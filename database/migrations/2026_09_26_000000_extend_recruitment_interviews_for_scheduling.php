<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Task 7: what the Phase 1 interview skeleton needs to schedule,
 * reschedule, cancel and remind.
 *
 * - An interview gets an end time, the timezone it was entered in, a meeting
 *   link kept apart from the physical location (shown only to authorized
 *   users), when its current slot was set (so a reminder is never sent for a
 *   slot fixed after the reminder window opened), and who cancelled it, why,
 *   and who recorded the outcome. Interviews are never deleted: cancelled is
 *   a status, and every foreign key into this table stays restrict/cascade as
 *   Phase 1 left it.
 * - location_value is widened past 191 characters. On SQLite it is left
 *   alone: change() rebuilds the table there, and dropping
 *   recruitment_interviews fires the ON DELETE CASCADE on participants and
 *   evaluations. SQLite does not enforce the varchar length anyway.
 * - A participant who is taken off an interview keeps their row, marked
 *   removed, so the record of who was invited survives.
 * - recruitment_interview_reschedules is the reschedule history: one row per
 *   change of slot or place, with the old and new values, the reason, and
 *   whether a reminder had already gone out for the old slot. It stores no
 *   meeting link, only whether the place changed.
 * - recruitment_interview_notices is one row per email to the candidate
 *   (scheduled, rescheduled, cancelled, reminder), written inside the
 *   scheduling transaction and sent by a job after commit. scheduled_for is
 *   the slot the email talks about; a worker that finds the interview has
 *   moved or been cancelled since drops the email instead of sending a stale
 *   one. The unique key allows one reminder per slot and per offset, so a
 *   reschedule after a reminder gets a new one, and nothing gets two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_interviews', function (Blueprint $table) {
            $table->dateTime('ends_at')->nullable()->after('scheduled_at');
            $table->string('timezone', 64)->nullable()->after('ends_at');
            $table->text('meeting_url')->nullable()->after('location_value');
            $table->dateTime('slot_set_at')->nullable()->after('meeting_url');
            $table->unsignedSmallInteger('reschedule_count')->default(0)->after('slot_set_at');
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable();
            $table->string('cancellation_reason', 60)->nullable();
            $table->text('cancellation_note')->nullable();
            $table->dateTime('outcome_recorded_at')->nullable();
            $table->foreignId('outcome_recorded_by_user_id')->nullable();
            $table->text('outcome_note')->nullable();
            $table->foreignId('updated_by_user_id')->nullable();
        });

        Schema::table('recruitment_interview_participants', function (Blueprint $table) {
            $table->foreignId('added_by_user_id')->nullable()->after('notification_status');
            $table->dateTime('removed_at')->nullable()->after('added_by_user_id');
            $table->foreignId('removed_by_user_id')->nullable()->after('removed_at');
        });

        // Altering an existing SQLite table's keys or column types rebuilds it,
        // and dropping recruitment_interviews would cascade into participants
        // and evaluations. The suite runs on SQLite, which neither enforces
        // varchar lengths nor needs these keys to behave; MySQL gets both.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('recruitment_interviews', function (Blueprint $table) {
                $table->string('location_value', 2048)->nullable()->change();

                $table->foreign('cancelled_by_user_id', 'ri_canceller_fk')
                    ->references('id')->on('users')->restrictOnDelete();
                $table->foreign('outcome_recorded_by_user_id', 'ri_outcome_recorder_fk')
                    ->references('id')->on('users')->restrictOnDelete();
                $table->foreign('updated_by_user_id', 'ri_updater_fk')
                    ->references('id')->on('users')->restrictOnDelete();
            });

            Schema::table('recruitment_interview_participants', function (Blueprint $table) {
                $table->foreign('added_by_user_id', 'rip_adder_fk')
                    ->references('id')->on('users')->restrictOnDelete();
                $table->foreign('removed_by_user_id', 'rip_remover_fk')
                    ->references('id')->on('users')->restrictOnDelete();
            });
        }

        // Rows written before this migration: an hour-long slot, set when
        // the row was created, in the application timezone.
        DB::table('recruitment_interviews')->whereNull('ends_at')->update([
            'ends_at' => DB::raw(DB::getDriverName() === 'sqlite'
                ? "datetime(scheduled_at, '+60 minutes')"
                : 'DATE_ADD(scheduled_at, INTERVAL 60 MINUTE)'),
            'slot_set_at' => DB::raw('created_at'),
            'timezone' => config('app.timezone'),
        ]);

        Schema::create('recruitment_interview_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id');
            $table->dateTime('from_scheduled_at');
            $table->dateTime('from_ends_at')->nullable();
            $table->dateTime('to_scheduled_at');
            $table->dateTime('to_ends_at')->nullable();
            $table->string('from_location_type', 60);
            $table->string('to_location_type', 60);
            $table->boolean('location_changed')->default(false);
            $table->boolean('reminder_already_sent')->default(false);
            $table->text('reason');
            $table->foreignId('rescheduled_by_user_id');
            $table->dateTime('rescheduled_at');

            $table->index(['interview_id', 'rescheduled_at'], 'rir_interview_at_idx');

            $table->foreign('interview_id', 'rir_interview_fk')
                ->references('id')->on('recruitment_interviews')->restrictOnDelete();
            $table->foreign('rescheduled_by_user_id', 'rir_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recruitment_interview_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id');
            $table->string('kind', 20);
            $table->dateTime('scheduled_for');
            $table->unsignedInteger('offset_minutes')->nullable();
            $table->string('recipient_email', 191);
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('requested_by_user_id')->nullable();
            $table->dateTime('queued_at');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_reason', 191)->nullable();
            $table->timestamps();

            $table->unique(['interview_id', 'kind', 'scheduled_for', 'offset_minutes'], 'rin_reminder_slot_uq');
            $table->index(['status', 'queued_at'], 'rin_status_queued_idx');

            $table->foreign('interview_id', 'rin_interview_fk')
                ->references('id')->on('recruitment_interviews')->restrictOnDelete();
            $table->foreign('requested_by_user_id', 'rin_requester_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_interview_notices');
        Schema::dropIfExists('recruitment_interview_reschedules');

        $sqlite = DB::getDriverName() === 'sqlite';

        Schema::table('recruitment_interview_participants', function (Blueprint $table) use ($sqlite) {
            if (! $sqlite) {
                $table->dropForeign('rip_adder_fk');
                $table->dropForeign('rip_remover_fk');
            }
            $table->dropColumn(['added_by_user_id', 'removed_at', 'removed_by_user_id']);
        });

        Schema::table('recruitment_interviews', function (Blueprint $table) use ($sqlite) {
            if (! $sqlite) {
                $table->dropForeign('ri_canceller_fk');
                $table->dropForeign('ri_outcome_recorder_fk');
                $table->dropForeign('ri_updater_fk');
            }
            $table->dropColumn([
                'ends_at', 'timezone', 'meeting_url', 'slot_set_at', 'reschedule_count',
                'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason', 'cancellation_note',
                'outcome_recorded_at', 'outcome_recorded_by_user_id', 'outcome_note', 'updated_by_user_id',
            ]);
        });
    }
};
