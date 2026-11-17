<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * DEC-09 increment 2 (docs/ux/journeys.md §3.3, exit-journey-implementation.md):
 * the exit journey of a departing volunteer. One row per departure, i.e. per
 * employee and the last real contract they leave on; started when HR stops
 * that contract's auto-renewal (E1), terminates it (E4), or it ends with no
 * successor. It holds only what no other record owns: when and why it
 * started, the optional exit interview, and when it was first seen complete.
 * Equipment, contract end, deactivation, access and card are read from their
 * own tables, never copied here.
 *
 * Additive: no backfill, so nobody who left before this release is shown a
 * journey. Rollback drops the table and nothing else reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offboarding_journeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            // The last real contract the volunteer leaves on.
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            // auto_renew_stopped (E1) | terminated (E4) | contract_ended (no successor).
            $table->string('trigger', 32);
            $table->timestamp('started_at');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('exit_interview_held_at')->nullable();
            $table->foreignId('exit_interview_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            // One journey per departure: a retried save, a second trigger on
            // the same contract and the daily sweep all land on this row.
            $table->unique(['employee_id', 'contract_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offboarding_journeys');
    }
};
