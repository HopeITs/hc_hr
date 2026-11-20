<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Probation review (meeting 19/11, track 4): when a trial contract enters its
 * last third, the direct manager is asked what should happen next. One row per
 * contract (unique), started by `probation:start-reviews`.
 *
 * The decision is a REQUEST to HR (extend | confirm | cancel) with a mandatory
 * reason. Nothing is applied to the contract: what HR does with each request
 * has not been agreed yet, so no end date, contract or termination is written.
 *
 * Additive: no backfill beyond what the command finds. Rollback drops the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->unique()->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamp('started_at');

            // extend | confirm | cancel — what the manager asks HR for. Null = not answered yet.
            $table->string('decision', 16)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            // The first time an HR user opened the request.
            $table->timestamp('hr_seen_at')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probation_reviews');
    }
};
