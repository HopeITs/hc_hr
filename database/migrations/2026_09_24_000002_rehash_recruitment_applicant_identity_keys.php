<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-keys identity_key_hash from a bare sha256(email) to
 * hash_hmac('sha256', email, app.key) — the scheme Team 1's
 * PublicRecruitmentApplicationService already uses — so one person maps to
 * one applicant row whichever submission path they came through.
 *
 * Idempotent: a row already on the HMAC scheme recomputes to its own value.
 * A row whose target hash is already held by another row is skipped rather
 * than violating ra_identity_hash_uq; that pair is a genuine duplicate for a
 * human to resolve, not something a migration should merge.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rehash(fn (string $email): string => hash_hmac('sha256', $email, (string) config('app.key')));
    }

    public function down(): void
    {
        $this->rehash(fn (string $email): string => hash('sha256', $email));
    }

    private function rehash(callable $hash): void
    {
        DB::table('recruitment_applicants')->orderBy('id')->each(function ($row) use ($hash): void {
            $target = $hash((string) $row->email_normalized);

            $taken = DB::table('recruitment_applicants')
                ->where('identity_key_hash', $target)
                ->where('id', '!=', $row->id)
                ->exists();

            if (! $taken) {
                DB::table('recruitment_applicants')->where('id', $row->id)->update(['identity_key_hash' => $target]);
            }
        });
    }
};
