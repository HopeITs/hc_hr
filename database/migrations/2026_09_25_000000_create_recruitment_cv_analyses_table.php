<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One automatic reading per CV file.
 *
 * Only the facts drawn from the CV are kept (skills, education, languages,
 * experience, and how they compare with the request), never the CV's text,
 * so this table adds no copy of the document. It goes when the file goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_cv_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id');
            $table->foreignId('application_id')->nullable();
            $table->string('status', 30);
            $table->string('extraction_method', 30)->nullable();
            $table->unsignedInteger('text_chars')->default(0);
            $table->json('facts_json')->nullable();
            $table->json('comparison_json')->nullable();
            $table->string('analyzer_version', 20);
            $table->string('error', 191)->nullable();
            $table->dateTime('analyzed_at');
            $table->timestamps();

            $table->unique('file_id', 'rca_file_uq');
            $table->index('application_id', 'rca_application_idx');
            $table->index('status', 'rca_status_idx');

            $table->foreign('file_id', 'rca_file_fk')
                ->references('id')->on('recruitment_applicant_files')->cascadeOnDelete();
            $table->foreign('application_id', 'rca_application_fk')
                ->references('id')->on('recruitment_applications')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_cv_analyses');
    }
};
