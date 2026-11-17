<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Task 11: privacy, retention, export and audit.
 *
 * - recruitment_applicants keeps the consent the person last gave (notice
 *   version + moment), because an applicant outlives any one application and
 *   a talent-pool record has to say what it may be kept under. Each
 *   application already carries its own copy, so consent stays provable per
 *   submission; the applicant row is the latest of them.
 * - erasure_requested_at / anonymization_reason record a candidate's request
 *   and why a record was anonymized. retention_until and anonymized_at were
 *   created in Task 2 and are only now given a writer.
 * - recruitment_applicant_files.purged_at says the bytes are gone on purpose,
 *   so a missing file is not mistaken for a storage fault.
 * - recruitment_privacy_audits is the append-only record of sensitive acts
 *   (export, bulk download, erasure request, anonymization): who, when, under
 *   which scope and filter, and how many records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_applicants', function (Blueprint $table) {
            $table->string('privacy_notice_version')->nullable();
            $table->dateTime('privacy_accepted_at')->nullable();
            $table->dateTime('erasure_requested_at')->nullable();
            $table->string('anonymization_reason', 40)->nullable();
        });

        Schema::table('recruitment_applicants', function (Blueprint $table) {
            $table->index('erasure_requested_at', 'ra_erasure_idx');
        });

        Schema::table('recruitment_applicant_files', function (Blueprint $table) {
            $table->dateTime('purged_at')->nullable();
        });

        Schema::create('recruitment_privacy_audits', function (Blueprint $table) {
            $table->id();
            $table->string('action', 40);
            $table->string('outcome', 20);
            $table->foreignId('actor_user_id')->nullable();
            $table->string('actor_role', 60)->nullable();
            $table->string('scope_type', 20);
            $table->json('scope_city_ids')->nullable();
            $table->json('filters_json')->nullable();
            $table->unsignedInteger('record_count')->default(0);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('reason', 191)->nullable();
            $table->json('details_json')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at'], 'rpa_action_created_idx');
            $table->index(['actor_user_id', 'created_at'], 'rpa_actor_created_idx');
            $table->index(['subject_type', 'subject_id'], 'rpa_subject_idx');

            $table->foreign('actor_user_id', 'rpa_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        $this->backfillApplicantConsent();
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_privacy_audits');

        Schema::table('recruitment_applicant_files', function (Blueprint $table) {
            $table->dropColumn('purged_at');
        });

        Schema::table('recruitment_applicants', function (Blueprint $table) {
            $table->dropIndex('ra_erasure_idx');
        });

        Schema::table('recruitment_applicants', function (Blueprint $table) {
            $table->dropColumn([
                'privacy_notice_version', 'privacy_accepted_at', 'erasure_requested_at', 'anonymization_reason',
            ]);
        });
    }

    /** Existing applicants take the consent of their most recent application. */
    private function backfillApplicantConsent(): void
    {
        DB::table('recruitment_applicants')->orderBy('id')->chunkById(500, function ($applicants): void {
            foreach ($applicants as $applicant) {
                $latest = DB::table('recruitment_applications')
                    ->where('applicant_id', $applicant->id)
                    ->whereNotNull('privacy_accepted_at')
                    ->orderByDesc('privacy_accepted_at')
                    ->orderByDesc('id')
                    ->first(['privacy_notice_version', 'privacy_accepted_at']);

                if ($latest === null) {
                    continue;
                }

                DB::table('recruitment_applicants')->where('id', $applicant->id)->update([
                    'privacy_notice_version' => $latest->privacy_notice_version,
                    'privacy_accepted_at' => $latest->privacy_accepted_at,
                ]);
            }
        });
    }
};
