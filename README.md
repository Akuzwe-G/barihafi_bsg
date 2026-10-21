# Barihafi - Financial Governance and Club Administration

Barihafi is an independent Laravel 13.37 application for multiple savings clubs. Phases 1 through 4 provide authentication, controlled account setup, platform and club administration, admission review, notifications, versioned financial configuration and independent governance approvals. No financial transactions, balances, shares, loans, dividends, banking or ledger posting are implemented.

## Phase 4 - Financial configuration and governance

Club-specific shares, savings, lending and governance settings now follow **Draft -> Submitted -> independent President review -> Effective or Scheduled**. Rejected, cancelled and superseded records remain available. Approval is an audited transition before a version becomes scheduled/effective.

### Installation and scheduled activation

Migration: `database/migrations/2026_10_21_080330_extend_financial_governance.php`. This extends existing proposals/versions and creates `committee_membership_changes`; it preserves old approved snapshot values and reconstructs their effective intervals. Legacy pending proposals become submitted. Pre-Phase-4 preparatory committee flags are recorded as legacy history and withdrawn pending fresh independent nomination/approval. Existing accounts, membership activation states and financial records are preserved.

```powershell
php artisan migrate --no-interaction
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
```

Configure the deployment host to run `php artisan schedule:run` every minute. For local use, run `php artisan schedule:work` in another terminal. The scheduled command runs every minute with overlap protection. It can also be run explicitly:

```powershell
php artisan barihafi:activate-policies --no-interaction
```

Activation is idempotent and skips suspended clubs. If the scheduler stops, activation waits until its next successful run; the actual effective interval starts at activation, avoiding retroactive changes. Financial workspace timestamps are UTC.

### Exact workflow to test

1. As an existing System Administrator, assign a Club Administrator and a **different** President using the existing club role tools. Both global accounts must be active. Explicit administrative/President onboarding access still works while financial membership is pending. System Administrator status alone grants no club financial governance access.
2. As Club Administrator, open **Financial settings -> Propose Rule Changes**. Review the four sections, enter the club's share price/currency and other terms, give a reason and proposed effective time, then **Save draft**. There is no forced initial share price. Suggested values are configuration suggestions, not financial balances.
3. Inspect the saved comparison, then **Submit for President review**. Effective settings remain unchanged. Submission is blocked if no independent authorised President exists. You can cancel your own draft/submitted proposal and create a corrected replacement; a draft cannot be approved.
4. As President, use the dashboard's outstanding reviews or **Pending Proposals** to open the proposal. Review existing/proposed values and effective time. Approve with confirmation, or reject with comments. The proposer cannot review their own proposal, including a person holding both Admin and President roles.
5. The first independently approved version is the **Initial baseline**. Approval effective now creates the sole effective version; future approval is **Scheduled** and retains the current policy until due activation. To test scheduling interactively, choose a time a few minutes ahead and run the activation command after that time.
6. Draft another change after the baseline. The form starts with the effective settings; optional monetary limits can be cleared. Several drafts may exist, but only one submitted/scheduled proposal may be outstanding per club. Stale submissions/approvals and duplicate decisions are rejected. Resolve the outstanding proposal before submitting another.
7. Open **Policy Version History** to inspect immutable snapshots, approval references, reviewers, baseline markers and effective intervals. Superseded versions remain unchanged. Future lending must retain the version and agreed terms on each loan rather than recalculate them.
8. Assign existing **Committee Member** roles using authorised role tools. In **Committee Management**, nominate eligible memberships for appointment with a reason. A separate President who is neither proposer nor affected member approves/rejects each change. Removal uses the same review process. Losing eligibility immediately revokes access and records history; reappointment needs fresh approval.
9. Quorum/minimum size changes use a financial-policy proposal. Safe minimum: three members and two independent approvals, configurable upwards. Conflicted people, inactive accounts and ineligible memberships do not count. Insufficient quorum blocks future approval. Normal lending still requires Accountant review followed by President approval; committee designation grants no ordinary financial role override.
10. Inspect real notifications using the bell and the audit trail in **Club activity**. Admin dashboards show draft, submitted, rejected and scheduled counts. Active ordinary members have read-only settings/history/committee access. Cross-club access, suspended clubs/memberships, self approval, ordinary-member approval and platform-only overrides are denied server-side.

### Configuration and safeguards

- **Shares:** currency, positive decimal price, minimum completed shares, partial contributions, verified partial capital eligibility, activation threshold, contribution precision and exit restrictions.
- **Savings:** support, optional minimum deposit/retained balance, none/weekly/monthly/custom frequency, custom description, withdrawal restrictions/notice. Independent withdrawal approval remains mandatory in this MVP.
- **Loans:** multiplier, optional amounts, decimal interest with monthly/annual/per-term period, flat/reducing balance, instalments/lump sum/both, duration/frequency, early repayment, late policy, cash reserve and maximum lendable percentage. An outstanding loan, pending application or undisbursed approval must block a new loan.
- **Governance:** independent club approval; no self approval; safe configurable committee quorum. `assertCommitteeApprovals` checks distinct eligible, nonconflicted designated members. `assertNoConcurrentLoan` supplies the future loan-state guard. Future financial workflows must apply these under their own transaction locks and preserve normal role controls; there is no lending endpoint in Phase 4.
- Money/rates use decimal strings in JSON; monetary limits use integer comparisons. Club-row locks serialize workflow changes. Unique club/version, approval-reference and active-club keys prevent duplicate and conflicting effective versions. Only lifecycle metadata changes during activation; approved snapshot updates/deletes through the model are denied.
- Scheduled approved commitments cannot be cancelled by the proposer; later changes require a fresh proposal after activation. Correct drafts by cancelling and creating a replacement. Legacy snapshots retain their original recorded fields rather than inventing previously unspecified historical terms.

### Routes, permissions and ten screens

Base: `/clubs/{club}/financial-settings`. Named routes: `clubs.financial.*`. Existing `/clubs/{club}/governance` and proposal/review URLs remain compatible. The legacy proposal POST submits directly through the same validation, independent-approver checks and locking; the new form always saves a draft first. Platform governance retains role delegation/assignment but rejects direct committee designation.

| Relative routes | Permission / purpose |
| --- | --- |
| GET `/`, `/overview`, `/shares`, `/savings`, `/loans`, `/governance`, `/pending` | `financial_settings.view`; overview, four rule sections and register |
| GET `/create`; POST `/proposals` | `financial_settings.propose`; draft form/creation |
| GET `/proposals/{proposal}` | `financial_settings.view`; details and comparison |
| POST `/proposals/{proposal}/submit`, `/cancel` | `financial_settings.propose`; proposer only |
| POST `/proposals/{proposal}/review` | `financial_settings.approve`; independent President |
| GET `/history` | `financial_settings.history`; approved versions |
| GET `/committee` | `committee.view`; membership/readiness/history |
| POST `/committee` | `committee.manage`; nominate appointment/removal |
| POST `/committee/{change}/review` | `financial_settings.approve`; independent President excluding affected member |

All six permissions resolve against the current club membership. The ten requested screens reuse the dark/orange Montserrat layout, responsive navigation and accessible review controls. Discover routes with `php artisan route:list --path=financial-settings`.

### Phase 4 file inventory and validation

Created:
- `app/Http/Controllers/FinancialSettingsController.php`
- `app/Support/FinancialPolicyWorkflow.php`, `app/Support/FinancialRuleSchema.php`
- `app/Models/CommitteeMembershipChange.php`
- `app/Console/Commands/ActivateFinancialPolicies.php`
- `database/migrations/2026_10_21_080330_extend_financial_governance.php`
- `resources/views/clubs/financial-settings.blade.php`, `financial-proposal.blade.php`, `financial-summary.blade.php`
- `tests/Feature/FinancialGovernanceTest.php`

Modified:
- `app/Http/Controllers/ClubGovernanceController.php`
- `app/Models/FinancialRuleProposal.php`, `ClubPolicyVersion.php`, `PlatformActivity.php`
- `app/Support/ClubMembershipWorkflow.php`, `app/Providers/AppServiceProvider.php`
- `routes/web.php`, `routes/console.php`
- `resources/views/layouts/app.blade.php`, `resources/views/club.blade.php`, `resources/views/clubs/dashboard.blade.php`, `resources/views/admin/clubs/governance.blade.php`
- `resources/css/app.css`, `tests/Feature/ClubWorkspaceTest.php`, `README.md`

Automated validation: **136 tests, 1,237 assertions passed** using guarded SQLite `:memory:` storage. All 101 previous tests remain, with Phase 3 expectations updated for the intentional new lifecycle and committee designation rules. Coverage includes decimal validation, independent decisions, scheduling/idempotency, isolation, immutability, database uniqueness, stale/overlapping changes, committee eligibility/conflicts/quorum, audit rollback, future loan guards and upgrade preservation. Assets build successfully. Browser checks passed at 1440, 390 and 320 pixels, including rejection validation and confirmation/cancel/submission, with no JavaScript errors.

No known blocking issues. Deployment requires an operating Laravel scheduler. Actual financial transactions, share payments, savings withdrawals, loans, repayments, dividends and bank/API integration remain outside this phase.

## Phase 3 - Club Administrator workflow

The Phase 3 migration is already applied locally. For another checkout, run the normal migration and asset build before using the new routes:

```powershell
php artisan migrate --no-interaction
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
```

1. **Assign the Club Administrator:** sign in as an existing System Administrator, open **Clubs -> club details**, and assign an existing account or securely invite a new Club Administrator using the Phase 2 setup process. This still creates a pending financial membership with explicit administrative-onboarding access. No activation or contribution is invented.
2. **Open the club workspace:** sign out and sign in as that Club Administrator. Complete account setup or mandatory temporary-password change if required. A single authorised club opens automatically; with several clubs, select the desired club. Active administrators land at `/clubs/{club}/admin`; pending administrators retain the compatible `/clubs/{club}/onboarding` route, which now serves the scoped administration dashboard.
3. **Inspect the dashboard:** real counts distinguish all registered membership records, active financial memberships, approved admissions awaiting financial activation and pending join requests. Leadership, recent club activity and administrative setup checks also come from this club's records. Setup completion does not verify contributions.
4. **Register a new member:** choose **Members -> Add or invite member -> Register a new account**. Enter full name, international phone, optional email, a strong temporary password and confirmation. Deliver temporary credentials privately. They are not redisplayed or logged. The new account must choose a different password on first login. It has approved administrative admission but remains pending financial activation.
5. **Reuse an existing user:** choose **Invite an existing Barihafi user** and provide the registered phone. No duplicate account is created, no password is requested, and the existing account's password is never changed. Deliver the seven-day private invitation link securely. The intended recipient can also accept the phone-bound invitation from their own workspace. Acceptance submits admission review and leaves financial membership pending.
6. **Review join requests:** share the private joining link from **Join requests** or **Club settings**. An applicant can use the link/code without browsing other clubs. Review the club's queue, confirm approval or rejection, and provide a rejection reason. Approval connects/creates pending financial membership; it does not activate it. Request history records submission and decisions. Duplicate pending submissions do not duplicate requests or notices; rejected requests can be resubmitted. Suspended/revoked memberships are not restored through joining.
7. **Manage members:** search/filter/paginate by name/phone/email, financial membership state, role and admission. Open a profile to inspect this club's roles and separate account/admission/membership states. Permitted financial membership changes are pending, suspended, revoked or unchanged. There is no active-membership option. Changing financial membership does not rewrite the admission decision or a global user account.
8. **Configure contact and payment instructions:** **Club settings** edits name, description, contact phone/email and address. Add/edit bank destinations (bank name, account name/number) or Mobile Money destinations (provider, normalized number, account holder). Active destinations are visible to active members and authorised Club Administrators, including explicit administrative onboarding. Ordinary pending applicants cannot read payment instructions. Numbers are encrypted at rest using Laravel's application key and omitted from model serialization and activity metadata. Preserve `APP_KEY` and its backups when deploying or rotating encryption keys.
9. **Manage the private joining code:** view/copy the code/link in settings. Regenerating it requires the current password and confirmation, invalidating previous joining links. Individually issued invitation tokens are separate and remain governed by their own expiry/acceptance rules. The public `BHF-...` club identifier is never a joining credential.
10. **Read notifications:** the bell and **Notifications** page show only notices addressed to the signed-in account. Admission submissions/decisions, invitations, membership changes and governance reviews produce persisted in-app notifications. **My join requests** shows only the applicant's own decisions and reasons. There is no public club directory or cross-club notification feed.

## Phase 3 - Role and financial-governance rules

- Every leadership role retains Member capabilities. Roles remain scoped to one membership and may differ across clubs.
- By default, a Club Administrator may assign **Member** and **Committee Member** to other users. President and Accountant changes require explicit platform delegation. Club Administrator assignment remains reserved to the platform.
- System Administrators open **Clubs -> club details -> Manage club governance** to configure delegated roles, assign sensitive roles to existing club memberships, and assign eligible Committee Member roles. Designations now require the Phase 4 independent club approval workflow. These operations cannot activate financial membership.
- A Club Administrator cannot use the club endpoint to change reserved roles, grant themselves new privileged roles, modify another club, or edit governance delegation through ordinary club settings. The backend checks the change from previous roles, retaining reserved assignments the caller cannot change. Platform-authorised role changes use separate guarded routes and are audited.
- A pending President explicitly assigned through authorised governance may receive `has_governance_access` for policy review only. This allows governance pages, not member financial dashboards, payment instructions or member directories. Ordinary pending applicants receive neither administrative nor President-review onboarding access.
- **Club Admin proposes; an independent President reviews.** Proposals specify share price, minimum membership shares, loan multiplier, interest rate/method, loan duration, repayment frequency and savings withdrawal notice. Blank fields retain current approved specifications. Validation checks values but does not calculate financial amounts.
- Proposed specifications do not overwrite approved policy. Independent approval appends an immutable policy version with reviewer, proposal and approval time; rejection requires a reason. A dual-role Admin/President cannot review their own proposal. Stale proposals cannot replace a newer approved baseline, and repeated decisions cannot create duplicate versions. There is no direct approved-policy edit/delete endpoint.
- Committee approver designation is preparation only. Actual loans, leadership financial requests, committee financial decisions, share verification, interest calculations, repayments and financial transactions are not implemented. Approved specifications are recorded but not executed or used to activate membership in this phase.

## Phase 3 - Browser identity

`public/favicon.svg` is an original orange stylised B on a dark rounded square using `#E98A2A` and `#17191D`. The empty default icon was replaced with a matching custom 16px/32px ICO fallback. The shared layout references both through Laravel `asset()` URLs. No external logo or image service is used.

Default title: **Barihafi | Saving Club**. Named pages use meaningful titles such as **Dashboard | Barihafi**, **Members | Barihafi**, **Settings | Barihafi** and **Governance | Barihafi**. Login, recovery, password-change, platform and club pages share this browser identity. A cached browser icon may need a hard reload after deployment.

## Phase 3 - Migration and routes

Migration: `database/migrations/2026_10_17_181128_add_club_workspace_and_governance.php`.

- Adds club contact email/address and platform-controlled role delegation.
- Adds separate membership admission state, President-review onboarding and designated-committee flags. Existing financial statuses and activation dates are preserved. Existing pending admission requests are marked for review without fabricating historical events.
- Adds request reviewer/time/rejection reason and `club_join_request_events`.
- Creates `club_notifications`, encrypted-number `club_payment_destinations`, `financial_rule_proposals` and immutable `club_policy_versions`.
- Uses foreign keys, unique club/user membership/request constraints from previous migrations, and a unique club/version policy index. The migration was applied with normal `migrate`, never a development reset.

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/clubs/{club}/admin` | Scoped administrator dashboard |
| GET | `/clubs/{club}/onboarding` | Compatible pending-administrator dashboard |
| GET | `/clubs/{club}/members` | Member search/filter/pagination |
| GET / POST | `/clubs/{club}/members/create` / `/clubs/{club}/members` | Assisted account registration or existing-user invitation |
| GET / PATCH | `/clubs/{club}/members/{membership}` | Club profile / permitted roles and status |
| GET | `/clubs/{club}/requests` | Admission queue and history |
| POST | `/clubs/{club}/requests/{joinRequest}/review` | Confirmed admission decision |
| GET | `/my-requests` | Applicant's own request histories |
| POST | `/club-invitations/{invitation}/accept` | Authenticated phone-bound invitation acceptance |
| GET / PATCH | `/clubs/{club}/settings` | Club profile and payment-destination settings |
| POST | `/clubs/{club}/settings/join-code` | Confirmed joining-code rotation |
| POST | `/clubs/{club}/payment-destinations` | Create instructions-only destination |
| PATCH | `/clubs/{club}/payment-destinations/{destination}` | Edit or activate/deactivate destination |
| GET | `/clubs/{club}/payments` | Authorised active payment instructions |
| GET | `/clubs/{club}/activities` | Club-scoped administrative audit history |
| GET | `/clubs/{club}/governance` | Proposed/approved policy specifications and history |
| POST | `/clubs/{club}/governance/proposals` | Club Admin proposal |
| POST | `/clubs/{club}/governance/proposals/{proposal}/review` | Independent President decision |
| GET / PATCH | `/admin/clubs/{club}/governance` | Platform-controlled delegation and committee designation |
| PATCH | `/admin/clubs/{club}/memberships/{membership}/roles` | Platform-authorised club roles |
| GET | `/notifications` | Personal in-app notifications |
| PATCH | `/notifications/{notification}/read` | Mark own notification as read |

Existing authentication, platform, private code-based joining, raw invitation-link acceptance and mandatory password-change routes remain supported. All club-owned record parameters are checked against the authorised parent club; suspended memberships, accounts and clubs remain blocked. Password-change protection is not bypassed.

## Phase 3 - Files and validation

Created:

- Controllers: `app/Http/Controllers/ClubWorkspaceController.php`, `ClubMemberController.php`, `ClubJoinRequestController.php`, `ClubSettingsController.php`, `ClubGovernanceController.php`, `ClubNotificationController.php`.
- Service: `app/Support/ClubMembershipWorkflow.php`.
- Models: `app/Models/ClubJoinRequestEvent.php`, `ClubNotification.php`, `ClubPaymentDestination.php`, `FinancialRuleProposal.php`, `ClubPolicyVersion.php`.
- Factories: `database/factories/ClubPaymentDestinationFactory.php`, `FinancialRuleProposalFactory.php`.
- The Phase 3 migration and `tests/Feature/ClubWorkspaceTest.php`.
- Views under `resources/views/clubs/`: dashboard, member index/create/profile, requests, settings, payments, governance, activity history and the payment-form partial. Also `resources/views/admin/clubs/governance.blade.php`, `my-requests.blade.php`, `notifications.blade.php`.
- `public/favicon.svg` and the replacement `public/favicon.ico` fallback.

Updated:

- `app/Http/Controllers/ClubController.php` and `JoiningController.php` to use the new scoped workflows and preserve existing routes.
- `app/Models/Club.php`, `ClubMembership.php`, `ClubJoinRequest.php`, `User.php`, `PlatformActivity.php`; `app/Policies/ClubPolicy.php` and `app/Providers/AppServiceProvider.php`.
- `routes/web.php`; shared layout, active-member overview, club selector and platform club-details views; `resources/css/app.css` and this README.
- Phase 1 role tests now explicitly configure platform delegation when assigning sensitive roles. The Phase 2 pending-administrator test now verifies expanded administrative access while continuing to deny the active-member financial page.

Validation commands include:

```powershell
php artisan test --compact
php vendor/bin/pint --dirty --format agent
npm.cmd run build
php artisan view:cache --no-interaction
php artisan route:list --path=clubs --no-interaction
php artisan migrate:status --no-interaction
git diff --check
```

PHPUnit continues to use guarded SQLite `:memory:` storage; no automated test refreshes the development MySQL database. Tests cover club isolation, account creation/uniqueness/password preservation, required temporary-password replacement, pending financial membership, code/invitation admission, approval/rejection/history, notifications privacy, role delegation and self-escalation prevention, payment-destination validation/encryption/permissions, joining-code rotation, immutable independent policy review, stale decisions, rollback and favicon/titles, plus all previous authentication and platform tests. Final suite result: **101 tests passed, 947 assertions**.

Headless Edge checks use rendered isolated test fixtures, built local assets and a loopback request-capture server. They validate desktop and 390px/320px layouts, mobile member/settings screens, password-free existing-user invitation forms, bank/Mobile Money field controls, admission confirmation/cancellation/submission, governance forms and favicon/titles with no JavaScript errors. They do not log into or write to development accounts. Optional screenshots are in ignored `storage/app/phase3-review/`. Set `BARIHAFI_CLUB_REVIEW_ARTIFACTS=1` only while running `test_all_club_workspace_views_render_and_can_export_isolated_browser_fixtures` to regenerate fixture HTML; normal tests create no visual-review artifacts.

No new package dependencies were required. Existing PHP/Composer/framework versions, migrations, models, routes, authentication, middleware and permission logic were inspected before changes. Artisan scaffolding commands were used for the new migration, models/factories, controllers, workflow class and test. Formatting, route inspection, template compilation, build and normal MySQL migration were run. There are no known blocking issues in the implemented workflow. Financial execution, contribution verification/activation, actual loan/committee approvals and outbound notification/invitation delivery remain explicitly deferred.

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
- Active club memberships can open member dashboards. A pending applicant cannot. Assigned pending Club Administrators may have the separate `has_onboarding_access` permission: this opens their own administrative dashboard, member/admission management, settings and governance proposals. It does not open the active-member financial workspace or activate financial membership.
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

Montserrat is bundled locally with Tailwind and Alpine. The responsive layout includes sidebar navigation, personal in-app notifications, profile menu, loading states, keyboard-accessible confirmation dialogs, empty/error/success states and horizontally scrollable mobile data tables/charts. Default currency is RWF; no conversion, ledger or financial-policy logic is attached to that display preference.

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

PHPUnit forces isolated SQLite `:memory:` storage, and `Tests\TestCase` refuses any other database before database refresh traits execute. Development continues to use MySQL `barihafi`. Tests cover all requested Phase 2 cases plus directory filters/pagination, settings/profile security, invitation expiry/reissue/single use, revoked membership protection, account suspension, audit metadata filtering, legacy route protections and successful rendering of every admin screen. Authentication and initial-admin regression tests remain included. Phase 2 baseline: **72 tests passed, 672 assertions**. The current Phase 3 result is recorded below.

Headless Edge review uses HTML generated from isolated test fixtures, with built local assets and a local request-capture server; it never signs into or writes to the development database. Review checks desktop/mobile overflow, mobile menu, new-administrator field visibility, form submission and confirmation cancellation/submission. Screenshots are in ignored `storage/app/phase2-review/`. To regenerate render fixtures, temporarily set `BARIHAFI_REVIEW_ARTIFACTS=1` while running the `test_administration_pages_render_with_consistent_navigation` test; normal test runs create no review artifacts.

Setup commands included existing dependency/version checks and Artisan `make:migration`, `make:model --factory`, `make:class`, `make:controller`, `make:middleware` and `make:test --phpunit`. Laravel Boost was already installed and generated guidelines were followed. Phase 2 required no dependency installation or destructive database operation.

## Remaining boundaries

Financial verification and financial membership activation remain unimplemented. There are no shares, savings, loans, repayments, bank integrations, dividends or ledger entries. Personal in-app notifications are available; outbound email/SMS notification delivery is not implemented. Setup/invitation delivery is private and manual; email/SMS delivery and identity verification integrations are not included. Audit records cover implemented administrative operations, not an external tamper-evident logging service. Financial rules can be specified through independently approved policy versions; calculation, enforcement and financial-user-record editing remain outside this phase.
