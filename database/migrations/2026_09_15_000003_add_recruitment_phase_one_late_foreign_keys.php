<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_requests', function (Blueprint $table) {
            $table->foreign('target_vacancy_id')
                ->references('id')->on('vacancies')->restrictOnDelete();
        });

        Schema::table('job_posts', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')->on('job_post_versions')->nullOnDelete();
        });

        Schema::table('application_forms', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')->on('application_form_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('application_forms', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::table('job_posts', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::table('recruitment_requests', function (Blueprint $table) {
            $table->dropForeign(['target_vacancy_id']);
        });
    }
};
