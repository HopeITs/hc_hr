<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meeting 19/11, track 5: the Permissions Library. Professional access a role
 * needs outside this system (a database, a Telegram group, a mail list, the
 * center's cameras, IT maintenance). The system does not enforce any of it:
 * it records who should have each item, and whether it was done or taken back.
 * None of it is a Spatie permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlements', function (Blueprint $table): void {
            $table->id();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('category', 32)->index();
            $table->string('reference')->nullable();
            $table->foreignId('mail_list_id')->nullable()->constrained('mail_lists')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // The defaults: an item every holder of a position needs, or every holder of a job level
        // (the managers' grade: the center's cameras). Exactly one of the two is set.
        Schema::create('position_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entitlement_id')->constrained('entitlements')->cascadeOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->cascadeOnDelete();
            $table->foreignId('job_level_id')->nullable()->constrained('job_levels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['entitlement_id', 'position_id']);
            $table->unique(['entitlement_id', 'job_level_id']);
        });

        // One row per person per item: required (should be done), granted (done) or revoked.
        Schema::create('employee_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('entitlement_id')->constrained('entitlements')->cascadeOnDelete();
            $table->string('source', 16)->default('default');
            $table->string('state', 16)->default('required')->index();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            // Set when the person moved to a position whose defaults no longer include this item.
            $table->timestamp('needs_review_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'entitlement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_entitlements');
        Schema::dropIfExists('position_entitlements');
        Schema::dropIfExists('entitlements');
    }
};
