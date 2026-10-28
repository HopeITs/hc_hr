<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1 (profile-contract.md §5.1, approved by the technical lead 2026-10-27):
 * changes an employee proposes to their own profile that wait for HR.
 *
 * Additive only: a new table, nothing existing is altered, so SQLite rebuilds
 * nothing. down() drops it; approved changes were already written to their own
 * tables and stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // education | course | experience | relative | photo
            $table->string('section', 20);
            // The proposed entry, validated on submission. Never pay or identity.
            $table->json('proposed');
            // 0 pending, 1 approved, 2 rejected, 3 replaced by a newer proposal.
            $table->unsignedTinyInteger('status')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'section', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_change_requests');
    }
};
