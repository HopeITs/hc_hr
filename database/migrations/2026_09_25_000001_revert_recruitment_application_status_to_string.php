<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Returns recruitment_applications.status to VARCHAR(60) DEFAULT 'submitted',
 * the shape Team 1's original migration (2026_09_15_000004) created.
 *
 * Why: at the rms-main merge (25 Sep 2026) the team accepted Team 1's
 * string-backed App\Enums\RecruitmentApplicationStatus. Its public submission
 * service writes 'submitted' into this column, which MySQL rejects in a
 * TINYINT column (strict mode), so every real application would fail.
 * 2026_09_23_000001 had converted the column to TINYINT; this supersedes it.
 *
 * A forward migration rather than deleting 2026_09_23: that one has already
 * run on at least one machine and is pushed, and a deleted-but-run migration
 * leaves databases in a state no file describes.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const MAP = [
        0 => 'submitted',
        1 => 'under_review',
        2 => 'shortlisted',
        3 => 'rejected',
        4 => 'interview_scheduled',
        5 => 'interviewed',
        6 => 'selected',
        7 => 'offer_sent',
        8 => 'offer_accepted',
        9 => 'offer_rejected',
        10 => 'hired',
        11 => 'withdrawn',
        12 => 'archived',
    ];

    public function up(): void
    {
        // MySQL only, paired with 2026_09_23_000001 which skips on the same
        // condition: on SQLite (the test suite) the column never left VARCHAR.
        if (! $this->isMySql()) {
            return;
        }

        // Widen first, then remap: the integers survive the type change as
        // their digit strings, which are then rewritten to the real values.
        DB::statement("ALTER TABLE {$this->table()} MODIFY status VARCHAR(60) NOT NULL DEFAULT 'submitted'");

        foreach (self::MAP as $int => $string) {
            DB::table('recruitment_applications')->where('status', (string) $int)->update(['status' => $string]);
        }
    }

    public function down(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        foreach (self::MAP as $int => $string) {
            DB::table('recruitment_applications')->where('status', $string)->update(['status' => (string) $int]);
        }

        DB::statement("ALTER TABLE {$this->table()} MODIFY status TINYINT UNSIGNED NOT NULL DEFAULT 0");
    }

    /** Raw ALTER TABLE is not prefix-aware; this connection uses 'hchr_'. */
    private function table(): string
    {
        return DB::connection()->getTablePrefix().'recruitment_applications';
    }

    private function isMySql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
