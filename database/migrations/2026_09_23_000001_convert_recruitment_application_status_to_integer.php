<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Converts recruitment_applications.status from the string values it shipped
 * with to the integer values RecruitmentApplicationStatus now uses, matching
 * every other Recruitment status column (RecruitmentRequestStatus,
 * HiringCampaignStatus, ...).
 *
 * Team 1's original table (2026_09_15_000004) is left untouched — this is a
 * separate, additive migration that ALTERs the column in place, which
 * preserves its existing indexes (rapp_status_idx, rapp_campaign_status_idx,
 * rapp_vacancy_status_idx) since the column itself is never dropped.
 *
 * Existing rows are remapped by their old string value before the column
 * type changes, so no live data (there should be none outside dev testing
 * yet) is silently coerced to 0 by MySQL's implicit VARCHAR->INT cast.
 */
return new class extends Migration
{
    /**
     * Literal values, not enum constants: RecruitmentApplicationStatus is
     * string-backed again (Team 1's enum, adopted at the rms-main merge), so
     * reading it here would map every string onto itself. This migration is
     * superseded by 2026_09_25_000001, which converts the column back.
     *
     * @var array<string, int>
     */
    private const MAP = [
        'submitted' => 0,
        'under_review' => 1,
        'shortlisted' => 2,
        'rejected' => 3,
        'interview_scheduled' => 4,
        'interviewed' => 5,
        'selected' => 6,
        'offer_sent' => 7,
        'offer_accepted' => 8,
        'offer_rejected' => 9,
        'hired' => 10,
        'withdrawn' => 11,
        'archived' => 12,
    ];

    public function up(): void
    {
        // MySQL only. `ALTER TABLE … MODIFY` does not exist in SQLite, and
        // the test suite runs on SQLite :memory:, so this broke every test's
        // migrate:fresh. Skipping here is safe because 2026_09_25_000001 (its
        // reversal) skips on the same condition: on SQLite the column simply
        // stays the VARCHAR it was created as, the same end state MySQL
        // reaches after both run.
        if (! $this->isMySql()) {
            return;
        }

        foreach (self::MAP as $string => $int) {
            DB::table('recruitment_applications')->where('status', $string)->update(['status' => (string) $int]);
        }

        // Raw ALTER TABLE is not prefix-aware the way the query builder is —
        // this connection runs behind the 'hchr_' table prefix, so the actual
        // physical name must be resolved explicitly rather than hardcoded.
        DB::statement('ALTER TABLE '.$this->table().' MODIFY status TINYINT UNSIGNED NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        DB::statement('ALTER TABLE '.$this->table()." MODIFY status VARCHAR(60) NOT NULL DEFAULT 'submitted'");

        foreach (self::MAP as $string => $int) {
            DB::table('recruitment_applications')->where('status', (string) $int)->update(['status' => $string]);
        }
    }

    private function table(): string
    {
        return DB::connection()->getTablePrefix().'recruitment_applications';
    }

    private function isMySql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
