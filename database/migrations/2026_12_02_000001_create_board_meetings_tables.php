<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The board's meetings (دورية الاجتماعات والمحضر): the meeting, who is expected / present / absent,
 * the narrative sections of the minutes (free text, as the secretary writes them) and the table of
 * follow-up items at the end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_meetings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('location')->nullable();
            $table->string('room', 60)->nullable();
            $table->text('purpose')->nullable();
            $table->string('led_by')->nullable();
            // scheduled | cancelled (held = scheduled, in the past)
            $table->string('status', 20)->default('scheduled');
            $table->text('cancel_reason')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('prepared_by')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('starts_at');
        });

        Schema::create('board_meeting_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // expected | present | online | absent
            $table->string('status', 20)->default('expected');
            $table->text('absence_reason')->nullable();
            // null | pending | approved | rejected: the member's own request, decided by the chair
            $table->string('absence_request', 20)->nullable();
            $table->timestamp('absence_decided_at')->nullable();
            $table->timestamps();

            $table->unique(['board_meeting_id', 'employee_id']);
        });

        Schema::create('board_meeting_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_meeting_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('board_meeting_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_meeting_id')->constrained()->cascadeOnDelete();
            $table->string('topic');
            $table->text('required')->nullable();
            // The person or body (a department) it waits on, as the minutes name them.
            $table->string('party')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            // Room for open / done later; nothing reads it yet.
            $table->string('status', 20)->default('open');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_meeting_follow_ups');
        Schema::dropIfExists('board_meeting_sections');
        Schema::dropIfExists('board_meeting_attendees');
        Schema::dropIfExists('board_meetings');
    }
};
