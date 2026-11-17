<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_job_offers', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')->on('recruitment_job_offer_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_job_offers', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
    }
};
