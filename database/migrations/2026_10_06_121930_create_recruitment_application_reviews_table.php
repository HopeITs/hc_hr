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
        Schema::create('recruitment_application_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id');
            $table->foreignId('reviewer_user_id');
            $table->string('recommendation', 30);
            $table->text('pros')->nullable();
            $table->text('cons')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->foreign('application_id', 'ra_reviews_application_fk')
                ->references('id')->on('recruitment_applications')->cascadeOnDelete();
            $table->foreign('reviewer_user_id', 'ra_reviews_reviewer_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique(['application_id', 'reviewer_user_id'], 'ra_reviews_application_reviewer_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_application_reviews');
    }
};
