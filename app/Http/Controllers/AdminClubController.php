<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Support\ClubAdministration;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminClubController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended'])]]);
        $clubs = Club::query()->withCount('memberships')->with(['memberships' => fn ($query) => $query->whereJsonContains('roles', 'Club Administrator')->with('user')])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->latest()->orderByDesc('id')->paginate(15)->withQueryString();

        return view('admin.clubs.index', compact('clubs'));
    }

    public function create(): View
    {
        return view('admin.clubs.form', ['club' => new Club, 'editing' => false]);
    }

    public function store(Request $request, ClubAdministration $administration): RedirectResponse
    {
        $data = $this->validateClub($request, false);
        $result = $administration->create($request->user(), $data);
        $response = redirect()->route('admin.clubs.show', $result['club'])->with('status', 'Club created. Administrative onboarding is available; financial membership remains pending.');
        if ($result['setup_url']) {
            $response->with('setup_url', $result['setup_url']);
        }

        return $response;
    }

    public function show(Club $club): View
    {
        $club->loadCount('memberships');

        return view('admin.clubs.show', ['club' => $club, 'administrators' => $club->memberships()->whereJsonContains('roles', 'Club Administrator')->with('user')->get(), 'activities' => $club->activities()->with('actor')->latest()->orderByDesc('id')->paginate(12)]);
    }

    public function edit(Club $club): View
    {
        return view('admin.clubs.form', ['club' => $club, 'editing' => true]);
    }

    public function update(Request $request, Club $club, ClubAdministration $administration): RedirectResponse
    {
        $data = $this->validateClub($request, true);
        $administration->update($request->user(), $club, $data);

        return redirect()->route('admin.clubs.show', $club)->with('status', 'Club profile updated.');
    }

    public function status(Request $request, Club $club, ClubAdministration $administration): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive', 'suspended'])], 'confirmed' => ['required', 'accepted'], 'reason' => ['required_if:status,suspended', 'nullable', 'string', 'max:1000']]);
        $administration->changeStatus($request->user(), $club, $data['status'], $data['reason'] ?? null);

        return back()->with('status', 'Club status updated. Existing records have been retained.');
    }

    public function assign(Request $request, Club $club, ClubAdministration $administration): RedirectResponse
    {
        $data = $request->validate(['administrator_phone' => 'required|string|max:40', 'replace_administrators' => 'nullable|boolean', 'confirmed' => 'required|accepted']);
        $data['administrator_phone'] = Phone::normalize($data['administrator_phone']);
        DB::transaction(function () use ($request, $club, $administration, $data): void {
            $club = Club::whereKey($club->id)->lockForUpdate()->firstOrFail();
            $administrator = $administration->existingAdministrator($data);
            $administration->assign($request->user(), $club, $administrator, $request->boolean('replace_administrators'));
        });

        return back()->with('status', 'Administrator assignment updated. Financial membership status is unchanged.');
    }

    private function validateClub(Request $request, bool $editing): array
    {
        $request->validate(['contact_phone' => 'nullable|string|max:40', 'administrator_phone' => 'nullable|string|max:40', 'administrator_email' => 'nullable|string|max:255']);
        if ($request->filled('contact_phone')) {
            $request->merge(['contact_phone' => Phone::normalize($request->input('contact_phone'))]);
        }
        if ($request->filled('administrator_phone')) {
            $request->merge(['administrator_phone' => Phone::normalize($request->input('administrator_phone'))]);
        }
        if ($request->filled('administrator_email')) {
            $request->merge(['administrator_email' => strtolower(trim($request->input('administrator_email')))]);
        }
        $rules = ['name' => 'required|string|max:150', 'description' => 'nullable|string|max:3000', 'contact_phone' => 'nullable|string|max:16'];
        if (! $editing) {
            $request->merge(['administrator_mode' => $request->input('administrator_mode', 'existing')]);
            $rules += ['status' => ['nullable', Rule::in(['active', 'inactive'])], 'administrator_mode' => ['required', Rule::in(['existing', 'new'])], 'administrator_phone' => [Rule::requiredIf($request->input('administrator_mode') === 'new' || ! $request->filled('administrator_id')), 'nullable', 'string', 'max:16'], 'administrator_id' => 'nullable|integer|exists:users,id', 'administrator_name' => 'required_if:administrator_mode,new|nullable|string|max:150', 'administrator_email' => 'nullable|email|max:255'];
        }

        return $request->validate($rules);
    }
}
