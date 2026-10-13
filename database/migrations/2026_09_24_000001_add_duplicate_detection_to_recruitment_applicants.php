<?php

use App\Models\RecruitmentApplicant;
use App\Services\RecruitmentDuplicateApplicantDetector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Task 1 edge case "email مختلف لكن phone نفسه": two applicant rows
 * that share a phone number but not an email are flagged, never merged.
 *
 * phone_match_key is a comparison key derived from phone_normalized (see
 * RecruitmentDuplicateApplicantDetector::phoneMatchKey) so that "0933…",
 * "+963933…" and "00963933…" compare equal even though the two submission
 * paths normalize phone numbers slightly differently.
 *
 * possible_duplicate_of_applicant_id points at the earliest applicant of the
 * group. It is a hint for HR, not an identity decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_applicants', function (Blueprint $table) {
            $table->string('phone_match_key', 20)->nullable()->after('phone_normalized');
            $table->foreignId('possible_duplicate_of_applicant_id')->nullable()->after('identity_key_hash');

            $table->index('phone_match_key', 'ra_phone_match_idx');
            $table->index('possible_duplicate_of_applicant_id', 'ra_possible_dup_idx');

            $table->foreign('possible_duplicate_of_applicant_id', 'ra_possible_dup_fk')
                ->references('id')->on('recruitment_applicants')->nullOnDelete();
        });

        // Backfill through the detector itself so the key rule lives in one
        // place. Every key is written first, then flags are raised, so the
        // earliest applicant of a pair is always the anchor.
        $detector = app(RecruitmentDuplicateApplicantDetector::class);

        RecruitmentApplicant::query()->orderBy('id')->each(function (RecruitmentApplicant $applicant) use ($detector): void {
            $applicant->forceFill(['phone_match_key' => $detector->phoneMatchKey($applicant->phone_normalized)])->saveQuietly();
        });

        RecruitmentApplicant::query()->whereNotNull('phone_match_key')->orderBy('id')
            ->each(fn (RecruitmentApplicant $applicant) => $detector->flag($applicant));
    }

    public function down(): void
    {
        Schema::table('recruitment_applicants', function (Blueprint $table) {
            $table->dropForeign('ra_possible_dup_fk');
            $table->dropIndex('ra_possible_dup_idx');
            $table->dropIndex('ra_phone_match_idx');
            $table->dropColumn(['possible_duplicate_of_applicant_id', 'phone_match_key']);
        });
    }
};
