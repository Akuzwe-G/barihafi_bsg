# Barihafi - Authentication and Platform Administration

Barihafi is an independent Laravel 13.37 application for multiple savings clubs. Phases 1 and 2 provide authentication, controlled account setup, platform administration, club management and membership access foundations. No financial transactions, balances, shares, loans, dividends, banking or ledger posting are implemented.

## Launch and use the System Administrator dashboard

For the existing Laragon installation, start MySQL and run from `C:\laragon\www\barihafi`:

```powershell
php artisan migrate --no-interaction
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
```

1. Open **http://127.0.0.1:8000/login** and sign in using your existing System Administrator phone/email and password. Existing accounts and passwords are preserved; do not recreate the initial administrator.
2. If prompted, complete the mandatory initial password change. A System Administrator is then sent directly to **`/admin/dashboard`**.
3. Use **Clubs -> Create club**. Enter the name, optional description/contact phone and active/inactive status. The unique `BHF-...` club code is generated automatically; the high-entropy private joining link is separate.
4. Choose **Use an existing account** and enter its registered phone (the linked user directory can help), or **Invite a new account** and enter full name, international phone and optional email. Existing accounts are reused; conflicts in new-account mode require selecting the existing account instead of creating a duplicate.
5. For a new account, copy the private setup link shown after creation and deliver it securely to the verified recipient. It expires after 48 hours and can be used once. The recipient chooses their own strong password. No predictable/default password is sent or shown.
6. Club details provide profile editing, assigned administrators, membership count, private joining link, status controls and administrative history. Administrator reassignment requires confirmation and can retain or replace other administrators without deleting memberships or activating financial membership.
7. Suspending a club requires both confirmation and a reason. The acting administrator and timestamp are stored. Inactive and suspended clubs block member and administrative-onboarding workspaces while preserving all records. Activation restores access subject to the user's account and membership permissions.
8. **Users** supports search, status/role filters, pagination, details, assigned clubs, account suspension/reactivation and setup-link reissue. Suspension revokes remembered/database sessions and pending setup/recovery links. You cannot suspend yourself or the last active System Administrator. Reactivating an account awaiting setup restores its invited state; issue a fresh setup link to finish onboarding.
9. **Activity Logs** lists actual administrative/security events with action/date filters. **Platform Settings** controls platform name, brand initials/accent and the default display currency (RWF). These settings do not define club financial policies. **My Profile** edits your contact details after verifying your current password; the profile menu also offers password change and logout.

If using a Laragon virtual host, point its document root to this project's `public` directory and set `APP_URL` to that URL. Generated setup/recovery links must use a URL reachable by their recipient. For live frontend development run `npm.cmd run dev` in another terminal; stop it when switching back to built assets.

## Fresh checkout configuration

```powershell
composer install
npm.cmd install
```

Keep an existing `.env` and application key. For a new checkout only, copy `.env.example` to `.env`, run `php artisan key:generate --no-interaction`, and configure local credentials for MySQL database `barihafi`:

```dotenv
APP_NAME=Barihafi
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=barihafi
SESSION_DRIVER=database
```

Create an empty `barihafi` database only if one does not already exist. Run `php artisan config:clear --no-interaction` and `php artisan migrate --no-interaction`. Never use `migrate:fresh` or a reset against development data.

## Initial System Administrator seeder

For a new platform only, configure these Git-ignored `.env` values before explicitly running `SystemAdminSeeder`. Blank values are placeholders, not defaults:

```dotenv
BARIHAFI_ADMIN_NAME=
BARIHAFI_ADMIN_PHONE=
BARIHAFI_ADMIN_EMAIL=
BARIHAFI_ADMIN_PASSWORD=
```

Name and normalized international phone are required. Email is optional and may remain empty. Use a unique, randomly generated 16-72 character password containing uppercase/lowercase letters, numbers and symbols, with at most 72 bytes and no null characters. Single-quote dotenv values containing special characters such as `#`, spaces or `$`.

```powershell
php artisan config:clear --no-interaction
php artisan migrate --no-interaction
php artisan db:seed --class=SystemAdminSeeder
```

`config/barihafi.php` reads the variables. The dedicated seeder assigns platform `system_admin` through the existing `is_system_admin` capability; it creates no club membership. It runs only when explicitly invoked, never during application boot, migrations, public registration or default database seeding. Repeated runs with the same normalized phone/email preserve the existing password, name and first-login state. Conflicts with ordinary accounts, mismatched identities or another initial administrator fail without elevation or mutation. Credentials are not printed or logged. Changing environment values is not a password-reset mechanism.

After setup, remove bootstrap credential values, especially the initial password, from `.env` and run `php artisan config:clear --no-interaction`. Rebuild any deployment configuration cache only after removing them. Never commit `.env` or dump initial-admin configuration into logs/tickets. The first successful sign-in requires a different, confirmed strong password before protected workspace access. The interactive `php artisan barihafi:create-account --system-admin` alternative also requires first-login password change. Use `php artisan barihafi:create-account` without that option for a controlled ordinary account.

## Access and membership boundaries

- Login supports unique normalized phones and optional unique lowercase emails, secure hashing, remember me, generic credential failures, session regeneration, throttling and logout. International `+` numbers and `00` prefixes are accepted; separators are removed. National-only numbers are rejected rather than guessed. No email verification or SMS integration is required.
- Every `/admin/*` route requires authentication, an active account, completed mandatory password change and the platform gate. `/platform` remains a protected dashboard alias, and legacy platform writes use the same audited services and confirmation rules.
- Platform administration grants no member financial access. One authorised club opens automatically; multiple authorised clubs show only those clubs; no authorised club opens onboarding/pending status. Clubs remain private, without a public directory.
- Active club memberships can open member dashboards. A pending applicant cannot. Assigned pending Club Administrators may have the separate `has_onboarding_access` permission: this opens only their own `/clubs/{club}/onboarding` page, basic club profile and pending invitation tools. It does not open member dashboards, membership management or financial information.
- New memberships are always pending, including initial Club Administrators. Existing suspended/revoked memberships are never restored by assignment or joining again. Role replacement removes administrative onboarding access while retaining other roles and membership records. Suspended/inactive clubs and suspended accounts block access immediately.
- Existing pending Club Administrators receive administrative-onboarding flags in the Phase 2 migration; membership status and activation dates are not changed. Existing inactive clubs retain their state. Existing users remain active unless explicitly suspended.
- No membership activation endpoint, contribution verification shortcut or financial data exists. Future verification of the first complete share contribution is required before financial membership activation.
- Forms use Laravel request-forgery protection and server validation. Critical club/account/audit operations are transactional. Brand colors are restricted to hex values, profile edits require the current password, and public registration cannot set platform privileges, account status or administrative-onboarding access.

## Invitations and controlled password recovery

New Club Administrator accounts are stored in an invited state with an unguessable password that is never shared, plus a hashed, expiring one-time setup token. Setup links are displayed once for private delivery and can be reissued from user details. Reissue invalidates previous links. Invited/suspended accounts cannot sign in normally; setup cannot reactivate a suspended account or activate financial membership. Club membership invitations remain phone-bound, hashed, single-use seven-day links that create pending requests.

For recovery of an existing account, the public recovery page directs the user to their established private contact. After independently verifying identity, a trusted operator runs:

```powershell
php artisan barihafi:issue-recovery '+250788123456'
```

The operator must explicitly confirm verification and secure private delivery. The one-hour single-use URL lets the user choose a new password; only a hash is stored in `password_reset_tokens` (its legacy `email` key holds the normalized phone). Use invalidates the token, rotates remembered access and revokes database sessions. Recovery does not clear a mandatory first-password-change requirement or reactivate a suspended account. Keep the configured database session driver. Set secure cookies and disable debug output for an HTTPS deployment.

## Dashboard data, logs and settings

Dashboard counts come from current database records: total, active, suspended and inactive clubs, plus registered accounts. The twelve-month chart uses real club creation timestamps grouped in UTC. Recent clubs and events query actual records and show zero/empty states for a new platform. Audit timestamps are displayed in Mauritius time (UTC+4); date filters use UTC. Audit rows start with events performed after the feature is installed; historical events are not fabricated.

Events include club creation, profile updates, activation/suspension/deactivation, initial administrator assignment/reassignment, account setup/reissue/completion, account suspension/reactivation, profile/password changes and platform display settings. Logs store actor, action, subject, optional club and time. Change metadata is restricted to non-sensitive fields, roles, statuses and IDs. Passwords, hashes, setup tokens, joining links and recovery tokens are never audit metadata. Suspension reasons are stored on the relevant club/account and are visible only to authorised administrators. No permanent club deletion is implemented.

Montserrat is bundled locally with Tailwind and Alpine. The responsive layout includes sidebar navigation, notification placeholder, profile menu, loading states, keyboard-accessible confirmation dialogs, empty/error/success states and horizontally scrollable mobile data tables/charts. Default currency is RWF; no conversion, ledger or financial-policy logic is attached to that display preference.

## Phase 2 migration

`database/migrations/2026_10_17_173158_add_platform_management_foundation.php`:

- Adds account status and suspension metadata to users.
- Adds unique club codes, descriptions, contact phones, active/inactive/suspended status and suspension metadata; backfills existing rows without recreating records.
- Adds the separate administrative-onboarding flag to club memberships.
- Creates `account_setup_invitations`, `platform_activities` and `platform_settings`.

The previous migrations remain unchanged. The new migration was applied with normal `php artisan migrate --no-interaction` against MySQL `barihafi`; no development reset was used.

## Routes introduced in Phase 2

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/admin/dashboard` | Platform counts, chart and recent records |
| GET / POST | `/admin/clubs` | Search/list or create clubs |
| GET | `/admin/clubs/create` | Club creation form |
| GET | `/admin/clubs/{club}` | Club details/history |
| GET | `/admin/clubs/{club}/edit` | Edit form |
| PATCH | `/admin/clubs/{club}` | Update basic profile |
| PATCH | `/admin/clubs/{club}/status` | Confirmed activate/deactivate/suspend |
| POST | `/admin/clubs/{club}/administrator` | Confirmed administrator assignment |
| GET | `/admin/users` | Search/filter/paginate directory |
| GET | `/admin/users/{user}` | Account details and memberships |
| PATCH | `/admin/users/{user}/status` | Confirmed suspend/reactivate |
| POST | `/admin/users/{user}/setup-invitation` | Reissue private setup link |
| GET | `/admin/activities` | Filtered activity history |
| GET / PATCH | `/admin/settings` | Read/update display branding settings |
| GET / PATCH | `/admin/profile` | Read/update own profile |
| GET / POST | `/account/setup/{token}` | Guest account setup through private invitation |
| GET | `/clubs/{club}/onboarding` | Scoped pending-administrator workspace |

Existing `/login`, `/dashboard`, `/platform`, `/change-password`, recovery, joining and member routes remain supported. Successful System Administrator password change now redirects directly to `/admin/dashboard`.

## Phase 2 file inventory

Created:

- Controllers: `app/Http/Controllers/AdminClubController.php`, `PlatformController.php`, `AccountSetupController.php`.
- Service/middleware: `app/Support/ClubAdministration.php`, `app/Http/Middleware/EnsureAccountActive.php`.
- Models: `app/Models/PlatformActivity.php`, `PlatformSetting.php`, `AccountSetupInvitation.php`; factory `database/factories/PlatformActivityFactory.php`.
- Migration listed above; test `tests/Feature/PlatformManagementTest.php`.
- Views: `resources/views/admin/dashboard.blade.php`, `admin/clubs/{index,form,show}.blade.php`, `admin/users/{index,show}.blade.php`, `admin/activities.blade.php`, `admin/settings.blade.php`, `admin/profile.blade.php`, `admin/partials/activities.blade.php`, `account-setup.blade.php`, `club-onboarding.blade.php`.

Updated:

- `app/Models/User.php`, `Club.php`, `ClubMembership.php`, `app/Policies/ClubPolicy.php`.
- `app/Http/Controllers/AuthController.php`, `ClubController.php`, `PasswordChangeController.php`, `RecoveryController.php`, `JoiningController.php`.
- `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `routes/web.php`.
- `resources/views/layouts/app.blade.php`, `resources/views/selection.blade.php`, `resources/views/password-change.blade.php`, `resources/css/app.css`.
- `tests/Feature/PhaseOneTest.php`, `tests/Feature/SystemAdminSetupTest.php` (new confirmation rules and direct dashboard redirect), and this README.

No new runtime dependencies were added for Phase 2.

## Validation and test safety

```powershell
php artisan test --compact
php vendor/bin/pint --dirty --format agent
npm.cmd run build
php artisan view:cache --no-interaction
php artisan route:list --path=admin --no-interaction
php artisan migrate:status --no-interaction
git diff --check
```

PHPUnit forces isolated SQLite `:memory:` storage, and `Tests\TestCase` refuses any other database before database refresh traits execute. Development continues to use MySQL `barihafi`. Tests cover all requested Phase 2 cases plus directory filters/pagination, settings/profile security, invitation expiry/reissue/single use, revoked membership protection, account suspension, audit metadata filtering, legacy route protections and successful rendering of every admin screen. Authentication and initial-admin regression tests remain included. Final suite result: **72 tests passed, 672 assertions**.

Headless Edge review uses HTML generated from isolated test fixtures, with built local assets and a local request-capture server; it never signs into or writes to the development database. Review checks desktop/mobile overflow, mobile menu, new-administrator field visibility, form submission and confirmation cancellation/submission. Screenshots are in ignored `storage/app/phase2-review/`. To regenerate render fixtures, temporarily set `BARIHAFI_REVIEW_ARTIFACTS=1` while running the `test_administration_pages_render_with_consistent_navigation` test; normal test runs create no review artifacts.

Setup commands included existing dependency/version checks and Artisan `make:migration`, `make:model --factory`, `make:class`, `make:controller`, `make:middleware` and `make:test --phpunit`. Laravel Boost was already installed and generated guidelines were followed. Phase 2 required no dependency installation or destructive database operation.

## Remaining boundaries

Financial verification and financial membership activation remain unimplemented. There are no shares, savings, loans, repayments, bank integrations, dividends or ledger entries. Notifications are explicitly a placeholder. Setup/invitation delivery is private and manual; email/SMS delivery and identity verification integrations are not included. Audit records cover implemented administrative operations, not an external tamper-evident logging service. Club financial policies and financial-user-record editing are outside this phase.
