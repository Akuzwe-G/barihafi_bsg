<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseOneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function membership(User $user, Club $club, string $status = 'active', array $roles = ['Member']): ClubMembership
    {
        return $club->memberships()->create(['user_id' => $user->id, 'status' => $status, 'roles' => $roles, 'joined_at' => now(), 'activated_at' => $status === 'active' ? now() : null]);
    }

    private function registration(Club $club, array $extra = []): array
    {
        return array_merge(['code' => $club->join_code, 'name' => 'New Member', 'phone' => '+230 5123 4567', 'email' => null, 'password' => 'a-safe-password-2026', 'password_confirmation' => 'a-safe-password-2026'], $extra);
    }

    public function test_phone_login_works_without_email_and_regenerates_session(): void
    {
        $user = User::factory()->create(['email' => null, 'phone' => '+23051234567']);
        $this->withSession(['before' => 'value']);
        $before = session()->getId();
        $this->post('/login', ['login' => '00230 5123 4567', 'password' => 'password', 'remember' => 1])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_email_login_is_case_normalized(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);
        $this->post('/login', ['login' => 'MEMBER@example.com', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_are_generic_and_rejected(): void
    {
        $user = User::factory()->create();
        foreach ([$user->phone, '+23050000000', 'not-a-phone'] as $login) {
            $response = $this->post('/login', ['login' => $login, 'password' => 'wrong']);
            $response->assertSessionHasErrors(['login' => 'The supplied credentials are invalid.']);
            $this->assertGuest();
        }
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => $user->phone, 'password' => 'wrong']);
        }
        $this->post('/login', ['login' => $user->phone, 'password' => 'password'])->assertSessionHasErrors(['login' => 'Too many attempts. Please try again in a minute.']);
        $this->assertGuest();
    }

    public function test_protected_routes_require_authentication(): void
    {
        $club = Club::factory()->create();
        foreach (['/dashboard', '/platform', '/clubs/'.$club->id, '/clubs/'.$club->id.'/members'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_single_club_redirect_and_member_dashboard(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->create();
        $this->membership($user, $club);
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('clubs.show', $club));
        $this->get(route('clubs.show', $club))->assertOk()->assertSee('No share transactions recorded yet.')->assertSee('Member workspace');
    }

    public function test_multiple_clubs_only_show_authorized_clubs(): void
    {
        $user = User::factory()->create();
        $one = Club::factory()->create();
        $two = Club::factory()->create();
        $secret = Club::factory()->create(['name' => 'UNRELATED PRIVATE CLUB']);
        $this->membership($user, $one);
        $this->membership($user, $two);
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee($one->name)->assertSee($two->name)->assertDontSee($secret->name);
    }

    public function test_url_manipulation_does_not_grant_club_access(): void
    {
        $user = User::factory()->create();
        $one = Club::factory()->create();
        $other = Club::factory()->create();
        $this->membership($user, $one);
        $this->actingAs($user)->get(route('clubs.show', $other))->assertForbidden()->assertDontSee($other->name);
        $this->get(route('clubs.members', $other))->assertForbidden();
    }

    public function test_pending_suspended_and_revoked_memberships_are_denied(): void
    {
        foreach (['pending', 'suspended', 'revoked'] as $status) {
            $user = User::factory()->create();
            $club = Club::factory()->create();
            $this->membership($user, $club, $status, ['Club Administrator', 'Member']);
            $this->actingAs($user)->get(route('clubs.show', $club))->assertForbidden();
            $this->get(route('clubs.members', $club))->assertForbidden();
        }
    }

    public function test_club_deactivation_immediately_removes_access(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->create(['is_active' => false]);
        $this->membership($user, $club);
        $this->actingAs($user)->get(route('clubs.show', $club))->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertSee('No active club access yet');
    }

    public function test_system_administrator_creates_club_with_pending_initial_administrator(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $member = User::factory()->create();
        $this->actingAs($admin)->post(route('platform.clubs.store'), ['name' => 'Barihafi Test Club', 'administrator_phone' => $member->phone])->assertSessionHasNoErrors()->assertRedirect();
        $club = Club::where('name', 'Barihafi Test Club')->firstOrFail();
        $membership = $club->memberships()->sole();
        $this->assertSame('pending', $membership->status);
        $this->assertNull($membership->activated_at);
        $this->assertContains('Club Administrator', $membership->roles);
        $this->get('/dashboard')->assertRedirect('/platform');
        $this->get('/platform')->assertOk()->assertSee('Barihafi Test Club');
        $this->get(route('clubs.show', $club))->assertForbidden();
    }

    public function test_ordinary_users_cannot_manage_platform_or_self_promote(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/platform')->assertForbidden();
        $this->post(route('platform.clubs.store'), ['name' => 'Forbidden', 'administrator_phone' => $user->phone, 'is_system_admin' => true])->assertForbidden();
        $this->assertFalse($user->fresh()->is_system_admin);
    }

    public function test_roles_are_isolated_between_clubs_and_leaders_keep_member_tools(): void
    {
        $user = User::factory()->create();
        $one = Club::factory()->create();
        $two = Club::factory()->create();
        $this->membership($user, $one, 'active', ['Club Administrator', 'President', 'Accountant', 'Committee Member', 'Member']);
        $this->membership($user, $two);
        $this->actingAs($user)->get(route('clubs.members', $one))->assertOk();
        $this->get(route('clubs.members', $two))->assertForbidden();
        $this->get(route('clubs.show', $one))->assertOk()->assertSee('Leadership workspace')->assertSee('Accounting workspace')->assertSee('Committee workspace')->assertSee('Member workspace');
        $this->get(route('clubs.show', $two))->assertDontSee('Accounting workspace');
    }

    public function test_registration_creates_pending_request_and_ignores_admin_input(): void
    {
        $club = Club::factory()->create();
        $this->post('/join', $this->registration($club, ['is_system_admin' => true, 'status' => 'active', 'roles' => ['Club Administrator']]))->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $user = User::where('phone', '+23051234567')->sole();
        $this->assertFalse($user->is_system_admin);
        $this->assertNull($user->email);
        $this->assertTrue(Hash::check('a-safe-password-2026', $user->password));
        $membership = $user->memberships()->sole();
        $this->assertSame('pending', $membership->status);
        $this->assertSame(['Member'], $membership->roles);
        $this->assertFalse($membership->has_onboarding_access);
        $this->assertNull($membership->activated_at);
        $this->assertDatabaseHas('club_join_requests', ['user_id' => $user->id, 'club_id' => $club->id, 'status' => 'pending']);
        $this->get('/dashboard')->assertOk()->assertSee('Financial verification is not available');
        $this->get(route('clubs.show', $club))->assertForbidden();
    }

    public function test_invalid_or_inactive_join_codes_do_not_create_accounts_or_leak_clubs(): void
    {
        $club = Club::factory()->create(['is_active' => false]);
        foreach ([$club->join_code, 'invalid'] as $code) {
            $this->post('/join', $this->registration($club, ['code' => $code]))->assertSessionHasErrors('code');
        }
        $this->assertDatabaseCount('users', 0);
        $this->get('/join')->assertOk()->assertDontSee($club->name);
    }

    public function test_required_phone_and_normalized_phone_and_email_uniqueness(): void
    {
        $club = Club::factory()->create();
        User::factory()->create(['phone' => '+23051234567', 'email' => 'used@example.com']);
        $this->post('/join', $this->registration($club))->assertSessionHasErrors('phone');
        $this->post('/join', $this->registration($club, ['phone' => '+23059999999', 'email' => 'USED@example.com']))->assertSessionHasErrors('email');
        $this->post('/join', $this->registration($club, ['phone' => '']))->assertSessionHasErrors('phone');
    }

    public function test_existing_user_can_request_another_club_without_duplicate_membership(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 2; $i++) {
            $this->post('/join', ['code' => $club->join_code])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('club_memberships', 1);
        $this->assertDatabaseCount('club_join_requests', 1);
    }

    public function test_invitation_is_phone_bound_single_use_and_expires(): void
    {
        $club = Club::factory()->create();
        $token = Str::random(64);
        $invite = $club->invitations()->create(['phone' => '+23051234567', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $wrong = User::factory()->create();
        $this->actingAs($wrong)->post('/join', ['invitation' => $token])->assertSessionHasErrors('code');
        $this->assertNull($invite->fresh()->accepted_at);
        $this->post('/logout');
        $this->post('/join', $this->registration($club, ['code' => null, 'invitation' => $token]))->assertSessionHasNoErrors();
        $this->assertNotNull($invite->fresh()->accepted_at);
        $this->post('/join', ['invitation' => $token])->assertSessionHasErrors('code');
        $expired = Str::random(64);
        $club->invitations()->create(['phone' => auth()->user()->phone, 'token_hash' => hash('sha256', $expired), 'expires_at' => now()->subMinute()]);
        $this->post('/join', ['invitation' => $expired])->assertSessionHasErrors('code');
    }

    public function test_club_administrator_can_issue_invitation_but_member_cannot(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->create();
        $this->membership($user, $club, 'active', ['Club Administrator', 'Member']);
        $this->actingAs($user)->post(route('clubs.invite', $club), ['phone' => '+23059999999'])->assertSessionHas('invitation_url');
        $this->assertDatabaseCount('club_invitations', 1);
        $other = User::factory()->create();
        $this->membership($other, $club);
        $this->actingAs($other)->post(route('clubs.invite', $club), ['phone' => '+23058888888'])->assertForbidden();
    }

    public function test_club_administrator_cannot_update_other_club_membership_or_activate_anyone(): void
    {
        $user = User::factory()->create();
        $one = Club::factory()->create();
        $two = Club::factory()->create();
        $this->membership($user, $one, 'active', ['Club Administrator', 'Member']);
        $target = User::factory()->create();
        $foreign = $this->membership($target, $two, 'pending');
        $own = $this->membership($target, $one, 'pending');
        $this->actingAs($user)->patch(route('clubs.members.update', [$one, $foreign]), ['roles' => ['President'], 'status' => 'suspended'])->assertNotFound();
        $this->patch(route('clubs.members.update', [$one, $own]), ['roles' => ['Member'], 'status' => 'active'])->assertSessionHasErrors('status');
        $this->assertSame('pending', $own->fresh()->status);
        $this->patch(route('clubs.members.update', [$one, $own]), ['roles' => ['Committee Member'], 'status' => 'suspended'])->assertSessionHasNoErrors();
        $this->assertSame('suspended', $own->fresh()->status);
        $this->assertContains('Member', $own->fresh()->roles);
    }

    public function test_platform_can_deactivate_and_assign_without_activating_membership(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $user = User::factory()->create();
        $club = Club::factory()->create();
        $this->actingAs($admin)->post(route('platform.clubs.assign', $club), ['administrator_phone' => $user->phone, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('pending', $club->memberships()->sole()->status);
        $this->patch(route('platform.clubs.update', $club), ['name' => 'Renamed', 'is_active' => false, 'confirmed' => 1, 'reason' => 'Temporary administrative suspension'])->assertSessionHasNoErrors();
        $this->assertFalse($club->fresh()->is_active);
    }

    public function test_logout_ends_authentication(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_controlled_account_command_hides_password_and_creates_administrator(): void
    {
        $this->artisan('barihafi:create-account', ['--system-admin' => true])->expectsQuestion('Full name', 'Platform Operator')->expectsQuestion('International phone (+ country code)', '+23058888888')->expectsQuestion('Email (optional)', null)->expectsQuestion('Password (at least 12 characters)', 'a-safe-password-2026')->expectsQuestion('Confirm password', 'a-safe-password-2026')->assertSuccessful();
        $user = User::where('phone', '+23058888888')->sole();
        $this->assertTrue($user->is_system_admin);
        $this->assertTrue(Hash::check('a-safe-password-2026', $user->password));
        $this->assertDatabaseCount('club_memberships', 0);
    }

    public function test_recovery_command_requires_explicit_identity_verification(): void
    {
        $user = User::factory()->create();
        $this->artisan('barihafi:issue-recovery', ['phone' => $user->phone])->expectsConfirmation('Have you independently verified this account holder and arranged secure private delivery?', 'no')->assertFailed();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->artisan('barihafi:issue-recovery', ['phone' => $user->phone])->expectsConfirmation('Have you independently verified this account holder and arranged secure private delivery?', 'yes')->assertSuccessful();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->phone]);
    }

    public function test_recovery_tokens_are_single_use_and_revoke_database_sessions(): void
    {
        $user = User::factory()->create(['email' => null]);
        $token = Str::random(64);
        DB::table('password_reset_tokens')->insert(['email' => $user->phone, 'token' => Hash::make($token), 'created_at' => now()]);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $data = ['phone' => $user->phone, 'token' => $token, 'password' => 'a-new-safe-password', 'password_confirmation' => 'a-new-safe-password'];
        $this->get(route('password.reset', ['token' => $token, 'phone' => $user->phone]))->assertOk();
        $this->post('/reset-password', $data)->assertSessionHasNoErrors()->assertRedirect('/login');
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->post('/reset-password', $data)->assertSessionHasErrors('token');
    }

    public function test_invalid_and_expired_recovery_links_fail(): void
    {
        $user = User::factory()->create();
        $token = Str::random(64);
        DB::table('password_reset_tokens')->insert(['email' => $user->phone, 'token' => Hash::make($token), 'created_at' => now()->subHours(2)]);
        foreach ([$token, 'wrong'] as $value) {
            $this->post('/reset-password', ['phone' => $user->phone, 'token' => $value, 'password' => 'a-new-safe-password', 'password_confirmation' => 'a-new-safe-password'])->assertSessionHasErrors('token');
        }
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_unique_membership_constraint_is_enforced(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->create();
        $this->membership($user, $club);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->membership($user, $club);
    }

    public function test_malformed_registration_fields_return_validation_errors(): void
    {
        $club = Club::factory()->create();
        $this->post('/join', $this->registration($club, ['phone' => ['unexpected']]))->assertSessionHasErrors('phone');
        $this->post('/join', $this->registration($club, ['email' => ['unexpected']]))->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_revoked_user_cannot_restore_access_using_join_code(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->create();
        $membership = $this->membership($user, $club, 'revoked');
        $this->actingAs($user)->post('/join', ['code' => $club->join_code])->assertRedirect('/dashboard');
        $this->assertSame('revoked', $membership->fresh()->status);
        $this->get(route('clubs.show', $club))->assertForbidden();
    }

    public function test_public_authentication_views_render(): void
    {
        foreach (['/login', '/join', '/forgot-password'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_role_updates_can_preserve_status_without_activating_pending_members(): void
    {
        $admin = User::factory()->create();
        $club = Club::factory()->create();
        $club->delegated_roles = ['Member', 'President'];
        $club->save();
        $this->membership($admin, $club, 'active', ['Club Administrator', 'Member']);
        foreach (['active', 'pending', 'suspended', 'revoked'] as $status) {
            $user = User::factory()->create();
            $membership = $this->membership($user, $club, $status);
            $this->actingAs($admin)->patch(route('clubs.members.update', [$club, $membership]), ['roles' => ['President'], 'status' => 'unchanged'])->assertSessionHasNoErrors();
            $this->assertSame($status, $membership->fresh()->status);
            $this->assertSame(['President', 'Member'], $membership->fresh()->roles);
        }
    }
}
