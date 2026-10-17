<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubPaymentDestination;
use App\Models\PlatformActivity;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClubSettingsController extends Controller
{
    public function edit(Club $club): View
    {
        Gate::authorize('administer', $club);

        return view('clubs.settings', ['club' => $club, 'destinations' => $club->paymentDestinations()->orderByDesc('is_active')->latest()->get()]);
    }

    public function update(Request $request, Club $club): RedirectResponse
    {
        Gate::authorize('administer', $club);
        $request->validate(['contact_phone' => 'nullable|string|max:40', 'contact_email' => 'nullable|string|max:255']);
        $request->merge(['contact_phone' => $request->filled('contact_phone') ? Phone::normalize($request->input('contact_phone')) : null, 'contact_email' => $request->filled('contact_email') ? strtolower(trim($request->input('contact_email'))) : null]);
        $data = $request->validate(['name' => 'required|string|max:150', 'description' => 'nullable|string|max:3000', 'contact_phone' => 'nullable|string|max:16', 'contact_email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:500']);
        DB::transaction(function () use ($request, $club, $data): void {
            $club->fill($data);
            $fields = array_keys($club->getDirty());
            $club->save();
            PlatformActivity::record($request->user(), 'club.settings_updated', $club, $club, ['fields' => $fields]);
        });

        return back()->with('status', 'Club profile updated. Approved financial policies are unchanged.');
    }

    public function rotateCode(Request $request, Club $club): RedirectResponse
    {
        Gate::authorize('administer', $club);
        $request->validate(['current_password' => 'required|current_password', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($request, $club): void {
            $club->update(['join_code' => Str::random(40)]);
            PlatformActivity::record($request->user(), 'club.join_code_rotated', $club, $club, ['fields' => ['join_code']]);
        });

        return back()->with('status', 'Private joining code regenerated. Previous joining links are invalid; individual invitations remain independent.');
    }

    public function payment(Request $request, Club $club, ?ClubPaymentDestination $destination = null): RedirectResponse
    {
        Gate::authorize('administer', $club);
        if ($destination) {
            abort_unless($destination->club_id === $club->id, 404);
        }
        $request->validate(['type' => ['required', Rule::in(['bank', 'mobile_money'])], 'mobile_number' => 'nullable|string|max:40']);
        if ($request->input('type') === 'mobile_money' && $request->filled('mobile_number')) {
            $request->merge(['mobile_number' => Phone::normalize($request->input('mobile_number'))]);
        }
        $data = $request->validate(['type' => ['required', Rule::in(['bank', 'mobile_money'])], 'label' => 'required|string|max:100', 'bank_name' => 'required_if:type,bank|nullable|string|max:150', 'account_name' => 'required_if:type,bank|nullable|string|max:150', 'account_number' => ['required_if:type,bank', 'nullable', 'string', 'regex:/^[A-Za-z0-9 -]{4,40}$/'], 'provider' => 'required_if:type,mobile_money|nullable|string|max:80', 'mobile_number' => 'required_if:type,mobile_money|nullable|string|max:16', 'account_holder' => 'required_if:type,mobile_money|nullable|string|max:150', 'is_active' => 'required|boolean']);
        $fields = $data['type'] === 'bank' ? ['provider', 'mobile_number', 'account_holder'] : ['bank_name', 'account_name', 'account_number'];
        foreach ($fields as $field) {
            $data[$field] = null;
        }
        DB::transaction(function () use ($request, $club, $destination, $data): void {
            $destination ??= new ClubPaymentDestination(['club_id' => $club->id, 'created_by' => $request->user()->id]);
            $new = ! $destination->exists;
            $destination->fill($data);
            $destination->updated_by = $request->user()->id;
            $changed = array_keys($destination->getDirty());
            $destination->save();
            PlatformActivity::record($request->user(), $new ? 'club.payment_destination_created' : 'club.payment_destination_updated', $destination, $club, ['fields' => $changed]);
        });

        return back()->with('status', 'Payment destination saved. No transaction has been processed.');
    }

    public function instructions(Club $club): View
    {
        abort_unless(Gate::allows('view', $club) || Gate::allows('administer', $club), 403);

        return view('clubs.payments', ['club' => $club, 'destinations' => $club->paymentDestinations()->where('is_active', true)->get()]);
    }
}
