<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubPolicyVersion;
use App\Models\CommitteeMembershipChange;
use App\Models\FinancialRuleProposal;
use App\Models\PlatformActivity;
use App\Models\User;
use App\Support\FinancialPolicyWorkflow;
use App\Support\FinancialRuleSchema;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private User $admin;

    private User $president;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->club = Club::factory()->create();
        $this->admin = $this->person(['Club Administrator']);
        $this->president = $this->person(['President']);
    }

    private function person(array $roles, ?Club $club = null): User
    {
        $user = User::factory()->create();
        ($club ?? $this->club)->memberships()->create(['user_id' => $user->id, 'roles' => $roles, 'status' => 'active', 'admission_status' => 'approved', 'joined_at' => now()]);

        return $user;
    }

    private function data(array $rules = [], int $base = 0): array
    {
        return ['base_version' => $base, 'rules' => array_replace(['share_price' => '50000.00'], $rules), 'rationale' => 'Club members require clear and independently reviewed financial terms.', 'effective_at' => now()->format('Y-m-d H:i:s')];
    }

    private function draft(array $rules = [], int $base = 0, ?string $date = null): FinancialRuleProposal
    {
        $data = $this->data($rules, $base);
        $data['effective_at'] = $date ?? $data['effective_at'];
        $this->actingAs($this->admin)->post(route('clubs.financial.proposals.store', $this->club), $data)->assertSessionHasNoErrors()->assertRedirect();

        return $this->club->ruleProposals()->latest('id')->firstOrFail();
    }

    private function submit(FinancialRuleProposal $proposal): void
    {
        $this->actingAs($this->admin)->post(route('clubs.financial.proposals.submit', [$this->club, $proposal]))->assertSessionHasNoErrors();
    }

    private function approve(FinancialRuleProposal $proposal): void
    {
        $this->actingAs($this->president)->post(route('clubs.financial.proposals.review', [$this->club, $proposal]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
    }

    private function baseline(): FinancialRuleProposal
    {
        $proposal = $this->draft();
        $this->submit($proposal);
        $this->approve($proposal);

        return $proposal;
    }

    public function test_draft_submission_and_independent_baseline_approval_preserve_complete_decimal_snapshot(): void
    {
        $proposal = $this->draft(['partial_capital_loan_eligible' => false, 'interest_period' => 'annual', 'interest_method' => 'reducing_balance', 'interest_rate' => '12.1250']);
        $this->assertSame('draft', $proposal->status);
        $this->assertNull($proposal->submitted_at);
        $this->assertSame([], $proposal->existing_rules);
        $this->assertTrue($proposal->is_baseline);
        $this->assertDatabaseCount('club_policy_versions', 0);
        $this->submit($proposal);
        $this->assertSame('submitted', $proposal->fresh()->status);
        $this->assertNotNull($proposal->fresh()->submitted_at);
        $this->assertDatabaseCount('club_policy_versions', 0);
        $this->approve($proposal);
        $version = ClubPolicyVersion::sole();
        $this->assertSame('effective', $proposal->fresh()->status);
        $this->assertSame($this->club->id, $version->active_club_id);
        $this->assertCount(count(FinancialRuleSchema::fields()), $version->rules);
        $this->assertSame('50000.00', $version->rules['share_price']);
        $this->assertSame('12.1250', $version->rules['interest_rate']);
        $this->assertSame('annual', $version->rules['interest_period']);
        $this->assertSame('reducing_balance', $version->rules['interest_method']);
        $this->assertFalse($version->rules['partial_capital_loan_eligible']);
        $this->assertDatabaseHas('club_notifications', ['user_id' => $this->president->id, 'kind' => 'rules_proposed']);
        $this->assertDatabaseHas('platform_activities', ['action' => 'club.policy_effective', 'club_id' => $this->club->id]);
    }

    public static function invalidPrices(): array
    {
        return array_map(fn ($value) => [$value], ['0', '-1', '1.001', '1e3', '100000000000.00', 'not money']);
    }

    #[DataProvider('invalidPrices')]
    public function test_share_price_rejects_invalid_precision_range_or_format(string $price): void
    {
        $this->actingAs($this->admin)->post(route('clubs.financial.proposals.store', $this->club), $this->data(['share_price' => $price]))->assertSessionHasErrors('share_price');
        $this->assertDatabaseCount('financial_rule_proposals', 0);
    }

    public static function invalidMultipliers(): array
    {
        return array_map(fn ($value) => [$value], ['0', '-2', '101', '1.00001', '2e0']);
    }

    #[DataProvider('invalidMultipliers')]
    public function test_multiplier_rejects_unsafe_values(string $multiplier): void
    {
        $this->actingAs($this->admin)->post(route('clubs.financial.proposals.store', $this->club), $this->data(['loan_multiplier' => $multiplier]))->assertSessionHasErrors('loan_multiplier');
    }

    public function test_invalid_savings_duration_interest_and_quorum_settings_are_rejected(): void
    {
        foreach ([['savings_withdrawal_approval_required' => false], ['interest_period' => 'daily'], ['interest_method' => 'compound'], ['committee_quorum' => 1], ['committee_quorum' => 4], ['minimum_duration_months' => 15], ['activation_threshold' => 1, 'minimum_membership_shares' => 2], ['maximum_lendable_percent' => '100.01'], ['minimum_loan_amount' => '200.01', 'maximum_loan_amount' => '200.00'], ['savings_frequency' => 'custom'], ['system_admin' => true]] as $rules) {
            $this->actingAs($this->admin)->post(route('clubs.financial.proposals.store', $this->club), $this->data($rules))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('financial_rule_proposals', 0);
    }

    public function test_each_club_keeps_independent_policy_and_foreign_routes_are_blocked(): void
    {
        $this->baseline();
        $other = Club::factory()->create();
        $foreignAdmin = $this->person(['Club Administrator'], $other);
        $foreignPresident = $this->person(['President'], $other);
        $this->actingAs($foreignAdmin)->post(route('clubs.governance.propose', $other), $this->data(['share_price' => '12000.50']))->assertSessionHasNoErrors();
        $proposal = $other->ruleProposals()->sole();
        $this->actingAs($foreignPresident)->post(route('clubs.financial.proposals.review', [$other, $proposal]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('50000.00', app(FinancialPolicyWorkflow::class)->current($this->club)->rules['share_price']);
        $this->assertSame('12000.50', app(FinancialPolicyWorkflow::class)->current($other)->rules['share_price']);
        $this->actingAs($this->admin)->post(route('clubs.financial.proposals.store', $other), $this->data())->assertForbidden();
        $this->actingAs($this->president)->get(route('clubs.financial.proposals.show', [$this->club, $proposal]))->assertNotFound();
        $this->post(route('clubs.financial.proposals.review', [$this->club, $proposal]), ['decision' => 'approved', 'confirmed' => 1])->assertNotFound();
    }

    public function test_future_policy_is_scheduled_then_activated_once_and_history_is_preserved(): void
    {
        $this->baseline();
        $old = ClubPolicyVersion::sole();
        $proposal = $this->draft(['share_price' => '70000.00'], 1, now()->addDays(2)->format('Y-m-d H:i:s'));
        $this->submit($proposal);
        $this->approve($proposal);
        $this->assertSame('scheduled', $proposal->fresh()->status);
        $this->assertSame($old->id, app(FinancialPolicyWorkflow::class)->current($this->club)->id);
        $this->artisan('barihafi:activate-policies')->expectsOutput('0 policy version(s) activated.')->assertSuccessful();
        $this->travel(3)->days();
        $this->artisan('barihafi:activate-policies')->expectsOutput('1 policy version(s) activated.')->assertSuccessful();
        $this->artisan('barihafi:activate-policies')->expectsOutput('0 policy version(s) activated.')->assertSuccessful();
        $this->assertSame('effective', $proposal->fresh()->status);
        $this->assertSame('superseded', FinancialRuleProposal::find($old->financial_rule_proposal_id)->status);
        $this->assertSame('50000.00', $old->fresh()->rules['share_price']);
        $this->assertNotNull($old->fresh()->effective_to);
        $this->assertSame(1, $this->club->policyVersions()->whereNotNull('active_club_id')->count());
        $this->assertSame('70000.00', app(FinancialPolicyWorkflow::class)->current($this->club)->rules['share_price']);
        $this->assertSame('50000.00', $proposal->existing_rules['share_price']);
    }

    public function test_suspended_club_cannot_mutate_or_activate_due_settings(): void
    {
        $proposal = $this->draft([], 0, now()->addDay()->format('Y-m-d H:i:s'));
        $this->submit($proposal);
        $this->approve($proposal);
        $this->club->update(['status' => 'suspended']);
        $this->actingAs($this->admin)->post(route('clubs.financial.proposals.store', $this->club), $this->data())->assertForbidden();
        $this->travel(2)->days();
        $this->artisan('barihafi:activate-policies')->expectsOutput('0 policy version(s) activated.')->assertSuccessful();
        $this->assertNull(app(FinancialPolicyWorkflow::class)->current($this->club));
        $this->assertSame('scheduled', $proposal->fresh()->status);
    }

    public function test_self_approval_and_platform_or_member_overrides_are_forbidden(): void
    {
        $proposal = $this->draft();
        $this->submit($proposal);
        $own = $this->club->memberships()->where('user_id', $this->admin->id)->sole();
        $own->update(['roles' => ['Club Administrator', 'President']]);
        $platform = User::factory()->create(['is_system_admin' => true]);
        $member = $this->person(['Member']);
        foreach ([$this->admin, $platform, $member] as $actor) {
            $this->actingAs($actor)->post(route('clubs.financial.proposals.review', [$this->club, $proposal]), ['decision' => 'approved', 'confirmed' => 1])->assertForbidden();
        }
        $this->assertDatabaseCount('club_policy_versions', 0);
        $this->actingAs($member)->get(route('clubs.financial.index', $this->club))->assertOk()->assertDontSee('Save draft');
        $this->get(route('clubs.financial.page', [$this->club, 'create']))->assertForbidden();
    }

    public function test_submission_is_blocked_without_an_independent_president(): void
    {
        $this->club->memberships()->where('user_id', $this->president->id)->update(['status' => 'suspended']);
        $proposal = $this->draft();
        $this->actingAs($this->admin)->post(route('clubs.financial.proposals.submit', [$this->club, $proposal]))->assertSessionHasErrors('proposal');
        $this->assertSame('draft', $proposal->fresh()->status);
        $this->get(route('clubs.financial.proposals.show', [$this->club, $proposal]))->assertSee('No independent authorised President');
    }

    public function test_president_rejection_requires_comments_and_does_not_create_a_version(): void
    {
        $proposal = $this->draft();
        $this->submit($proposal);
        $this->actingAs($this->president)->post(route('clubs.financial.proposals.review', [$this->club, $proposal]), ['decision' => 'rejected', 'confirmed' => 1])->assertSessionHasErrors('reason');
        $this->post(route('clubs.financial.proposals.review', [$this->club, $proposal]), ['decision' => 'rejected', 'confirmed' => 1, 'reason' => 'Revise the loan duration.'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $proposal->fresh()->status);
        $this->assertSame('Revise the loan duration.', $proposal->fresh()->review_reason);
        $this->assertDatabaseCount('club_policy_versions', 0);
    }

    public function test_overlapping_submissions_stale_drafts_and_duplicate_decisions_are_blocked(): void
    {
        $first = $this->draft();
        $second = $this->draft(['share_price' => '20000.00']);
        $this->submit($first);
        $this->actingAs($this->admin)->post(route('clubs.financial.proposals.submit', [$this->club, $second]))->assertSessionHasErrors('proposal');
        $this->approve($first);
        $this->actingAs($this->admin)->post(route('clubs.financial.proposals.submit', [$this->club, $second]))->assertSessionHasErrors('base_version');
        $this->actingAs($this->president)->post(route('clubs.financial.proposals.review', [$this->club, $first]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasErrors('decision');
        $this->assertDatabaseCount('club_policy_versions', 1);
    }

    public function test_scheduled_change_blocks_a_conflicting_submission_and_stale_approval(): void
    {
        $this->baseline();
        $first = $this->draft([], 1, now()->addDay()->format('Y-m-d H:i:s'));
        $second = $this->draft(['share_price' => '1.00'], 1);
        $this->submit($first);
        $this->approve($first);
        $this->actingAs($this->admin)->post(route('clubs.financial.proposals.submit', [$this->club, $second]))->assertSessionHasErrors('proposal');
        $second->update(['status' => 'submitted']);
        $this->actingAs($this->president)->post(route('clubs.financial.proposals.review', [$this->club, $second]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasErrors('decision');
    }

    public function test_only_the_proposer_may_cancel_a_draft_and_cancelled_proposal_cannot_be_approved(): void
    {
        $proposal = $this->draft();
        $other = $this->person(['Club Administrator']);
        $this->actingAs($other)->post(route('clubs.financial.proposals.cancel', [$this->club, $proposal]))->assertForbidden();
        $this->actingAs($this->admin)->post(route('clubs.financial.proposals.cancel', [$this->club, $proposal]))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $proposal->fresh()->status);
        $this->actingAs($this->president)->post(route('clubs.financial.proposals.review', [$this->club, $proposal]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasErrors('decision');
    }

    public function test_approved_snapshot_cannot_be_updated_or_deleted(): void
    {
        $this->baseline();
        $version = ClubPolicyVersion::sole();
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $version->update(['rules' => ['share_price' => '1.00']]) : $version->delete();
                $this->fail('Approved version mutation must be denied.');
            } catch (\LogicException $error) {
                $this->assertStringContainsString('Approved policy', $error->getMessage());
            }
        }
        $this->assertSame('50000.00', $version->fresh()->rules['share_price']);
    }

    public function test_database_constraint_prevents_multiple_active_versions(): void
    {
        $this->baseline();
        $proposal = $this->draft([], 1);
        $this->expectException(QueryException::class);
        DB::table('club_policy_versions')->insert(['club_id' => $this->club->id, 'version' => 2, 'financial_rule_proposal_id' => $proposal->id, 'rules' => '{}', 'approved_by' => $this->president->id, 'approved_at' => now(), 'active_club_id' => $this->club->id]);
    }

    public function test_committee_nomination_is_scoped_and_requires_independent_approval(): void
    {
        $candidate = $this->person(['Committee Member']);
        $membership = $this->club->memberships()->where('user_id', $candidate->id)->sole();
        $other = Club::factory()->create();
        $foreign = $this->person(['Committee Member'], $other);
        $foreignMembership = $other->memberships()->where('user_id', $foreign->id)->sole();
        $this->actingAs($this->admin)->post(route('clubs.financial.committee.nominate', $this->club), ['club_membership_id' => $foreignMembership->id, 'operation' => 'appoint', 'reason' => 'Nominate'])->assertSessionHasErrors('club_membership_id');
        $this->post(route('clubs.financial.committee.nominate', $this->club), ['club_membership_id' => $membership->id, 'operation' => 'appoint', 'reason' => 'An experienced independent member.'])->assertSessionHasNoErrors();
        $change = CommitteeMembershipChange::sole();
        $this->assertFalse($membership->fresh()->is_committee_approver);
        $this->actingAs($candidate)->post(route('clubs.financial.committee.review', [$this->club, $change]), ['decision' => 'approved', 'confirmed' => 1])->assertForbidden();
        $this->actingAs($this->president)->post(route('clubs.financial.committee.review', [$this->club, $change]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($membership->fresh()->is_committee_approver);
        $this->assertSame($this->president->id, $change->fresh()->reviewed_by);
        $this->assertDatabaseHas('platform_activities', ['action' => 'club.committee_approved', 'club_id' => $this->club->id]);
        $this->actingAs($this->admin)->post(route('clubs.financial.committee.nominate', $this->club), ['club_membership_id' => $membership->id, 'operation' => 'remove', 'reason' => 'Rotation at end of term.'])->assertSessionHasNoErrors();
        $remove = CommitteeMembershipChange::latest('id')->first();
        $this->assertTrue($membership->fresh()->is_committee_approver);
        $this->actingAs($this->president)->post(route('clubs.financial.committee.review', [$this->club, $remove]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertFalse($membership->fresh()->is_committee_approver);
    }

    public function test_president_cannot_approve_own_appointment_even_when_another_admin_nominates(): void
    {
        $membership = $this->club->memberships()->where('user_id', $this->president->id)->sole();
        $membership->update(['roles' => ['Committee Member', 'President']]);
        $second = $this->person(['President']);
        $this->actingAs($this->admin)->post(route('clubs.financial.committee.nominate', $this->club), ['club_membership_id' => $membership->id, 'operation' => 'appoint', 'reason' => 'Nomination'])->assertSessionHasNoErrors();
        $change = CommitteeMembershipChange::sole();
        $this->actingAs($this->president)->post(route('clubs.financial.committee.review', [$this->club, $change]), ['decision' => 'approved', 'confirmed' => 1])->assertForbidden();
        $this->actingAs($second)->post(route('clubs.financial.committee.review', [$this->club, $change]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
    }

    public function test_committee_quorum_counts_distinct_eligible_nonconflicted_people_and_fails_closed(): void
    {
        $this->baseline();
        $workflow = app(FinancialPolicyWorkflow::class);
        $this->assertFalse($workflow->committeeReadiness($this->club)['available']);
        $ids = [];
        for ($index = 0; $index < 3; $index++) {
            $person = $this->person(['Committee Member']);
            $membership = $this->club->memberships()->where('user_id', $person->id)->sole();
            $this->actingAs($this->admin)->post(route('clubs.financial.committee.nominate', $this->club), ['club_membership_id' => $membership->id, 'operation' => 'appoint', 'reason' => 'Independent service.'])->assertSessionHasNoErrors();
            $change = CommitteeMembershipChange::latest('id')->first();
            $this->actingAs($this->president)->post(route('clubs.financial.committee.review', [$this->club, $change]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
            $ids[] = $person->id;
        }
        $this->assertTrue($workflow->committeeReadiness($this->club, [$ids[0]])['available']);
        $this->assertFalse($workflow->committeeReadiness($this->club, [$ids[0], $ids[1]])['available']);
        User::whereKey($ids[2])->update(['account_status' => 'suspended']);
        $this->assertFalse($workflow->committeeReadiness($this->club)['available']);
    }

    public function test_committee_review_rechecks_eligibility_and_duplicate_decisions(): void
    {
        $candidate = $this->person(['Committee Member']);
        $membership = $this->club->memberships()->where('user_id', $candidate->id)->sole();
        $this->actingAs($this->admin)->post(route('clubs.financial.committee.nominate', $this->club), ['club_membership_id' => $membership->id, 'operation' => 'appoint', 'reason' => 'Nomination'])->assertSessionHasNoErrors();
        $change = CommitteeMembershipChange::sole();
        $membership->update(['status' => 'suspended']);
        $this->actingAs($this->president)->post(route('clubs.financial.committee.review', [$this->club, $change]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasErrors('decision');
        $this->post(route('clubs.financial.committee.review', [$this->club, $change]), ['decision' => 'rejected', 'reason' => 'Eligibility changed.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->post(route('clubs.financial.committee.review', [$this->club, $change]), ['decision' => 'rejected', 'reason' => 'Again.', 'confirmed' => 1])->assertSessionHasErrors('decision');
    }

    public function test_policy_approval_rolls_back_when_audit_storage_fails(): void
    {
        $proposal = $this->draft();
        $this->submit($proposal);
        $event = 'eloquent.creating: '.PlatformActivity::class;
        Event::listen($event, function (PlatformActivity $activity): void {
            if ($activity->action === 'club.policy_effective') {
                throw new \RuntimeException('Audit unavailable');
            }
        });
        $this->withoutExceptionHandling();
        try {
            app(FinancialPolicyWorkflow::class)->review($this->president, $this->club, $proposal, ['decision' => 'approved']);
            $this->fail('Audit failure should roll back approval.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('club_policy_versions', 0);
        $this->assertSame('submitted', $proposal->fresh()->status);
    }

    public function test_future_loan_guard_rejects_outstanding_pending_and_undisbursed_states(): void
    {
        $workflow = app(FinancialPolicyWorkflow::class);
        $workflow->assertNoConcurrentLoan(false, false, false);
        foreach ([[true, false, false], [false, true, false], [false, false, true]] as $states) {
            try {
                $workflow->assertNoConcurrentLoan(...$states);
                $this->fail('Concurrent loan must be blocked.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('loan', $error->errors());
            }
        }
    }

    public function test_all_permissions_and_pages_render_with_real_dashboard_review_counts(): void
    {
        $this->baseline();
        $proposal = $this->draft(['loan_multiplier' => '2.50', 'interest_rate' => '1.25'], 1);
        $this->submit($proposal);
        $capture = (bool) getenv('BARIHAFI_FINANCIAL_REVIEW_ARTIFACTS');
        $routes = [];
        if ($capture) {
            $this->withVite();
            URL::forceRootUrl('http://127.0.0.1:8131');
            File::ensureDirectoryExists(storage_path('app/phase4-review'));
        }
        $this->actingAs($this->admin);
        foreach (['overview', 'shares', 'savings', 'loans', 'governance', 'create', 'pending', 'history', 'committee'] as $page) {
            $response = $this->get(route('clubs.financial.page', [$this->club, $page]))->assertOk();
            if ($capture) {
                file_put_contents(storage_path('app/phase4-review/'.$page.'.html'), $response->getContent());
                $routes[$page] = parse_url(route('clubs.financial.page', [$this->club, $page]), PHP_URL_PATH);
            }
        }
        $this->get(route('clubs.financial.proposals.show', [$this->club, $proposal]))->assertOk()->assertSee('Existing value')->assertSee('50000.00');
        $this->get(route('clubs.admin.dashboard', $this->club))->assertOk()->assertSee('Submitted: 1');
        $this->actingAs($this->president)->get(route('clubs.show', $this->club))->assertOk()->assertSee('Proposal #'.$proposal->id);
        if ($capture) {
            $response = $this->get(route('clubs.financial.proposals.show', [$this->club, $proposal]))->assertOk();
            file_put_contents(storage_path('app/phase4-review/proposal.html'), $response->getContent());
            $routes['proposal'] = parse_url(route('clubs.financial.proposals.show', [$this->club, $proposal]), PHP_URL_PATH);
            file_put_contents(storage_path('app/phase4-review/routes.json'), json_encode($routes));
        }
        foreach (['financial_settings.view', 'financial_settings.history', 'committee.view', 'financial_settings.approve'] as $permission) {
            $this->assertTrue(Gate::forUser($this->president)->allows($permission, $this->club));
        }
        $this->assertFalse(Gate::forUser($this->president)->allows('committee.manage', $this->club));
        $this->assertTrue(Gate::forUser($this->admin)->allows('committee.manage', $this->club));
    }

    public function test_committee_approval_guard_rejects_duplicate_conflicted_or_foreign_approvers(): void
    {
        $this->baseline();
        $ids = [];
        for ($index = 0; $index < 3; $index++) {
            $person = $this->person(['Committee Member']);
            $membership = $this->club->memberships()->where('user_id', $person->id)->sole();
            $this->actingAs($this->admin)->post(route('clubs.financial.committee.nominate', $this->club), ['club_membership_id' => $membership->id, 'operation' => 'appoint', 'reason' => 'Independent committee service.'])->assertSessionHasNoErrors();
            $change = CommitteeMembershipChange::latest('id')->first();
            $this->actingAs($this->president)->post(route('clubs.financial.committee.review', [$this->club, $change]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
            $ids[] = $person->id;
        }
        $workflow = app(FinancialPolicyWorkflow::class);
        $workflow->assertCommitteeApprovals($this->club, [$ids[0]], [$ids[1], $ids[2]]);
        foreach ([[$ids[1], $ids[1]], [$ids[0], $ids[1]], [$ids[1], $this->president->id]] as $approvers) {
            try {
                $workflow->assertCommitteeApprovals($this->club, [$ids[0]], $approvers);
                $this->fail('Invalid independent quorum must be blocked.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('committee', $error->errors());
            }
        }
    }

    public function test_optional_monetary_limits_can_be_cleared_without_float_conversion(): void
    {
        $first = $this->draft(['minimum_loan_amount' => '100.01', 'maximum_loan_amount' => '9999999999.99', 'minimum_savings_deposit' => '0.00']);
        $this->submit($first);
        $this->approve($first);
        $second = $this->draft(['minimum_loan_amount' => null, 'maximum_loan_amount' => null], 1);
        $this->submit($second);
        $this->approve($second);
        $rules = app(FinancialPolicyWorkflow::class)->current($this->club)->rules;
        $this->assertNull($rules['minimum_loan_amount']);
        $this->assertNull($rules['maximum_loan_amount']);
        $this->assertSame('0.00', $rules['minimum_savings_deposit']);
    }

    public function test_upgrade_preserves_legacy_snapshots_and_requires_independent_committee_reapproval(): void
    {
        $migration = require database_path('migrations/2026_10_21_080330_extend_financial_governance.php');
        $migration->down();
        $candidate = $this->person(['Committee Member']);
        DB::table('club_memberships')->where('user_id', $candidate->id)->update(['is_committee_approver' => true]);
        $proposalId = DB::table('financial_rule_proposals')->insertGetId(['club_id' => $this->club->id, 'proposed_by' => $this->admin->id, 'rules' => '{"share_price":"1234.50"}', 'base_version' => 0, 'status' => 'approved', 'rationale' => 'Legacy approved policy', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('club_policy_versions')->insert(['club_id' => $this->club->id, 'version' => 1, 'financial_rule_proposal_id' => $proposalId, 'rules' => '{"share_price":"1234.50"}', 'approved_by' => $this->president->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $migration->up();
        $version = ClubPolicyVersion::sole();
        $this->assertSame(['share_price' => '1234.50'], $version->rules);
        $this->assertSame($this->club->id, $version->active_club_id);
        $this->assertSame('effective', FinancialRuleProposal::findOrFail($proposalId)->status);
        $this->assertNotNull($version->effective_from);
        $this->assertFalse($this->club->memberships()->where('user_id', $candidate->id)->sole()->is_committee_approver);
        $this->assertDatabaseHas('committee_membership_changes', ['club_id' => $this->club->id, 'status' => 'legacy', 'reviewed_by' => null]);
        $this->assertDatabaseHas('platform_activities', ['club_id' => $this->club->id, 'action' => 'club.committee_legacy_review_required']);
    }

    public function test_designated_member_with_a_suspended_account_can_still_be_removed_by_independent_review(): void
    {
        $candidate = $this->person(['Committee Member']);
        $membership = $this->club->memberships()->where('user_id', $candidate->id)->sole();
        $this->actingAs($this->admin)->post(route('clubs.financial.committee.nominate', $this->club), ['club_membership_id' => $membership->id, 'operation' => 'appoint', 'reason' => 'Independent service.'])->assertSessionHasNoErrors();
        $change = CommitteeMembershipChange::sole();
        $this->actingAs($this->president)->post(route('clubs.financial.committee.review', [$this->club, $change]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
        $candidate->account_status = 'suspended';
        $candidate->save();
        $this->actingAs($this->admin)->get(route('clubs.financial.page', [$this->club, 'committee']))->assertOk()->assertViewHas('candidates', fn ($candidates) => $candidates->contains('id', $membership->id));
        $this->post(route('clubs.financial.committee.nominate', $this->club), ['club_membership_id' => $membership->id, 'operation' => 'remove', 'reason' => 'Account is no longer available.'])->assertSessionHasNoErrors();
        $remove = CommitteeMembershipChange::latest('id')->first();
        $this->actingAs($this->president)->post(route('clubs.financial.committee.review', [$this->club, $remove]), ['decision' => 'approved', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertFalse($membership->fresh()->is_committee_approver);
    }
}
