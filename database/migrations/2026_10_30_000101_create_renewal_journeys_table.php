<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * DEC-09 increment 2 (docs/ux/journeys.md §3.2, renewal-journey-implementation.md):
 * the renewal journey of an expiring contract. One row per contract (unique),
 * started by `renewals:track-journeys` 30 days before the contract's end date.
 *
 * It holds only the one step no other record owns: HR's decision to let the
 * contract renew or to stop it, who made it and when. The expiry warning
 * (notification ledger), the successor contract (contracts) and its leave
 * balances (leave_balances) are read from their own tables, never copied here;
 * the decision's due date is read live from contracts.end_date.
 *
 * Additive: no backfill. Contracts already past the 30-day mark get a journey
 * at the command's first run, so nothing already renewed is shown open.
 * Rollback drops the table and nothing else reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_journeys', function (Blueprint $table) {
            $table->id();
            // The expiring contract. One journey per contract, ever.
            $table->foreignId('contract_id')->unique()->constrained('contracts')->cascadeOnDelete();
            // The contract's employee, so the edit page lists their journeys
            // without a join. A contract never changes employee.
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamp('started_at');

            // R2: renew | stop. Null = not decided yet.
            $table->string('decision', 16)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            // True when the "stop" decision itself switched auto-renew off, so
            // an undo switches it back on and never turns on a flag HR had
            // already left off.
            $table->boolean('decision_switched_auto_renew')->default(false);

            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['employee_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_journeys');
    }
};
