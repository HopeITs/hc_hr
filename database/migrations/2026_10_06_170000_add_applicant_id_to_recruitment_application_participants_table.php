<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_application_participants', function (Blueprint $table): void {
            $table->foreignId('applicant_id')->nullable()->after('application_id');
            $table->foreign('applicant_id', 'ra_participants_applicant_fk')
                ->references('id')->on('recruitment_applicants')->cascadeOnDelete();
            $table->index(['applicant_id', 'user_id'], 'ra_participants_applicant_user_idx');
        });

        DB::table('recruitment_application_participants as participants')
            ->join('recruitment_applications as applications', 'applications.id', '=', 'participants.application_id')
            ->whereNull('participants.applicant_id')
            ->select('participants.id', 'applications.applicant_id')
            ->orderBy('participants.id')
            ->get()
            ->each(function (object $row): void {
                DB::table('recruitment_application_participants')
                    ->where('id', $row->id)
                    ->update(['applicant_id' => $row->applicant_id]);
            });
    }

    public function down(): void
    {
        Schema::table('recruitment_application_participants', function (Blueprint $table): void {
            $table->dropForeign('ra_participants_applicant_fk');
            $table->dropIndex('ra_participants_applicant_user_idx');
            $table->dropColumn('applicant_id');
        });
    }
};
