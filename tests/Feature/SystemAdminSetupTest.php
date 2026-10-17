<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SystemAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SystemAdminSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function configureAdmin(array $overrides = []): array
    {
        $data = array_merge(['name' => 'Initial Operator', 'phone' => '00230 5123 4567', 'email' => null, 'password' => 'Aa9!'.Str::random(24)], $overrides);
        config(['barihafi.initial_admin' => $data]);

        return $data;
    }

    private function seedAdmin(): void
    {
        app(SystemAdminSeeder::class)->run();
    }

    private function assertSetupFails(): void
    {
        try {
            $this->seedAdmin();
            $this->fail('Expected initial setup to fail safely.');
        } catch (RuntimeException $exception) {
            $configuration = config('barihafi.initial_admin');
            foreach (['name', 'phone', 'email', 'password'] as $key) {
                if (filled($configuration[$key] ?? null)) {
                    $this->assertStringNotContainsString($configuration[$key], $exception->getMessage());
                }
            }
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_explicit_seeder_creates_normalized_platform_administrator_with_hashed_password(): void
    {
        $data = $this->configureAdmin();
        Log::spy();
        $this->assertSame(0, Artisan::call('db:seed', ['--class' => 'SystemAdminSeeder', '--no-interaction' => true]));
        $user = User::sole();
        $this->assertSame('+23051234567', $user->phone);
        $this->assertNull($user->email);
        $this->assertTrue($user->hasPlatformRole('system_admin'));
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($data['password'], $user->password));
        $this->assertNotSame($data['password'], $user->password);
        $this->assertDatabaseCount('club_memberships', 0);
        foreach ($data as $value) {
            if (filled($value)) {
                $this->assertStringNotContainsString($value, Artisan::output());
            }
        }
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('debug');
    }

    public function test_email_is_optional_and_normalized_when_present(): void
    {
        $this->configureAdmin(['email' => 'OPERATOR@example.com']);
        $this->seedAdmin();
        $this->assertSame('operator@example.com', User::sole()->email);
    }

    public function test_repeated_seeding_preserves_password_name_and_first_login_state(): void
    {
        $this->configureAdmin();
        $this->seedAdmin();
        $user = User::sole();
        $initialHash = $user->password;
        $this->configureAdmin(['password' => 'Bb8!'.Str::random(24), 'name' => 'Changed Config Name']);
        $this->seedAdmin();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($initialHash, $user->fresh()->password);
        $this->assertSame('Initial Operator', $user->fresh()->name);
        $user->password = 'Cc7!'.Str::random(24);
        $user->must_change_password = false;
        $user->save();
        $changedHash = $user->password;
        $this->seedAdmin();
        $this->assertSame($changedHash, $user->fresh()->password);
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_phone_conflict_never_elevates_existing_ordinary_account(): void
    {
        $ordinary = User::factory()->create(['phone' => '+23051234567']);
        $hash = $ordinary->password;
        $this->configureAdmin();
        $this->assertSetupFails();
        $this->assertDatabaseCount('users', 1);
        $this->assertFalse($ordinary->fresh()->is_system_admin);
        $this->assertSame($hash, $ordinary->fresh()->password);
    }

    public function test_email_conflict_never_elevates_existing_ordinary_account(): void
    {
        $ordinary = User::factory()->create(['email' => 'existing@example.com']);
        $hash = $ordinary->password;
        $this->configureAdmin(['email' => 'EXISTING@example.com']);
        $this->assertSetupFails();
        $this->assertDatabaseCount('users', 1);
        $this->assertFalse($ordinary->fresh()->is_system_admin);
        $this->assertSame($hash, $ordinary->fresh()->password);
    }

    public function test_conflicting_phone_and_email_accounts_are_not_combined(): void
    {
        $this->configureAdmin();
        $this->seedAdmin();
        User::factory()->create(['email' => 'other@example.com']);
        $this->configureAdmin(['email' => 'other@example.com']);
        $this->assertSetupFails();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_an_existing_administrator_with_different_identity_blocks_initial_setup(): void
    {
        User::factory()->create(['is_system_admin' => true]);
        $this->configureAdmin();
        $this->assertSetupFails();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_same_phone_with_changed_email_fails_without_changing_existing_admin(): void
    {
        $this->configureAdmin();
        $this->seedAdmin();
        $user = User::sole();
        $hash = $user->password;
        $this->configureAdmin(['email' => 'new@example.com']);
        $this->assertSetupFails();
        $this->assertNull($user->fresh()->email);
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_missing_or_weak_configuration_creates_nothing(): void
    {
        foreach ([[], ['password' => ''], ['password' => Str::random(8)], ['password' => str_repeat('a', 24)], ['password' => 'Aa9!'.str_repeat(chr(195).chr(169), 40)], ['phone' => '51234567'], ['name' => ''], ['email' => 'invalid-email']] as $overrides) {
            if ($overrides === []) {
                config(['barihafi.initial_admin' => []]);
            } else {
                $this->configureAdmin($overrides);
            }
            $this->assertSetupFails();
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_default_database_seeding_and_http_requests_do_not_create_an_administrator(): void
    {
        $this->configureAdmin();
        $this->get('/login')->assertOk();
        $this->assertDatabaseCount('users', 0);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, User::where('is_system_admin', true)->count());
    }

    public function test_first_login_redirects_to_password_change_and_blocks_other_routes(): void
    {
        $data = $this->configureAdmin();
        $this->seedAdmin();
        $this->post('/login', ['login' => '+23051234567', 'password' => $data['password']])->assertRedirect(route('password.change'));
        $this->get('/change-password')->assertOk()->assertSee('Change your initial password');
        foreach (['/dashboard', '/platform', '/join'] as $url) {
            $this->get($url)->assertRedirect(route('password.change'));
        }
        $this->post('/platform/clubs', ['name' => 'Blocked'])->assertRedirect(route('password.change'));
        $this->post('/join', ['code' => 'anything'])->assertRedirect(route('password.change'));
        $this->getJson('/platform')->assertForbidden();
        $this->assertDatabaseCount('clubs', 0);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_first_password_change_requires_initial_password_confirmation_strength_and_difference(): void
    {
        $data = $this->configureAdmin();
        $this->seedAdmin();
        $user = User::sole();
        $this->actingAs($user);
        $new = 'Dd6!'.Str::random(24);
        $this->post('/change-password', ['current_password' => 'wrong', 'password' => $new, 'password_confirmation' => $new])->assertSessionHasErrors('current_password');
        $this->post('/change-password', ['current_password' => $data['password'], 'password' => $data['password'], 'password_confirmation' => $data['password']])->assertSessionHasErrors('password');
        $this->post('/change-password', ['current_password' => $data['password'], 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $this->post('/change-password', ['current_password' => $data['password'], 'password' => $new, 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));
    }

    public function test_password_change_unlocks_workspace_rotates_sessions_and_is_not_undone_by_seeder(): void
    {
        $data = $this->configureAdmin();
        $this->seedAdmin();
        $user = User::sole();
        $new = 'Ee5!'.Str::random(24);
        $this->post('/login', ['login' => $user->phone, 'password' => $data['password']]);
        DB::table('sessions')->insert(['id' => 'prior-admin-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->post('/change-password', ['current_password' => $data['password'], 'password' => $new, 'password_confirmation' => $new])->assertSessionHasNoErrors()->assertRedirect('/admin/dashboard');
        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check($new, $user->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'prior-admin-session']);
        $this->get('/dashboard')->assertRedirect('/platform');
        $this->get('/platform')->assertOk();
        $this->seedAdmin();
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check($new, $user->fresh()->password));
        $this->post('/logout');
        $this->post('/login', ['login' => $user->phone, 'password' => $data['password']])->assertSessionHasErrors('login');
        $this->post('/login', ['login' => $user->phone, 'password' => $new])->assertRedirect('/dashboard');
    }

    public function test_public_registration_cannot_set_platform_role_or_bypass_password_change(): void
    {
        $club = Club::factory()->create();
        $password = 'Ff4!'.Str::random(24);
        $this->post('/join', ['code' => $club->join_code, 'name' => 'Ordinary Member', 'phone' => '+23059999999', 'password' => $password, 'password_confirmation' => $password, 'is_system_admin' => true, 'role' => 'system_admin', 'must_change_password' => false])->assertRedirect('/dashboard');
        $user = User::sole();
        $this->assertFalse($user->hasPlatformRole('system_admin'));
        $this->get('/platform')->assertForbidden();
        $this->post('/logout');
        $this->get('/change-password')->assertRedirect('/login');
        $this->post('/change-password')->assertRedirect('/login');
    }
}
