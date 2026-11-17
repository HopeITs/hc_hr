<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Task 8: what the Phase 1 evaluation table needs to be edited
 * safely and never lose what it said before.
 *
 * - version: bumped on every change. A save carries the version the user
 *   opened, so two tabs — or a reviewer and HR correcting them — cannot
 *   silently overwrite each other. The existing unique (interview, reviewer)
 *   key keeps it one row per reviewer per interview.
 * - updated_by_user_id: who last changed the row (the reviewer, or HR
 *   correcting it).
 * - withdrawn_*: an evaluation is never deleted. HR can withdraw one, with a
 *   reason, and it drops out of the aggregate; its content and history stay.
 * - recruitment_interview_evaluation_revisions: an append-only copy of the
 *   evaluation after every change (created, updated, corrected, withdrawn),
 *   with who and why. This is the evaluation history.
 * - evaluations_closed_*: on the interview. After completion HR can close
 *   evaluation; reviewers can then no longer edit (HR can still correct).
 *
 * Foreign keys on the two existing tables are added on MySQL only: altering
 * an existing SQLite table's keys rebuilds it, and rebuilding
 * recruitment_interviews would cascade into participants and evaluations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_interview_evaluations', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('recommendation');
            $table->foreignId('updated_by_user_id')->nullable()->after('submitted_at');
            $table->dateTime('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by_user_id')->nullable();
            $table->text('withdrawal_reason')->nullable();
        });

        Schema::table('recruitment_interviews', function (Blueprint $table) {
            $table->dateTime('evaluations_closed_at')->nullable();
            $table->foreignId('evaluations_closed_by_user_id')->nullable();
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('recruitment_interview_evaluations', function (Blueprint $table) {
                $table->foreign('updated_by_user_id', 'rie_updater_fk')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('withdrawn_by_user_id', 'rie_withdrawer_fk')->references('id')->on('users')->restrictOnDelete();
            });

            Schema::table('recruitment_interviews', function (Blueprint $table) {
                $table->foreign('evaluations_closed_by_user_id', 'ri_eval_closer_fk')->references('id')->on('users')->restrictOnDelete();
            });
        }

        Schema::create('recruitment_interview_evaluation_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id');
            $table->unsignedInteger('version');
            $table->string('action', 20);
            $table->text('pros')->nullable();
            $table->text('cons')->nullable();
            $table->text('notes')->nullable();
            $table->string('recommendation');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by_user_id');
            $table->dateTime('changed_at');

            $table->unique(['evaluation_id', 'version'], 'rier_evaluation_version_uq');

            $table->foreign('evaluation_id', 'rier_evaluation_fk')
                ->references('id')->on('recruitment_interview_evaluations')->restrictOnDelete();
            $table->foreign('changed_by_user_id', 'rier_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_interview_evaluation_revisions');

        $sqlite = DB::getDriverName() === 'sqlite';

        Schema::table('recruitment_interviews', function (Blueprint $table) use ($sqlite) {
            if (! $sqlite) {
                $table->dropForeign('ri_eval_closer_fk');
            }
            $table->dropColumn(['evaluations_closed_at', 'evaluations_closed_by_user_id']);
        });

        Schema::table('recruitment_interview_evaluations', function (Blueprint $table) use ($sqlite) {
            if (! $sqlite) {
                $table->dropForeign('rie_updater_fk');
                $table->dropForeign('rie_withdrawer_fk');
            }
            $table->dropColumn(['version', 'updated_by_user_id', 'withdrawn_at', 'withdrawn_by_user_id', 'withdrawal_reason']);
        });
    }
};
