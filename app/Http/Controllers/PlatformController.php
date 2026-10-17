<?php

namespace App\Http\Controllers;

use App\Models\AccountSetupInvitation;
use App\Models\Club;
use App\Models\PlatformActivity;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlatformController extends Controller
{
    public function dashboard(): View
    {
        $statistics = ['clubs' => Club::count(), 'active' => Club::where('status', 'active')->count(), 'suspended' => Club::where('status', 'suspended')->count(), 'inactive' => Club::where('status', 'inactive')->count(), 'users' => User::count()];
        $start = now()->startOfMonth()->subMonths(11);
        $months = collect(range(0, 11))->map(fn ($offset) => $start->copy()->addMonths($offset));
        $expression = DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')";
        $counts = Club::whereBetween('created_at', [$start, now()->endOfMonth()])->selectRaw($expression.' as registration_month, COUNT(*) as total')->groupBy('registration_month')->pluck('total', 'registration_month');
        $chart = $months->map(fn ($month) => ['label' => $month->format('M'), 'month' => $month->format('M Y'), 'count' => (int) ($counts[$month->format('Y-m')] ?? 0)]);

        return view('admin.dashboard', ['statistics' => $statistics, 'chart' => $chart, 'recentClubs' => Club::withCount('memberships')->latest()->orderByDesc('id')->limit(5)->get(), 'activities' => PlatformActivity::with(['actor', 'club'])->latest()->orderByDesc('id')->limit(6)->get()]);
    }

    public function users(Request $request): View
    {
        $filters = $request->validate(['search' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(['active', 'invited', 'suspended'])], 'role' => ['nullable', Rule::in(['system_admin', 'ordinary'])]]);
        $users = User::query()->withCount('memberships')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('account_status', $status))
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('is_system_admin', $role === 'system_admin'))->latest()->orderByDesc('id')->paginate(15)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function user(User $user): View
    {
        return view('admin.users.show', ['account' => $user, 'memberships' => $user->memberships()->with('club')->get(), 'activities' => PlatformActivity::where('subject_type', 'user')->where('subject_id', $user->id)->with('actor')->latest()->orderByDesc('id')->paginate(12)]);
    }

    public function userStatus(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['suspend', 'reactivate'])], 'reason' => ['required_if:action,suspend', 'nullable', 'string', 'max:1000'], 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($request, $user, $data): void {
            $administrators = User::where('is_system_admin', true)->orderBy('id')->lockForUpdate()->get();
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($data['action'] === 'suspend') {
                if ($user->id === $request->user()->id || ($user->is_system_admin && $user->account_status === 'active' && $administrators->where('account_status', 'active')->count() <= 1)) {
                    throw ValidationException::withMessages(['action' => 'You cannot suspend your own account or the last active System Administrator.']);
                }
                if ($user->account_status === 'suspended') {
                    return;
                }
                $user->suspended_from_status = $user->account_status;
                $user->account_status = 'suspended';
                $user->suspension_reason = $data['reason'];
                $user->suspended_by = $request->user()->id;
                $user->suspended_at = now();
                $user->remember_token = Str::random(60);
                AccountSetupInvitation::where('user_id', $user->id)->whereNull('used_at')->update(['expires_at' => now()]);
                DB::table('password_reset_tokens')->where('email', $user->phone)->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            } else {
                if ($user->account_status !== 'suspended') {
                    return;
                }
                $user->account_status = $user->suspended_from_status === 'invited' ? 'invited' : 'active';
                $user->suspended_from_status = null;
            }
            $user->save();
            PlatformActivity::record($request->user(), $data['action'] === 'suspend' ? 'account.suspended' : 'account.reactivated', $user, null, ['status' => $user->account_status]);
        });

        return back()->with('status', 'Account status updated. Club membership and financial records are unchanged.');
    }

    public function reissueSetup(Request $request, User $user): RedirectResponse
    {
        $request->validate(['confirmed' => 'required|accepted']);
        $url = DB::transaction(function () use ($request, $user): string {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->account_status !== 'invited') {
                throw ValidationException::withMessages(['account' => 'Only an account awaiting setup can receive a setup invitation.']);
            }
            $url = AccountSetupInvitation::issue($user, $request->user());
            PlatformActivity::record($request->user(), 'account.setup_reissued', $user);

            return $url;
        });

        return back()->with('setup_url', $url)->with('status', 'A new setup link was issued. Previous links are invalid. Deliver this link privately to the verified account holder.');
    }

    public function activities(Request $request): View
    {
        $endDateRules = ['nullable', 'date_format:Y-m-d'];
        if ($request->filled('from')) {
            $endDateRules[] = 'after_or_equal:from';
        }
        $filters = $request->validate(['action' => 'nullable|string|max:80', 'from' => 'nullable|date_format:Y-m-d', 'to' => $endDateRules]);
        $activities = PlatformActivity::with(['actor', 'club'])->when($filters['action'] ?? null, fn ($query, $action) => $query->where('action', $action))->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))->latest()->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.activities', ['activities' => $activities, 'actions' => PlatformActivity::select('action')->distinct()->orderBy('action')->pluck('action')]);
    }

    public function settings(): View
    {
        return view('admin.settings', ['settings' => PlatformSetting::current()]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate(['platform_name' => 'required|string|max:100', 'currency' => ['required', Rule::in(['RWF', 'USD', 'EUR', 'GBP', 'MUR', 'KES', 'UGX', 'TZS'])], 'brand_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'brand_initials' => ['required', 'regex:/^[A-Za-z0-9]{1,3}$/']]);
        $data['brand_initials'] = strtoupper($data['brand_initials']);
        DB::transaction(function () use ($request, $data): void {
            $settings = PlatformSetting::firstOrNew(['id' => 1]);
            $settings->fill($data);
            $fields = array_keys($settings->getDirty());
            $settings->save();
            PlatformActivity::record($request->user(), 'platform.settings_updated', $settings, null, ['fields' => $fields, 'currency' => $settings->currency, 'brand_color' => $settings->brand_color]);
        });

        return back()->with('status', 'Platform display settings updated. Club financial policies are unchanged.');
    }

    public function profile(): View
    {
        return view('admin.profile');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $request->validate(['phone' => 'required|string|max:40', 'email' => 'nullable|string|max:255']);
        $request->merge(['phone' => Phone::normalize($request->input('phone')), 'email' => $request->filled('email') ? strtolower(trim($request->input('email'))) : null]);
        $user = $request->user();
        $data = $request->validate(['name' => 'required|string|max:150', 'phone' => ['required', Rule::unique('users', 'phone')->ignore($user->id)], 'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($user->id)], 'current_password' => 'required|current_password']);
        DB::transaction(function () use ($user, $data): void {
            $oldPhone = $user->phone;
            $user->fill(array_intersect_key($data, array_flip(['name', 'phone', 'email'])));
            $fields = array_keys($user->getDirty());
            $user->save();
            if ($oldPhone !== $user->phone) {
                DB::table('password_reset_tokens')->where('email', $oldPhone)->delete();
            }
            if ($fields !== []) {
                PlatformActivity::record($user, 'account.profile_updated', $user, null, ['fields' => $fields]);
            }
        });

        return back()->with('status', 'Your profile was updated.');
    }
}
