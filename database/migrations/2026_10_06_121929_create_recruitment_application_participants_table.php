<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recruitment_application_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id');
            $table->foreignId('user_id');
            $table->string('role', 20);
            $table->foreignId('added_by_user_id');
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('application_id', 'ra_participants_application_fk')
                ->references('id')->on('recruitment_applications')->cascadeOnDelete();
            $table->foreign('user_id', 'ra_participants_user_fk')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('added_by_user_id', 'ra_participants_added_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('removed_by_user_id', 'ra_participants_removed_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->unique(['application_id', 'user_id'], 'ra_participants_application_user_unique');
            $table->index(['user_id', 'removed_at'], 'ra_participants_user_removed_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_application_participants');
    }
};
