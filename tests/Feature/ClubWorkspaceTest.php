<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubInvitation;
use App\Models\ClubJoinRequest;
use App\Models\ClubMembership;
use App\Models\ClubNotification;
use App\Models\ClubPaymentDestination;
use App\Models\ClubPolicyVersion;
use App\Models\FinancialRuleProposal;
use App\Models\PlatformActivity;
use App\Models\User;
use App\Support\ClubMembershipWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ClubWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function membership(User $user, Club $club, array $extra = []): ClubMembership
    {
        return $club->memberships()->create(array_merge(['user_id' => $user->id, 'status' => 'pending', 'admission_status' => 'approved', 'roles' => ['Member'], 'joined_at' => now()], $extra));
    }

    private function administrator(Club $club, bool $pending = true): User
    {
        $user = User::factory()->create();
        $this->membership($user, $club, ['roles' => ['Club Administrator', 'Member'], 'status' => $pending ? 'pending' : 'active', 'has_onboarding_access' => $pending]);

        return $user;
    }

    private function memberData(array $extra = []): array
    {
        $password = 'Aw9!'.Str::random(24);

        return array_merge(['mode' => 'new', 'name' => 'New Club Member', 'phone' => '+250 788 123 999', 'email' => null, 'password' => $password, 'password_confirmation' => $password], $extra);
    }

    public function test_club_dashboard_has_only_real_club_statistics_and_scoped_activities(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $active = User::factory()->create();
        $this->membership($active, $club, ['status' => 'active']);
        $applicant = User::factory()->create();
        app(ClubMembershipWorkflow::class)->submit($applicant, $club);
        $other = Club::factory()->create(['name' => 'UNRELATED CLUB']);
        $this->administrator($other);
        PlatformActivity::record($admin, 'club.unrelated_marker', $other, $other);
        $response = $this->actingAs($admin)->get(route('clubs.admin.dashboard', $club))->assertOk()->assertSee($club->name)->assertDontSee($other->name)->assertDontSee('Unrelated Marker');
        $this->assertSame(['total' => 3, 'active' => 1, 'pending' => 1, 'requests' => 1], $response->viewData('statistics'));
        $this->get('/dashboard')->assertRedirect(route('clubs.onboarding', $club));
        $this->get(route('clubs.show', $club))->assertForbidden();
    }

    public function test_admin_can_register_new_account_with_optional_email_and_required_password_change(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $data = $this->memberData(['is_system_admin' => true, 'roles' => ['President'], 'status' => 'active', 'has_onboarding_access' => true]);
        $response = $this->actingAs($admin)->post(route('clubs.members.store', $club), $data)->assertSessionHasNoErrors()->assertRedirect();
        $user = User::where('phone', '+250788123999')->sole();
        $membership = $club->memberships()->where('user_id', $user->id)->sole();
        $this->assertNull($user->email);
        $this->assertTrue($user->must_change_password);
        $this->assertFalse($user->is_system_admin);
        $this->assertTrue(Hash::check($data['password'], $user->password));
        $this->assertSame('pending', $membership->status);
        $this->assertSame('approved', $membership->admission_status);
        $this->assertSame(['Member'], $membership->roles);
        $this->assertFalse($membership->has_onboarding_access);
        $this->assertNull($membership->activated_at);
        $response->assertSessionMissing('password');
        $response->assertSessionMissing('_old_input.password');
        $this->post('/logout');
        $this->post('/login', ['login' => $user->phone, 'password' => $data['password']])->assertRedirect('/change-password');
        $this->get('/dashboard')->assertRedirect('/change-password');
        $new = 'Bx8!'.Str::random(24);
        $this->post('/change-password', ['current_password' => $data['password'], 'password' => $new, 'password_confirmation' => $new])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertFalse($user->fresh()->must_change_password);
        $this->get(route('clubs.show', $club))->assertForbidden();
        $this->assertDatabaseHas('platform_activities', ['action' => 'club.member_registered', 'subject_id' => $user->id, 'club_id' => $club->id]);
    }

    public function test_duplicate_phone_or_email_never_changes_existing_accounts_or_passwords(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $existing = User::factory()->create(['phone' => '+250788123999', 'email' => 'existing@example.com']);
        $hash = $existing->password;
        $this->actingAs($admin)->post(route('clubs.members.store', $club), $this->memberData())->assertSessionHasErrors('phone');
        $this->post(route('clubs.members.store', $club), $this->memberData(['phone' => '+250788000999', 'email' => 'EXISTING@example.com']))->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('users', 2);
        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertFalse($existing->fresh()->must_change_password);
        $this->assertDatabaseCount('club_memberships', 1);
    }

    public function test_validation_does_not_flash_temporary_passwords(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $data = $this->memberData(['password_confirmation' => 'mismatch']);
        $this->actingAs($admin)->post(route('clubs.members.store', $club), $data)->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_user_invitation_is_phone_bound_single_use_and_never_overwrites_password(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $user = User::factory()->create();
        $hash = $user->password;
        $this->actingAs($admin)->post(route('clubs.members.store', $club), ['mode' => 'existing', 'phone' => $user->phone])->assertSessionHas('invitation_url');
        $invitation = ClubInvitation::sole();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('club_memberships', 1);
        $this->assertSame($hash, $user->fresh()->password);
        $wrong = User::factory()->create();
        $this->actingAs($wrong)->post(route('invitations.accept', $invitation))->assertNotFound();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Accept invitation');
        $this->post(route('invitations.accept', $invitation))->assertRedirect(route('requests.mine'));
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame($hash, $user->fresh()->password);
        $membership = $club->memberships()->where('user_id', $user->id)->sole();
        $this->assertSame('pending', $membership->status);
        $this->assertSame('pending_review', $membership->admission_status);
        $this->post(route('invitations.accept', $invitation))->assertNotFound();
        $this->get(route('clubs.show', $club))->assertForbidden();
    }

    public function test_expired_and_suspended_club_invitations_cannot_be_accepted(): void
    {
        $club = Club::factory()->create();
        $user = User::factory()->create();
        $invitation = $club->invitations()->create(['phone' => $user->phone, 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->subMinute()]);
        $this->actingAs($user)->post(route('invitations.accept', $invitation))->assertNotFound();
        $invitation->update(['expires_at' => now()->addDay()]);
        $club->update(['status' => 'suspended']);
        $this->post(route('invitations.accept', $invitation))->assertNotFound();
        $this->assertDatabaseCount('club_memberships', 0);
    }

    public function test_valid_join_code_submits_idempotent_pending_requests_with_history_and_notices(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 2; $i++) {
            $this->post('/join', ['code' => $club->join_code])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        }
        $request = ClubJoinRequest::sole();
        $this->assertSame('pending', $request->status);
        $this->assertSame(1, $request->history()->count());
        $this->assertDatabaseCount('club_memberships', 2);
        $this->assertSame('pending_review', $club->memberships()->where('user_id', $user->id)->sole()->admission_status);
        $this->assertDatabaseHas('club_notifications', ['user_id' => $admin->id, 'club_id' => $club->id, 'kind' => 'join_request']);
        $this->get('/my-requests')->assertOk()->assertSee($club->name);
        $this->get(route('clubs.members', $club))->assertForbidden();
    }

    public function test_approval_keeps_financial_membership_pending_and_records_reviewer(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $user = User::factory()->create();
        $request = app(ClubMembershipWorkflow::class)->submit($user, $club);
        $this->actingAs($admin)->post(route('clubs.requests.review', [$club, $request]), ['decision' => 'approved', 'confirmed' => 1, 'status' => 'active'])->assertSessionHasNoErrors();
        $membership = $club->memberships()->where('user_id', $user->id)->sole();
        $this->assertSame('approved', $membership->admission_status);
        $this->assertSame('pending', $membership->status);
        $this->assertNull($membership->activated_at);
        $this->assertSame($admin->id, $request->fresh()->reviewed_by);
        $this->assertCount(2, $request->history()->get());
        $this->actingAs($user)->get(route('clubs.show', $club))->assertForbidden();
        $this->get('/my-requests')->assertSee('Your admission is approved');
    }

    public function test_rejection_requires_reason_preserves_history_and_supports_reapplication(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $user = User::factory()->create();
        $request = app(ClubMembershipWorkflow::class)->submit($user, $club);
        $this->actingAs($admin)->post(route('clubs.requests.review', [$club, $request]), ['decision' => 'rejected', 'confirmed' => 1])->assertSessionHasErrors('reason');
        $this->post(route('clubs.requests.review', [$club, $request]), ['decision' => 'rejected', 'reason' => 'Please provide the requested membership details.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $request->fresh()->status);
        $this->actingAs($user)->get('/my-requests')->assertSee('Please provide the requested membership details.');
        $this->post('/join', ['code' => $club->join_code])->assertSessionHasNoErrors();
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertCount(3, $request->history()->get());
        $this->assertDatabaseCount('club_join_requests', 1);
    }

    public function test_admission_decision_does_not_change_on_membership_suspension(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $user = User::factory()->create();
        $request = app(ClubMembershipWorkflow::class)->submit($user, $club);
        $membership = $club->memberships()->where('user_id', $user->id)->sole();
        $this->actingAs($admin)->post(route('clubs.requests.review', [$club, $request]), ['decision' => 'approved', 'confirmed' => 1]);
        $this->patch(route('clubs.members.update', [$club, $membership]), ['roles' => ['Member'], 'status' => 'suspended'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame('approved', $membership->fresh()->admission_status);
        $this->assertSame('suspended', $membership->fresh()->status);
    }

    public function test_club_owned_pages_and_writes_reject_unrelated_clubs_and_record_ids(): void
    {
        $one = Club::factory()->create();
        $two = Club::factory()->create();
        $admin = $this->administrator($one);
        $other = User::factory()->create();
        $foreign = $this->membership($other, $two);
        $request = $two->joinRequests()->create(['user_id' => $other->id, 'status' => 'pending']);
        $destination = ClubPaymentDestination::factory()->create(['club_id' => $two->id]);
        $this->actingAs($admin);
        foreach (['clubs.admin.dashboard', 'clubs.members', 'clubs.members.create', 'clubs.requests', 'clubs.settings', 'clubs.activities', 'clubs.governance', 'clubs.payments'] as $route) {
            $this->get(route($route, $two))->assertForbidden();
        }
        $this->get(route('clubs.members.show', [$one, $foreign]))->assertNotFound();
        $this->patch(route('clubs.members.update', [$one, $foreign]), ['roles' => ['Member'], 'status' => 'suspended'])->assertNotFound();
        $this->post(route('clubs.requests.review', [$one, $request]), ['decision' => 'approved', 'confirmed' => 1])->assertNotFound();
        $this->patch(route('clubs.payments.update', [$one, $destination]), [])->assertNotFound();
        $this->post(route('clubs.members.store', $two), $this->memberData())->assertForbidden();
        $this->patch(route('clubs.settings.update', $two), ['name' => 'Hacked'])->assertForbidden();
        $this->assertSame('pending', $foreign->fresh()->status);
    }

    public function test_member_directory_search_filters_and_pagination_are_club_scoped(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        foreach (User::factory()->count(18)->create() as $user) {
            $this->membership($user, $club);
        }
        $other = Club::factory()->create();
        $outsider = User::factory()->create(['name' => 'HIDDEN OTHER MEMBER']);
        $this->membership($outsider, $other);
        $response = $this->actingAs($admin)->get(route('clubs.members', $club))->assertOk()->assertDontSee($outsider->name);
        $this->assertSame(19, $response->viewData('memberships')->total());
        $this->assertCount(15, $response->viewData('memberships'));
        $this->get(route('clubs.members', $club).'?search='.urlencode($admin->phone).'&role=Club+Administrator&status=pending')->assertViewHas('memberships', fn ($members) => $members->total() === 1);
    }

    public function test_role_changes_are_delegated_scoped_and_cannot_self_escalate(): void
    {
        $one = Club::factory()->create();
        $two = Club::factory()->create();
        $admin = $this->administrator($one);
        $user = User::factory()->create();
        $membership = $this->membership($user, $one);
        $other = $this->membership($user, $two);
        $own = $one->memberships()->where('user_id', $admin->id)->sole();
        $this->actingAs($admin)->patch(route('clubs.members.update', [$one, $membership]), ['roles' => ['Accountant'], 'status' => 'unchanged'])->assertForbidden();
        $this->patch(route('clubs.members.update', [$one, $membership]), ['roles' => ['Club Administrator'], 'status' => 'unchanged'])->assertForbidden();
        $this->patch(route('clubs.members.update', [$one, $own]), ['roles' => ['Club Administrator', 'President'], 'status' => 'unchanged'])->assertForbidden();
        $this->patch(route('clubs.members.update', [$one, $membership]), ['roles' => ['Committee Member'], 'status' => 'unchanged'])->assertSessionHasNoErrors();
        $this->assertSame(['Committee Member', 'Member'], $membership->fresh()->roles);
        $this->assertSame(['Member'], $other->fresh()->roles);
        $this->assertDatabaseHas('platform_activities', ['action' => 'club.roles_changed', 'club_id' => $one->id, 'subject_id' => $user->id]);
    }

    public function test_platform_can_delegate_sensitive_roles_without_granting_financial_membership(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $platform = User::factory()->create(['is_system_admin' => true]);
        $user = User::factory()->create();
        $membership = $this->membership($user, $club);
        $this->actingAs($admin)->patch(route('admin.clubs.governance.update', $club), ['delegated_roles' => ['President'], 'confirmed' => 1])->assertForbidden();
        $this->actingAs($platform)->patch(route('admin.clubs.governance.update', $club), ['delegated_roles' => ['Member', 'President', 'Accountant'], 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch(route('clubs.members.update', [$club, $membership]), ['roles' => ['President', 'Accountant'], 'status' => 'unchanged'])->assertSessionHasNoErrors();
        $this->assertTrue($membership->fresh()->has_governance_access);
        $this->assertSame('pending', $membership->fresh()->status);
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('clubs.governance', $club));
        $this->get(route('clubs.governance', $club))->assertOk();
        $this->get(route('clubs.show', $club))->assertForbidden();
        $this->get(route('clubs.members', $club))->assertForbidden();
    }

    public function test_payment_destinations_validate_details_are_encrypted_and_scoped(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $this->actingAs($admin);
        $this->post(route('clubs.payments.store', $club), ['type' => 'bank', 'label' => 'Main bank', 'is_active' => 1])->assertSessionHasErrors(['bank_name', 'account_name', 'account_number']);
        $data = ['type' => 'bank', 'label' => 'Main bank', 'bank_name' => 'Community Bank', 'account_name' => 'Club Account', 'account_number' => '000123456789', 'is_active' => 1];
        $this->post(route('clubs.payments.store', $club), $data)->assertSessionHasNoErrors();
        $destination = ClubPaymentDestination::sole();
        $this->assertSame($club->id, $destination->club_id);
        $this->assertSame('000123456789', $destination->account_number);
        $this->assertNotSame('000123456789', DB::table('club_payment_destinations')->value('account_number'));
        $this->assertStringNotContainsString('000123456789', PlatformActivity::latest('id')->first()->toJson());
        $this->patch(route('clubs.payments.update', [$club, $destination]), array_merge($data, ['is_active' => 0]))->assertSessionHasNoErrors();
        $this->assertFalse($destination->fresh()->is_active);
        $mobile = ['type' => 'mobile_money', 'label' => 'Mobile destination', 'provider' => 'MTN', 'mobile_number' => '00250 788 444 555', 'account_holder' => 'Club Holder', 'is_active' => 1];
        $this->post(route('clubs.payments.store', $club), $mobile)->assertSessionHasNoErrors();
        $this->assertSame('+250788444555', $club->paymentDestinations()->where('type', 'mobile_money')->sole()->mobile_number);
        $this->post(route('clubs.payments.store', $club), array_merge($mobile, ['mobile_number' => 'invalid']))->assertSessionHasErrors('phone');
    }

    public function test_only_active_members_or_club_administrators_can_read_active_payment_instructions(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $active = User::factory()->create();
        $this->membership($active, $club, ['status' => 'active']);
        $pending = User::factory()->create();
        $this->membership($pending, $club);
        $suspended = User::factory()->create();
        $this->membership($suspended, $club, ['status' => 'suspended']);
        ClubPaymentDestination::factory()->create(['club_id' => $club->id, 'label' => 'ACTIVE INSTRUCTION', 'account_number' => '12345678']);
        ClubPaymentDestination::factory()->create(['club_id' => $club->id, 'label' => 'HIDDEN INACTIVE', 'is_active' => false]);
        $this->actingAs($active)->get(route('clubs.payments', $club))->assertOk()->assertSee('12345678')->assertDontSee('HIDDEN INACTIVE');
        $this->post(route('clubs.payments.store', $club), [])->assertForbidden();
        $this->actingAs($pending)->get(route('clubs.payments', $club))->assertForbidden();
        $this->actingAs($suspended)->get(route('clubs.payments', $club))->assertForbidden();
        $this->actingAs($admin)->get(route('clubs.payments', $club))->assertOk();
    }

    public function test_profile_updates_cannot_change_status_role_delegation_or_approved_rules_and_code_rotation_is_secure(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $oldCode = $club->join_code;
        $this->actingAs($admin)->patch(route('clubs.settings.update', $club), ['name' => 'Updated Club', 'contact_phone' => '00250 788 555 999', 'contact_email' => 'CLUB@example.com', 'status' => 'suspended', 'delegated_roles' => ['Accountant'], 'join_code' => 'predictable', 'rules' => ['share_price' => '1']])->assertSessionHasNoErrors();
        $club->refresh();
        $this->assertSame('Updated Club', $club->name);
        $this->assertSame('+250788555999', $club->contact_phone);
        $this->assertSame('club@example.com', $club->contact_email);
        $this->assertSame('active', $club->status);
        $this->assertSame($oldCode, $club->join_code);
        $this->assertSame(['Member', 'Committee Member'], $club->assignableRoles());
        $this->assertDatabaseCount('club_policy_versions', 0);
        $this->post(route('clubs.settings.code', $club), ['current_password' => 'wrong', 'confirmed' => 1])->assertSessionHasErrors('current_password');
        $this->post(route('clubs.settings.code', $club), ['current_password' => 'password', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertNotSame($oldCode, $club->fresh()->join_code);
        $user = User::factory()->create();
        $this->actingAs($user)->post('/join', ['code' => $oldCode])->assertSessionHasErrors('code');
    }

    public function test_rules_require_independent_president_review_and_immutable_approved_versions(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $president = User::factory()->create();
        $this->membership($president, $club, ['roles' => ['President', 'Member'], 'has_governance_access' => true]);
        $this->actingAs($admin)->post(route('clubs.governance.propose', $club), ['base_version' => 0, 'rules' => ['share_price' => '1000.00', 'minimum_membership_shares' => 2], 'rationale' => 'Initial proposed membership rules.'])->assertSessionHasNoErrors();
        $proposal = FinancialRuleProposal::sole();
        $this->assertSame('submitted', $proposal->status);
        $this->assertDatabaseCount('club_policy_versions', 0);
        $this->post(route('clubs.governance.review', [$club, $proposal]), ['decision' => 'approved', 'confirmed' => 1])->assertForbidden();
        $this->actingAs($president)->post(route('clubs.governance.review', [$club, $proposal]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
        $version = ClubPolicyVersion::sole();
        $this->assertSame(1, $version->version);
        $this->assertSame('1000.00', $version->rules['share_price']);
        $this->assertSame($president->id, $version->approved_by);
        $this->assertSame('effective', $proposal->fresh()->status);
        $this->expectException(\LogicException::class);
        $version->update(['rules' => ['share_price' => '1']]);
    }

    public function test_same_person_admin_and_president_cannot_review_own_proposal(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club, false);
        $membership = $club->memberships()->sole();
        $membership->update(['roles' => ['Club Administrator', 'President', 'Member']]);
        $proposal = FinancialRuleProposal::factory()->create(['club_id' => $club->id, 'proposed_by' => $admin->id]);
        $this->actingAs($admin)->post(route('clubs.governance.review', [$club, $proposal]), ['decision' => 'approved', 'confirmed' => 1])->assertForbidden();
        $this->assertDatabaseCount('club_policy_versions', 0);
    }

    public function test_rejection_requires_reason_and_stale_approvals_cannot_overwrite_a_newer_policy(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $president = User::factory()->create();
        $this->membership($president, $club, ['status' => 'active', 'roles' => ['President', 'Member']]);
        $first = FinancialRuleProposal::factory()->create(['club_id' => $club->id, 'proposed_by' => $admin->id]);
        $second = FinancialRuleProposal::factory()->create(['club_id' => $club->id, 'proposed_by' => $admin->id]);
        $this->actingAs($president)->post(route('clubs.governance.review', [$club, $first]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->post(route('clubs.governance.review', [$club, $second]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasErrors('decision');
        $this->assertDatabaseCount('club_policy_versions', 1);
        $this->post(route('clubs.governance.review', [$club, $second]), ['decision' => 'rejected', 'confirmed' => 1])->assertSessionHasErrors('reason');
        $this->post(route('clubs.governance.review', [$club, $second]), ['decision' => 'rejected', 'reason' => 'Use the latest approved version as the baseline.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $second->fresh()->status);
    }

    public function test_platform_role_assignment_and_committee_designations_are_explicit_and_scoped(): void
    {
        $club = Club::factory()->create();
        $other = Club::factory()->create();
        $platform = User::factory()->create(['is_system_admin' => true]);
        $admin = $this->administrator($club);
        $user = User::factory()->create();
        $membership = $this->membership($user, $club);
        $outsider = User::factory()->create();
        $foreign = $this->membership($outsider, $other, ['roles' => ['Committee Member', 'Member']]);
        $this->actingAs($platform)->patch(route('admin.clubs.memberships.roles', [$club, $membership]), ['roles' => ['Committee Member', 'President'], 'confirmed' => 1, 'status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $membership->fresh()->status);
        $this->assertTrue($membership->fresh()->has_governance_access);
        $this->patch(route('admin.clubs.governance.update', $club), ['delegated_roles' => ['Member'], 'committee_approvers' => [$foreign->id], 'confirmed' => 1])->assertSessionHasErrors('committee_approvers.0');
        $this->patch(route('admin.clubs.governance.update', $club), ['delegated_roles' => ['Member'], 'committee_approvers' => [$membership->id], 'confirmed' => 1])->assertSessionHasErrors('committee_approvers');
        $this->assertFalse($membership->fresh()->is_committee_approver);
        $this->actingAs($admin)->get(route('clubs.governance', $club))->assertDontSee($outsider->name);
        $this->patch(route('admin.clubs.memberships.roles', [$club, $membership]), ['roles' => ['Accountant'], 'confirmed' => 1])->assertForbidden();
    }

    public function test_notifications_and_request_histories_never_show_other_users_records(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $one = User::factory()->create();
        $two = User::factory()->create();
        $first = app(ClubMembershipWorkflow::class)->submit($one, $club);
        $second = app(ClubMembershipWorkflow::class)->submit($two, $club);
        ClubNotification::send($club, $one->id, 'test', 'OWN PRIVATE NOTICE');
        ClubNotification::send($club, $two->id, 'test', 'OTHER USER PRIVATE NOTICE');
        $foreign = ClubNotification::where('user_id', $two->id)->first();
        $this->actingAs($one)->get('/notifications')->assertOk()->assertSee('OWN PRIVATE NOTICE')->assertDontSee('OTHER USER PRIVATE NOTICE');
        $this->patch(route('notifications.read', $foreign))->assertNotFound();
        $this->get('/my-requests')->assertViewHas('requests', fn ($requests) => $requests->count() === 1 && $requests->first()->id === $first->id);
    }

    public function test_suspended_club_or_membership_blocks_all_administrative_and_governance_access(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $membership = $club->memberships()->sole();
        $this->actingAs($admin);
        $membership->update(['status' => 'suspended']);
        foreach (['clubs.admin.dashboard', 'clubs.members', 'clubs.requests', 'clubs.settings', 'clubs.governance', 'clubs.activities', 'clubs.payments'] as $route) {
            $this->get(route($route, $club))->assertForbidden();
        }
        $membership->update(['status' => 'pending']);
        $club->update(['status' => 'suspended']);
        $this->get(route('clubs.admin.dashboard', $club))->assertForbidden();
        $this->get(route('clubs.settings', $club))->assertForbidden();
    }

    public function test_member_creation_rolls_back_account_and_membership_if_audit_fails(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $event = 'eloquent.creating: '.PlatformActivity::class;
        Event::listen($event, function (PlatformActivity $activity): void {
            if ($activity->action === 'club.member_registered') {
                throw new RuntimeException('Audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)->post(route('clubs.members.store', $club), $this->memberData());
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit failure', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('club_memberships', 1);
        $this->assertDatabaseCount('club_notifications', 0);
    }

    public function test_favicon_and_titles_are_present_on_auth_platform_and_club_pages(): void
    {
        $this->assertFileExists(public_path('favicon.svg'));
        $svg = file_get_contents(public_path('favicon.svg'));
        $this->assertStringContainsString('#17191D', $svg);
        $this->assertStringContainsString('#E98A2A', $svg);
        $this->get('/login')->assertOk()->assertSee('<title>Barihafi | Saving Club</title>', false)->assertSee(asset('favicon.svg'), false);
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $this->actingAs($admin)->get(route('clubs.members', $club))->assertOk()->assertSee('<title>Members | Barihafi</title>', false);
        $this->get(route('clubs.settings', $club))->assertSee('<title>Settings | Barihafi</title>', false);
        $this->get('/change-password')->assertSee(asset('favicon.svg'), false);
        $platform = User::factory()->create(['is_system_admin' => true]);
        $this->actingAs($platform)->get('/admin/dashboard')->assertSee('<title>Dashboard | Barihafi</title>', false);
    }

    public function test_all_club_workspace_views_render_and_can_export_isolated_browser_fixtures(): void
    {
        $club = Club::factory()->create(['name' => 'Review Club', 'description' => 'A club profile for isolated UI review.']);
        $admin = $this->administrator($club);
        $admin->name = 'Club Reviewer';
        $admin->save();
        $member = User::factory()->create(['name' => 'Review Member']);
        $membership = $this->membership($member, $club);
        $applicant = User::factory()->create(['name' => 'Review Applicant']);
        app(ClubMembershipWorkflow::class)->submit($applicant, $club);
        ClubPaymentDestination::factory()->create(['club_id' => $club->id]);
        $capture = (bool) getenv('BARIHAFI_CLUB_REVIEW_ARTIFACTS');
        if ($capture) {
            $this->withVite();
            URL::forceRootUrl('http://127.0.0.1:8130');
            File::ensureDirectoryExists(storage_path('app/phase3-review'));
        }
        $this->actingAs($admin);
        $pages = ['dashboard' => [route('clubs.admin.dashboard', $club), 'Club administration'], 'members' => [route('clubs.members', $club), 'Members'], 'member-create' => [route('clubs.members.create', $club), 'Add or invite a member'], 'member-show' => [route('clubs.members.show', [$club, $membership]), $member->name], 'requests' => [route('clubs.requests', $club), 'Join requests'], 'settings' => [route('clubs.settings', $club), 'Club settings'], 'governance' => [route('clubs.governance', $club), 'Financial Settings'], 'payments' => [route('clubs.payments', $club), 'Club payment instructions'], 'activities' => [route('clubs.activities', $club), 'Club administration history'], 'notifications' => [route('notifications.index'), 'Notifications']];
        foreach ($pages as $name => [$url,$heading]) {
            $response = $this->get($url)->assertOk()->assertSee($heading, false);
            if ($capture) {
                file_put_contents(storage_path('app/phase3-review/'.$name.'.html'), $response->getContent());
            }
        }
        $platform = User::factory()->create(['is_system_admin' => true]);
        $this->actingAs($platform)->get(route('admin.clubs.governance', $club))->assertOk()->assertSee('Delegated Club Administrator permissions');
        if ($capture) {
            file_put_contents(storage_path('app/phase3-review/routes.json'), json_encode(array_map(fn ($page) => parse_url($page[0], PHP_URL_PATH), $pages)));
        }
    }

    public function test_delegated_role_permissions_still_block_self_promotion_and_committee_flags_are_removed(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $club->delegated_roles = ['Member', 'Committee Member', 'President', 'Accountant'];
        $club->save();
        $own = $club->memberships()->sole();
        $this->actingAs($admin)->patch(route('clubs.members.update', [$club, $own]), ['roles' => ['Club Administrator', 'President', 'Member'], 'status' => 'unchanged'])->assertForbidden();
        $this->assertFalse($own->fresh()->has_governance_access);
        $user = User::factory()->create();
        $membership = $this->membership($user, $club, ['roles' => ['Committee Member', 'Member'], 'is_committee_approver' => true]);
        $this->patch(route('clubs.members.update', [$club, $membership]), ['roles' => ['Member'], 'status' => 'unchanged'])->assertSessionHasNoErrors();
        $this->assertFalse($membership->fresh()->is_committee_approver);
    }

    public function test_governance_review_is_scoped_and_only_admins_can_propose_while_approved_history_renders(): void
    {
        $club = Club::factory()->create();
        $other = Club::factory()->create();
        $admin = $this->administrator($club);
        $president = User::factory()->create();
        $this->membership($president, $club, ['roles' => ['President', 'Member'], 'has_governance_access' => true]);
        $proposal = FinancialRuleProposal::factory()->create(['club_id' => $club->id, 'proposed_by' => $admin->id]);
        $foreign = FinancialRuleProposal::factory()->create(['club_id' => $other->id]);
        $this->actingAs($president)->post(route('clubs.governance.propose', $club), ['base_version' => 0, 'rules' => ['share_price' => '1'], 'rationale' => 'Not authorised'])->assertForbidden();
        $this->post(route('clubs.governance.review', [$club, $foreign]), ['decision' => 'approved', 'confirmed' => 1])->assertNotFound();
        $this->post(route('clubs.governance.review', [$club, $proposal]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->get(route('clubs.governance', $club))->assertOk()->assertSee('Version 1')->assertSee('1000.00');
        $this->actingAs($admin)->get(route('clubs.financial.page', [$club, 'create']))->assertOk()->assertSee('Current: 1000.00');
        $this->post(route('clubs.governance.propose', $club), ['base_version' => 0, 'rules' => ['share_price' => '1'], 'rationale' => 'Stale form'])->assertSessionHasErrors('base_version');
        $this->assertDatabaseCount('club_policy_versions', 1);
    }

    public function test_admission_review_is_single_decision_and_suspended_users_cannot_rejoin_to_restore_access(): void
    {
        $club = Club::factory()->create();
        $admin = $this->administrator($club);
        $user = User::factory()->create();
        $request = app(ClubMembershipWorkflow::class)->submit($user, $club);
        $this->actingAs($admin)->post(route('clubs.requests.review', [$club, $request]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->post(route('clubs.requests.review', [$club, $request]), ['decision' => 'rejected', 'reason' => 'Too late', 'confirmed' => 1])->assertSessionHasErrors('decision');
        $this->assertCount(2, $request->history()->get());
        $membership = $club->memberships()->where('user_id', $user->id)->sole();
        $membership->update(['status' => 'suspended']);
        $this->actingAs($user)->post('/join', ['code' => $club->join_code])->assertRedirect('/dashboard');
        $this->assertSame('suspended', $membership->fresh()->status);
        $this->assertSame('approved', $request->fresh()->status);
    }
}
