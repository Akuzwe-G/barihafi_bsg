<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table): void {
            $table->string('contact_email')->nullable();
            $table->string('address', 500)->nullable();
            $table->json('delegated_roles')->nullable();
        });
        Schema::table('club_memberships', function (Blueprint $table): void {
            $table->string('admission_status', 30)->default('approved');
            $table->boolean('has_governance_access')->default(false);
            $table->boolean('is_committee_approver')->default(false);
        });
        Schema::table('club_join_requests', function (Blueprint $table): void {
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });
        DB::table('club_join_requests')->where('status', 'pending')->orderBy('id')->each(function (object $request): void {
            DB::table('club_memberships')->where('club_id', $request->club_id)->where('user_id', $request->user_id)->where('status', 'pending')->where('has_onboarding_access', false)->update(['admission_status' => 'pending_review']);
        });
        Schema::create('club_join_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_join_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30);
            $table->text('reason')->nullable();
            $table->timestamps();
        });
        Schema::create('club_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 60);
            $table->string('message', 500);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });
        Schema::create('club_payment_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('label', 100);
            $table->string('bank_name', 150)->nullable();
            $table->string('account_name', 150)->nullable();
            $table->text('account_number')->nullable();
            $table->string('provider', 80)->nullable();
            $table->text('mobile_number')->nullable();
            $table->string('account_holder', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('financial_rule_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposed_by')->constrained('users');
            $table->json('rules');
            $table->unsignedInteger('base_version')->default(0);
            $table->string('status', 20)->default('pending');
            $table->text('rationale');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('club_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('financial_rule_proposal_id')->unique()->constrained();
            $table->json('rules');
            $table->foreignId('approved_by')->constrained('users');
            $table->timestamp('approved_at');
            $table->timestamps();
            $table->unique(['club_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_policy_versions');
        Schema::dropIfExists('financial_rule_proposals');
        Schema::dropIfExists('club_payment_destinations');
        Schema::dropIfExists('club_notifications');
        Schema::dropIfExists('club_join_request_events');
        Schema::table('club_join_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['reviewed_at', 'rejection_reason']);
        });
        Schema::table('club_memberships', fn (Blueprint $table) => $table->dropColumn(['admission_status', 'has_governance_access', 'is_committee_approver']));
        Schema::table('clubs', fn (Blueprint $table) => $table->dropColumn(['contact_email', 'address', 'delegated_roles']));
    }
};
