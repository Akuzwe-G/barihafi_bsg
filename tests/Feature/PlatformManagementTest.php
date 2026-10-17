<?php

namespace Tests\Feature;

use App\Models\AccountSetupInvitation;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\PlatformActivity;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PlatformManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['is_system_admin' => true], $attributes));
    }

    private function clubData(User $administrator, array $extra = []): array
    {
        return array_merge(['name' => 'Community Saving Club', 'description' => 'A local community club.', 'contact_phone' => '+250 788 123 456', 'administrator_phone' => $administrator->phone, 'administrator_mode' => 'existing', 'status' => 'active'], $extra);
    }

    private function membership(User $user, Club $club, string $status = 'active', array $roles = ['Member'], bool $onboarding = false): ClubMembership
    {
        return $club->memberships()->create(['user_id' => $user->id, 'status' => $status, 'roles' => $roles, 'has_onboarding_access' => $onboarding, 'joined_at' => now(), 'activated_at' => $status === 'active' ? now() : null]);
    }

    public function test_admin_password_change_opens_dashboard_and_records_security_event(): void
    {
        $admin = $this->admin(['must_change_password' => true]);
        $password = 'Ax9!'.Str::random(24);
        $this->actingAs($admin)->post('/change-password', ['current_password' => 'password', 'password' => $password, 'password_confirmation' => $password])->assertSessionHasNoErrors()->assertRedirect('/admin/dashboard');
        $this->get('/admin/dashboard')->assertOk()->assertSee('System Administrator dashboard');
        $this->assertDatabaseHas('platform_activities', ['actor_id' => $admin->id, 'action' => 'account.password_changed', 'subject_id' => $admin->id]);
    }

    public function test_all_admin_routes_reject_guests_ordinary_users_and_unchanged_initial_passwords(): void
    {
        $club = Club::factory()->create();
        $user = User::factory()->create();
        $paths = ['/admin/dashboard', '/admin/clubs', '/admin/clubs/create', '/admin/clubs/'.$club->id, '/admin/clubs/'.$club->id.'/edit', '/admin/users', '/admin/users/'.$user->id, '/admin/activities', '/admin/settings', '/admin/profile'];
        foreach ($paths as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->actingAs($user);
        foreach ($paths as $path) {
            $this->get($path)->assertForbidden();
        }
        $writes = [['POST', '/admin/clubs'], ['PATCH', '/admin/clubs/'.$club->id], ['PATCH', '/admin/clubs/'.$club->id.'/status'], ['POST', '/admin/clubs/'.$club->id.'/administrator'], ['PATCH', '/admin/users/'.$user->id.'/status'], ['POST', '/admin/users/'.$user->id.'/setup-invitation'], ['PATCH', '/admin/settings'], ['PATCH', '/admin/profile']];
        foreach ($writes as [$method,$path]) {
            $this->call($method, $path)->assertForbidden();
        }
        $this->actingAs($this->admin(['must_change_password' => true]));
        foreach ($paths as $path) {
            $this->get($path)->assertRedirect('/change-password');
        }
        foreach ($writes as [$method,$path]) {
            $this->call($method, $path)->assertRedirect('/change-password');
        }
    }

    public function test_dashboard_uses_real_counts_and_twelve_month_registration_history(): void
    {
        $admin = $this->admin();
        User::factory()->count(3)->create();
        Club::factory()->count(2)->create(['status' => 'active']);
        Club::factory()->create(['status' => 'suspended']);
        Club::factory()->create(['status' => 'inactive']);
        Club::factory()->create(['created_at' => now()->subYears(2)]);
        $response = $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
        $this->assertSame(['clubs' => 5, 'active' => 3, 'suspended' => 1, 'inactive' => 1, 'users' => 4], $response->viewData('statistics'));
        $chart = $response->viewData('chart');
        $this->assertCount(12, $chart);
        $this->assertSame(4, $chart->sum('count'));
        $this->assertSame(4, $chart->last()['count']);
    }

    public function test_dashboard_and_lists_have_truthful_empty_states(): void
    {
        $this->actingAs($this->admin())->get('/admin/dashboard')->assertOk()->assertSee('No clubs created yet.')->assertSee('No platform activity recorded yet.')->assertSee('No club registrations recorded in this period.');
        $this->get('/admin/clubs')->assertOk()->assertSee('No clubs match these filters.');
    }

    public function test_club_creation_reuses_existing_user_generates_code_and_keeps_financial_membership_pending(): void
    {
        $admin = $this->admin();
        $existing = User::factory()->create();
        $this->actingAs($admin)->post('/admin/clubs', $this->clubData($existing, ['code' => 'ATTACKER-CODE', 'join_code' => 'ATTACKER-JOIN', 'status' => 'active']))->assertSessionHasNoErrors()->assertRedirect();
        $club = Club::sole();
        $this->assertSame(2, User::count());
        $this->assertNotSame('ATTACKER-CODE', $club->code);
        $this->assertNotSame('ATTACKER-JOIN', $club->join_code);
        $this->assertSame('+250788123456', $club->contact_phone);
        $membership = $club->memberships()->sole();
        $this->assertSame($existing->id, $membership->user_id);
        $this->assertContains('Club Administrator', $membership->roles);
        $this->assertContains('Member', $membership->roles);
        $this->assertSame('pending', $membership->status);
        $this->assertTrue($membership->has_onboarding_access);
        $this->assertNull($membership->activated_at);
        $this->assertDatabaseHas('platform_activities', ['action' => 'club.created', 'club_id' => $club->id, 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('platform_activities', ['action' => 'club.initial_administrator_assigned', 'club_id' => $club->id]);
    }

    public function test_existing_administrator_can_be_selected_by_id(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $data = $this->clubData($user);
        unset($data['administrator_phone']);
        $data['administrator_id'] = $user->id;
        $this->actingAs($admin)->post('/admin/clubs', $data)->assertSessionHasNoErrors();
        $this->assertSame($user->id, Club::sole()->memberships()->sole()->user_id);
    }

    public function test_club_codes_are_unique_and_enforced_by_database(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->actingAs($admin);
        for ($i = 0; $i < 3; $i++) {
            $this->post('/admin/clubs', $this->clubData($user, ['name' => 'Club '.$i]))->assertSessionHasNoErrors();
        }
        $this->assertSame(3, Club::distinct()->count('code'));
        $this->expectException(UniqueConstraintViolationException::class);
        Club::factory()->create(['code' => Club::first()->code]);
    }

    public function test_new_administrator_gets_one_time_hashed_expiring_setup_invitation(): void
    {
        $admin = $this->admin();
        $data = ['name' => 'New Club', 'administrator_mode' => 'new', 'administrator_name' => 'New Administrator', 'administrator_phone' => '+250 788 222 333', 'administrator_email' => 'NEW@example.com', 'status' => 'active'];
        $response = $this->actingAs($admin)->post('/admin/clubs', $data)->assertSessionHasNoErrors()->assertSessionHas('setup_url');
        $url = session('setup_url');
        $token = basename(parse_url($url, PHP_URL_PATH));
        $user = User::where('phone', '+250788222333')->sole();
        $invitation = AccountSetupInvitation::sole();
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('invited', $user->account_status);
        $this->assertFalse($user->is_system_admin);
        $this->assertTrue(Hash::isHashed($user->password));
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->isFuture());
        $this->assertNull($invitation->used_at);
        foreach (PlatformActivity::all() as $activity) {
            $this->assertStringNotContainsString($token, $activity->toJson());
            $this->assertStringNotContainsString($user->password, $activity->toJson());
        }
        $this->post('/logout');
        $this->get($url)->assertOk();
        $password = 'Bx8!'.Str::random(24);
        $this->post($url, ['password' => $password, 'password_confirmation' => $password])->assertSessionHasNoErrors()->assertRedirect('/login');
        $this->assertSame('active', $user->fresh()->account_status);
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertNotNull($invitation->fresh()->used_at);
        $this->assertSame('pending', $user->memberships()->sole()->status);
        $this->get($url)->assertNotFound();
        $this->post($url, ['password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        $this->post('/login', ['login' => $user->phone, 'password' => $password])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect(route('clubs.onboarding', Club::sole()));
    }

    public function test_new_account_onboarding_rejects_existing_phone_and_rolls_back_creation(): void
    {
        $admin = $this->admin();
        $existing = User::factory()->create();
        $this->actingAs($admin)->post('/admin/clubs', $this->clubData($existing, ['administrator_mode' => 'new', 'administrator_name' => 'Attempted Duplicate']))->assertSessionHasErrors('administrator_phone');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('clubs', 0);
        $this->assertDatabaseCount('account_setup_invitations', 0);
        $this->post('/admin/clubs', ['name' => 'Incomplete', 'administrator_mode' => 'new', 'administrator_name' => 'Missing Phone', 'administrator_id' => $existing->id])->assertSessionHasErrors('administrator_phone');
    }

    public function test_club_creation_failure_rolls_back_club_account_membership_setup_and_logs(): void
    {
        $admin = $this->admin();
        $event = 'eloquent.creating: '.PlatformActivity::class;
        Event::listen($event, function (PlatformActivity $activity): void {
            if ($activity->action === 'club.initial_administrator_assigned') {
                throw new RuntimeException('Audit storage unavailable');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)->post('/admin/clubs', ['name' => 'Must Roll Back', 'administrator_mode' => 'new', 'administrator_name' => 'Not Saved', 'administrator_phone' => '+250788444555']);
            $this->fail('Expected transaction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('clubs', 0);
        $this->assertDatabaseCount('club_memberships', 0);
        $this->assertDatabaseCount('account_setup_invitations', 0);
        $this->assertDatabaseCount('platform_activities', 0);
    }

    public function test_pending_administrator_has_only_scoped_onboarding_access(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->create();
        $other = Club::factory()->create(['name' => 'UNRELATED SECRET']);
        $this->membership($user, $club, 'pending', ['Club Administrator', 'Member'], true);
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('clubs.onboarding', $club));
        $this->get(route('clubs.onboarding', $club))->assertOk()->assertSee('Administrative onboarding only')->assertDontSee($other->name);
        $this->get(route('clubs.show', $club))->assertForbidden();
        $this->get(route('clubs.members', $club))->assertForbidden();
        $this->get(route('clubs.onboarding', $other))->assertForbidden();
        $this->get(route('clubs.show', $other))->assertForbidden();
        $this->post(route('clubs.invite', $club), ['phone' => '+250788111999'])->assertSessionHas('invitation_url');
        $this->post(route('clubs.invite', $other), ['phone' => '+250788111999'])->assertForbidden();
    }

    public function test_multi_club_selection_includes_only_authorized_member_or_onboarding_workspaces(): void
    {
        $user = User::factory()->create();
        $active = Club::factory()->create();
        $pending = Club::factory()->create();
        $secret = Club::factory()->create(['name' => 'HIDDEN UNRELATED']);
        $this->membership($user, $active);
        $this->membership($user, $pending, 'pending', ['Club Administrator'], true);
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee($active->name)->assertSee($pending->name)->assertSee(route('clubs.onboarding', $pending))->assertDontSee($secret->name);
    }

    public function test_club_suspension_requires_confirmation_and_reason_and_retains_records(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $club = Club::factory()->create();
        $this->membership($user, $club);
        $this->actingAs($admin)->patch(route('admin.clubs.status', $club), ['status' => 'suspended'])->assertSessionHasErrors(['confirmed', 'reason']);
        $this->assertTrue($club->fresh()->is_active);
        $this->patch(route('admin.clubs.status', $club), ['status' => 'suspended', 'confirmed' => 1, 'reason' => 'Administrative review'])->assertSessionHasNoErrors();
        $club->refresh();
        $this->assertFalse($club->is_active);
        $this->assertSame('suspended', $club->status);
        $this->assertSame('Administrative review', $club->suspension_reason);
        $this->assertSame($admin->id, $club->suspended_by);
        $this->assertNotNull($club->suspended_at);
        $this->assertDatabaseCount('club_memberships', 1);
        $this->assertDatabaseHas('platform_activities', ['actor_id' => $admin->id, 'action' => 'club.suspended', 'club_id' => $club->id]);
        $this->actingAs($user)->get(route('clubs.show', $club))->assertForbidden();
        $this->get('/dashboard')->assertSee('No active club access yet');
        $this->actingAs($admin)->patch(route('admin.clubs.status', $club), ['status' => 'active', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->actingAs($user)->get(route('clubs.show', $club))->assertOk();
        $this->assertDatabaseHas('platform_activities', ['action' => 'club.activated', 'club_id' => $club->id]);
    }

    public function test_suspended_club_blocks_pending_administrator_onboarding_and_join_links(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $club = Club::factory()->create();
        $this->membership($user, $club, 'pending', ['Club Administrator'], true);
        $this->actingAs($admin)->patch(route('admin.clubs.status', $club), ['status' => 'suspended', 'reason' => 'Review', 'confirmed' => 1]);
        $this->actingAs($user)->get(route('clubs.onboarding', $club))->assertForbidden();
        $this->post('/join', ['code' => $club->join_code])->assertSessionHasErrors('code');
    }

    public function test_club_edit_and_reassignment_are_audited_without_changing_membership_status(): void
    {
        $admin = $this->admin();
        $old = User::factory()->create();
        $new = User::factory()->create();
        $club = Club::factory()->create();
        $oldMembership = $this->membership($old, $club, 'pending', ['Club Administrator', 'President', 'Member'], true);
        $this->actingAs($admin)->patch(route('admin.clubs.update', $club), ['name' => 'Updated Club', 'description' => 'Changed description', 'contact_phone' => '00250 788 555 666', 'status' => 'suspended'])->assertSessionHasNoErrors();
        $this->assertSame('active', $club->fresh()->status);
        $this->post(route('admin.clubs.assign', $club), ['administrator_phone' => $new->phone, 'replace_administrators' => 1, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertNotContains('Club Administrator', $oldMembership->fresh()->roles);
        $this->assertContains('President', $oldMembership->fresh()->roles);
        $this->assertFalse($oldMembership->fresh()->has_onboarding_access);
        $newMembership = $club->memberships()->where('user_id', $new->id)->sole();
        $this->assertSame('pending', $newMembership->status);
        $this->assertTrue($newMembership->has_onboarding_access);
        $this->assertDatabaseHas('platform_activities', ['action' => 'club.profile_updated', 'club_id' => $club->id]);
        $this->assertDatabaseHas('platform_activities', ['action' => 'club.administrator_changed', 'club_id' => $club->id]);
        $this->get(route('admin.clubs.show', $club))->assertOk()->assertSee('Updated Club')->assertSee($new->name)->assertSee('Club Profile Updated');
    }

    public function test_assignment_to_revoked_membership_does_not_restore_access(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $club = Club::factory()->create();
        $membership = $this->membership($user, $club, 'revoked');
        $this->actingAs($admin)->post(route('admin.clubs.assign', $club), ['administrator_phone' => $user->phone, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('revoked', $membership->fresh()->status);
        $this->assertFalse($membership->fresh()->has_onboarding_access);
        $this->actingAs($user)->get(route('clubs.onboarding', $club))->assertForbidden();
    }

    public function test_club_list_supports_search_status_and_pagination(): void
    {
        $admin = $this->admin();
        Club::factory()->count(18)->create();
        $match = Club::factory()->create(['name' => 'Unique Suspension Search', 'status' => 'suspended']);
        $response = $this->actingAs($admin)->get('/admin/clubs')->assertOk();
        $this->assertSame(19, $response->viewData('clubs')->total());
        $this->assertCount(15, $response->viewData('clubs'));
        $this->get('/admin/clubs?search=Unique&status=suspended')->assertOk()->assertSee($match->name)->assertViewHas('clubs', fn ($clubs) => $clubs->total() === 1);
        $this->get('/admin/clubs?search='.$match->code)->assertViewHas('clubs', fn ($clubs) => $clubs->total() === 1);
        $this->get('/admin/clubs?status=invalid')->assertSessionHasErrors('status');
    }

    public function test_user_directory_details_filters_and_pagination_do_not_expose_password_hashes(): void
    {
        $admin = $this->admin();
        User::factory()->count(16)->create();
        $user = User::factory()->create(['name' => 'Directory Target', 'account_status' => 'suspended']);
        $club = Club::factory()->create();
        $this->membership($user, $club, 'pending');
        $response = $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->assertSame(18, $response->viewData('users')->total());
        $this->assertCount(15, $response->viewData('users'));
        $response->assertDontSee($user->password);
        $this->get('/admin/users?search=Directory&status=suspended&role=ordinary')->assertViewHas('users', fn ($users) => $users->total() === 1)->assertSee($user->phone);
        $this->get(route('admin.users.show', $user))->assertOk()->assertSee($club->name)->assertSee('Pending')->assertDontSee($user->password);
    }

    public function test_account_suspension_revokes_sessions_and_blocks_login_until_reactivated(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $club = Club::factory()->create();
        $this->membership($user, $club);
        $remember = $user->remember_token;
        DB::table('sessions')->insert(['id' => 'suspended-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($admin)->patch(route('admin.users.status', $user), ['action' => 'suspend', 'confirmed' => 1, 'reason' => 'Account review'])->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame('suspended', $user->account_status);
        $this->assertNotSame($remember, $user->remember_token);
        $this->assertSame($admin->id, $user->suspended_by);
        $this->assertDatabaseMissing('sessions', ['id' => 'suspended-session']);
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['login' => $user->phone, 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->actingAs($admin)->patch(route('admin.users.status', $user), ['action' => 'reactivate', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('active', $user->fresh()->account_status);
        $this->assertDatabaseHas('platform_activities', ['action' => 'account.suspended', 'subject_id' => $user->id]);
        $this->assertDatabaseHas('platform_activities', ['action' => 'account.reactivated', 'subject_id' => $user->id]);
        $this->post('/logout');
        $this->post('/login', ['login' => $user->phone, 'password' => 'password'])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
    }

    public function test_system_administrator_cannot_suspend_self(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->patch(route('admin.users.status', $admin), ['action' => 'suspend', 'confirmed' => 1, 'reason' => 'Mistake'])->assertSessionHasErrors('action');
        $this->assertSame('active', $admin->fresh()->account_status);
    }

    public function test_setup_link_expiry_reissue_and_suspension_are_enforced(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['account_status' => 'invited']);
        $old = DB::transaction(fn () => AccountSetupInvitation::issue($user, $admin));
        $password = 'Cx7!'.Str::random(24);
        $this->actingAs($admin)->post(route('admin.users.setup', $user), ['confirmed' => 1])->assertSessionHas('setup_url');
        $new = session('setup_url');
        $this->assertNotSame($old, $new);
        $this->post('/logout');
        $this->get($old)->assertNotFound();
        $this->get($new)->assertOk();
        $this->travel(49)->hours();
        $this->get($new)->assertNotFound();
        $this->post($new, ['password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        $this->travelBack();
        $this->actingAs($admin)->patch(route('admin.users.status', $user), ['action' => 'suspend', 'confirmed' => 1, 'reason' => 'Stop setup'])->assertSessionHasNoErrors();
        $this->post('/logout');
        $this->get($new)->assertNotFound();
        $this->post($new, ['password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        $this->actingAs($admin)->patch(route('admin.users.status', $user), ['action' => 'reactivate', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('invited', $user->fresh()->account_status);
    }

    public function test_activity_logs_are_filterable_and_sensitive_metadata_is_discarded(): void
    {
        $admin = $this->admin();
        $club = Club::factory()->create();
        PlatformActivity::record($admin, 'club.created', $club, $club, ['status' => 'active', 'password' => 'DO-NOT-LOG', 'token' => 'PRIVATE-TOKEN', 'email' => 'private@example.com']);
        PlatformActivity::record($admin, 'club.profile_updated', $club, $club, ['fields' => ['description']]);
        $activity = PlatformActivity::first();
        $this->assertSame(['status' => 'active'], $activity->changes);
        $this->actingAs($admin)->get('/admin/activities?action=club.created')->assertOk()->assertSee('Club Created')->assertViewHas('activities', fn ($activities) => $activities->total() === 1 && $activities->first()->action === 'club.created')->assertDontSee('DO-NOT-LOG')->assertDontSee('PRIVATE-TOKEN');
        $this->get('/admin/activities?from='.now()->format('Y-m-d').'&to='.now()->format('Y-m-d'))->assertOk()->assertViewHas('activities', fn ($activities) => $activities->total() === 2);
        $this->get('/admin/activities?from=invalid')->assertSessionHasErrors('from');
        $this->get('/admin/activities?to='.now()->format('Y-m-d'))->assertOk()->assertViewHas('activities', fn ($activities) => $activities->total() === 2);
    }

    public function test_platform_settings_branding_and_profile_are_functional_and_audited(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('RWF');
        $this->patch('/admin/settings', ['platform_name' => 'Community Barihafi', 'currency' => 'RWF', 'brand_color' => '#E98A2A', 'brand_initials' => 'BH'])->assertSessionHasNoErrors();
        $this->assertSame('Community Barihafi', PlatformSetting::current()->platform_name);
        $this->get('/admin/dashboard')->assertSee('Community Barihafi');
        $this->assertDatabaseHas('platform_activities', ['action' => 'platform.settings_updated']);
        $this->patch('/admin/settings', ['platform_name' => 'Unsafe', 'currency' => 'RWF', 'brand_color' => 'url(javascript:alert(1))', 'brand_initials' => 'BH'])->assertSessionHasErrors('brand_color');
        $data = ['name' => 'Updated Operator', 'phone' => '00250 788 999 000', 'email' => null, 'current_password' => 'password', 'is_system_admin' => false, 'account_status' => 'suspended'];
        $this->patch('/admin/profile', $data)->assertSessionHasNoErrors();
        $admin->refresh();
        $this->assertSame('+250788999000', $admin->phone);
        $this->assertNull($admin->email);
        $this->assertTrue($admin->is_system_admin);
        $this->assertSame('active', $admin->account_status);
        $this->assertDatabaseHas('platform_activities', ['action' => 'account.profile_updated']);
        $this->get('/admin/profile')->assertOk()->assertSee('Updated Operator');
        $data['current_password'] = 'wrong';
        $this->patch('/admin/profile', $data)->assertSessionHasErrors('current_password');
    }

    public function test_legacy_platform_routes_preserve_confirmation_and_audit_requirements(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $club = Club::factory()->create();
        $this->actingAs($admin);
        $this->patch(route('platform.clubs.update', $club), ['name' => 'Retained', 'is_active' => false, 'confirmed' => 1])->assertSessionHasErrors('reason');
        $this->assertTrue($club->fresh()->is_active);
        $this->post(route('platform.clubs.assign', $club), ['administrator_phone' => $user->phone])->assertSessionHasErrors('confirmed');
        $this->get('/platform')->assertOk()->assertSee('System Administrator dashboard');
    }

    public function test_administration_pages_render_with_consistent_navigation(): void
    {
        $admin = $this->admin(['name' => 'Platform Reviewer']);
        $member = User::factory()->create(['name' => 'Club Administrator']);
        $club = Club::factory()->create(['name' => 'Review Community Club', 'description' => 'Club profile for isolated interface review.']);
        $this->membership($member, $club, 'pending', ['Club Administrator', 'Member'], true);
        PlatformActivity::record($admin, 'club.created', $club, $club, ['status' => 'active']);
        $this->actingAs($admin);
        $capture = (bool) getenv('BARIHAFI_REVIEW_ARTIFACTS');
        if ($capture) {
            $this->withVite();
            URL::forceRootUrl('http://127.0.0.1:8128');
            File::ensureDirectoryExists(storage_path('app/phase2-review'));
        }
        $pages = [
            'dashboard' => ['/admin/dashboard', 'System Administrator dashboard'],
            'club-create' => ['/admin/clubs/create', 'Create a club'],
            'club-edit' => ['/admin/clubs/'.$club->id.'/edit', 'Edit club profile'],
            'club-show' => ['/admin/clubs/'.$club->id, 'Review Community Club'],
            'clubs' => ['/admin/clubs', 'Clubs'],
            'users' => ['/admin/users', 'User directory'],
            'user-show' => ['/admin/users/'.$member->id, 'Club Administrator'],
            'settings' => ['/admin/settings', 'Platform settings'],
            'profile' => ['/admin/profile', 'My profile'],
            'activities' => ['/admin/activities', 'Activity logs'],
        ];
        foreach ($pages as $file => [$path, $heading]) {
            $response = $this->get($path)->assertOk()->assertSee($heading, false)->assertSee('Platform Settings')->assertSee('Change password');
            if ($capture) {
                file_put_contents(storage_path('app/phase2-review/'.$file.'.html'), $response->getContent());
            }
        }
        if ($capture) {
            file_put_contents(storage_path('app/phase2-review/routes.json'), json_encode(array_map(fn ($page) => $page[0], $pages)));
        }
    }
}
