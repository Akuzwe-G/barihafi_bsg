<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_status', 20)->default('active')->index();
            $table->string('suspended_from_status', 20)->nullable();
            $table->text('suspension_reason')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at')->nullable();
        });
        Schema::table('clubs', function (Blueprint $table): void {
            $table->string('code', 24)->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('contact_phone', 16)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('suspension_reason')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at')->nullable();
        });
        DB::table('clubs')->orderBy('id')->each(function (object $club): void {
            DB::table('clubs')->where('id', $club->id)->update(['code' => 'BHF-'.str_pad((string) $club->id, 10, '0', STR_PAD_LEFT), 'status' => $club->is_active ? 'active' : 'inactive']);
        });
        Schema::table('clubs', fn (Blueprint $table) => $table->string('code', 24)->nullable(false)->change());
        Schema::table('club_memberships', function (Blueprint $table): void {
            $table->boolean('has_onboarding_access')->default(false);
        });
        DB::table('club_memberships')->where('status', 'pending')->orderBy('id')->each(function (object $membership): void {
            if (in_array('Club Administrator', json_decode($membership->roles, true) ?? [], true)) {
                DB::table('club_memberships')->where('id', $membership->id)->update(['has_onboarding_access' => true]);
            }
        });
        Schema::create('account_setup_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('platform_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80)->index();
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $table->json('changes')->nullable();
            $table->timestamps();
        });
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('platform_name', 100)->default('Barihafi');
            $table->string('currency', 3)->default('RWF');
            $table->string('brand_color', 7)->default('#E98A2A');
            $table->string('brand_initials', 3)->default('B');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_activities');
        Schema::dropIfExists('account_setup_invitations');
        Schema::table('club_memberships', fn (Blueprint $table) => $table->dropColumn('has_onboarding_access'));
        Schema::table('clubs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'description', 'contact_phone', 'status', 'suspension_reason', 'suspended_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropColumn(['account_status', 'suspended_from_status', 'suspension_reason', 'suspended_at']);
        });
    }
};
