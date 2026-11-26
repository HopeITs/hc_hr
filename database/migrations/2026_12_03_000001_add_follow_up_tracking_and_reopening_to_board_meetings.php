<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up rows get a life after the minutes: done (when, by whom) or carried into a later meeting, and
 * their owner is told once. Published minutes can be reopened by the chair alone, and that is recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_meeting_follow_ups', function (Blueprint $table) {
            $table->timestamp('done_at')->nullable()->after('status');
            $table->foreignId('done_by')->nullable()->after('done_at')->constrained('users')->nullOnDelete();
            $table->foreignId('carried_from_id')->nullable()->after('done_by')
                ->constrained('board_meeting_follow_ups')->nullOnDelete();
            $table->timestamp('notified_at')->nullable()->after('carried_from_id');
        });

        Schema::table('board_meetings', function (Blueprint $table) {
            $table->timestamp('reopened_at')->nullable()->after('published_at');
            $table->foreignId('reopened_by')->nullable()->after('reopened_at')->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable()->after('reopened_by');
        });
    }

    public function down(): void
    {
        Schema::table('board_meetings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn(['reopened_at', 'reopen_reason']);
        });

        Schema::table('board_meeting_follow_ups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carried_from_id');
            $table->dropConstrainedForeignId('done_by');
            $table->dropColumn(['done_at', 'notified_at']);
        });
    }
};
