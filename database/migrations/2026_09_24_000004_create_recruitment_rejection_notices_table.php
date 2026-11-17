<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per rejection email, and at most one per application.
 *
 * The unique application_id is what stops a candidate receiving two
 * apologies: rejecting, reopening and rejecting again, or selecting an
 * already-rejected candidate for a bulk action, finds the existing row. A
 * failed row is re-queued in place rather than duplicated.
 *
 * No reason and no note are stored here, so nothing on this row can travel
 * into the email by mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_rejection_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id');
            $table->foreignId('decision_id')->nullable();
            $table->string('recipient_email', 191);
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('requested_by_user_id');
            $table->dateTime('queued_at');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_reason', 191)->nullable();
            $table->timestamps();

            $table->unique('application_id', 'rrn_application_uq');
            $table->index('status', 'rrn_status_idx');

            $table->foreign('application_id', 'rrn_application_fk')
                ->references('id')->on('recruitment_applications')->cascadeOnDelete();
            $table->foreign('decision_id', 'rrn_decision_fk')
                ->references('id')->on('recruitment_application_decisions')->nullOnDelete();
            $table->foreign('requested_by_user_id', 'rrn_requester_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_rejection_notices');
    }
};
