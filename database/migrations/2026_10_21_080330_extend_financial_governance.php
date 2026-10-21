<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_rule_proposals', function (Blueprint $table): void {
            $table->json('existing_rules')->nullable();
            $table->timestamp('effective_at')->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('is_baseline')->default(false);
        });
        Schema::table('club_policy_versions', function (Blueprint $table): void {
            $table->timestamp('effective_from')->nullable()->index();
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('active_club_id')->nullable()->unique()->constrained('clubs');
            $table->boolean('is_baseline')->default(false);
        });
        Schema::create('committee_membership_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained();
            $table->foreignId('club_membership_id')->constrained();
            $table->foreignId('proposed_by')->nullable()->constrained('users');
            $table->string('operation', 10);
            $table->string('status', 20)->default('submitted');
            $table->text('reason');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->text('review_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['club_id', 'status']);
        });
        DB::table('club_memberships')->where('is_committee_approver', true)->orderBy('id')->each(function (object $membership): void {
            $changeId = DB::table('committee_membership_changes')->insertGetId(['club_id' => $membership->club_id, 'club_membership_id' => $membership->id, 'operation' => 'remove', 'status' => 'legacy', 'reason' => 'Pre-Phase-4 preparatory designation requires independent club nomination and approval before financial authority can be granted.', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('club_memberships')->where('id', $membership->id)->update(['is_committee_approver' => false]);
            DB::table('platform_activities')->insert(['action' => 'club.committee_legacy_review_required', 'subject_type' => 'committee_change', 'subject_id' => $changeId, 'club_id' => $membership->club_id, 'changes' => json_encode(['status' => 'legacy']), 'created_at' => now(), 'updated_at' => now()]);
        });
        DB::table('financial_rule_proposals')->where('status', 'pending')->update(['status' => 'submitted']);
        DB::table('financial_rule_proposals')->orderBy('id')->each(function (object $proposal): void {
            $previous = DB::table('club_policy_versions')->where('club_id', $proposal->club_id)->where('version', $proposal->base_version)->first();
            DB::table('financial_rule_proposals')->where('id', $proposal->id)->update(['existing_rules' => $previous?->rules ?? '{}', 'submitted_at' => $proposal->created_at]);
        });
        DB::table('club_policy_versions')->orderBy('club_id')->orderBy('version')->get()->groupBy('club_id')->each(function ($versions): void {
            foreach ($versions as $index => $version) {
                $next = $versions->get($index + 1);
                DB::table('club_policy_versions')->where('id', $version->id)->update(['effective_from' => $version->approved_at, 'effective_to' => $next?->approved_at, 'active_club_id' => $next ? null : $version->club_id, 'is_baseline' => $index === 0]);
                DB::table('financial_rule_proposals')->where('id', $version->financial_rule_proposal_id)->update(['status' => $next ? 'superseded' : 'effective', 'effective_at' => $version->approved_at, 'is_baseline' => $index === 0]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_membership_changes');
        Schema::table('club_policy_versions', function (Blueprint $table): void {
            $table->dropUnique(['active_club_id']);
            $table->dropIndex(['effective_from']);
            $table->dropConstrainedForeignId('active_club_id');
            $table->dropColumn(['effective_from', 'effective_to', 'is_baseline']);
        });
        Schema::table('financial_rule_proposals', function (Blueprint $table): void {
            $table->dropIndex(['effective_at']);
            $table->dropColumn(['existing_rules', 'effective_at', 'submitted_at', 'is_baseline']);
        });
    }
};
