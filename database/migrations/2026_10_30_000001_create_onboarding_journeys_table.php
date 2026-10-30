<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * D4-I (DEC-09, docs/ux/journeys.md): the onboarding journey of a new
 * volunteer. One row per volunteer, started when their first real contract
 * activates them. It holds only the two steps no other record owns: the
 * printed card handed over and the orientation session. The record, contract,
 * account and digital card are read from their own tables, never copied here.
 *
 * Additive: existing staff get no row, so nobody already working is shown an
 * unfinished journey. Rollback drops the table and nothing else reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_journeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            // The first real contract, which started the journey.
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->timestamp('started_at');

            // DEC-09 slice, decided 2026-10-30: within 14 days of the contract start.
            $table->date('orientation_due_on');
            $table->date('orientation_scheduled_for')->nullable();
            $table->foreignId('orientation_scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('orientation_completed_at')->nullable();
            $table->foreignId('orientation_completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('card_handed_over_at')->nullable();
            $table->foreignId('card_handed_over_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_journeys');
    }
};
