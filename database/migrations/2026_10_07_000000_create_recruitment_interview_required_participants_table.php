<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_interview_required_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('interview_id');
            $table->string('seat', 20);
            $table->foreignId('owner_user_id');
            $table->foreignId('attendee_user_id');
            $table->timestamps();

            $table->unique(['interview_id', 'seat'], 'rirp_interview_seat_uq');
            $table->index('attendee_user_id', 'rirp_attendee_idx');

            $table->foreign('interview_id', 'rirp_interview_fk')
                ->references('id')->on('recruitment_interviews')->cascadeOnDelete();
            $table->foreign('owner_user_id', 'rirp_owner_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('attendee_user_id', 'rirp_attendee_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_interview_required_participants');
    }
};
