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
        if (Schema::hasTable('recruitment_interview_evaluation_notes')) {
            $foreignKeys = collect(Schema::getForeignKeys('recruitment_interview_evaluation_notes'));

            Schema::table('recruitment_interview_evaluation_notes', function (Blueprint $table) use ($foreignKeys): void {
                if (! $foreignKeys->contains(fn (array $key): bool => $key['columns'] === ['evaluation_id'])) {
                    $table->foreign('evaluation_id', 'ri_eval_notes_evaluation_fk')
                        ->references('id')
                        ->on('recruitment_interview_evaluations')
                        ->restrictOnDelete();
                }

                if (! $foreignKeys->contains(fn (array $key): bool => $key['columns'] === ['author_user_id'])) {
                    $table->foreign('author_user_id', 'ri_eval_notes_author_fk')
                        ->references('id')
                        ->on('users')
                        ->restrictOnDelete();
                }
            });

            if (! Schema::hasIndex('recruitment_interview_evaluation_notes', ['evaluation_id', 'created_at'])) {
                Schema::table('recruitment_interview_evaluation_notes', function (Blueprint $table): void {
                    $table->index(['evaluation_id', 'created_at'], 'ri_eval_notes_evaluation_created_idx');
                });
            }

            return;
        }

        Schema::create('recruitment_interview_evaluation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id');
            $table->foreignId('author_user_id');
            $table->text('body');
            $table->timestamps();

            $table->foreign('evaluation_id', 'ri_eval_notes_evaluation_fk')
                ->references('id')
                ->on('recruitment_interview_evaluations')
                ->restrictOnDelete();
            $table->foreign('author_user_id', 'ri_eval_notes_author_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->index(['evaluation_id', 'created_at'], 'ri_eval_notes_evaluation_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_interview_evaluation_notes');
    }
};
