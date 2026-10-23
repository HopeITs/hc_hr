<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The index of an employee's current digital-ID card.
 *
 * Signed decision (docs/ux/access-policy.md §9 A2D-Q6, docs/ux/decisions.md
 * "DEC-02 revision"): the card link is
 * /ID/[city 3 digits][program 3 digits][employee id]-[index]. Index 0 is the
 * temporary cardboard card, plastic cards start at 1, and only the latest
 * index resolves. Every existing row starts at 0, which is right because no
 * card has been printed yet.
 *
 * Additive only. digital_id_token stays where it is (nothing reads it any
 * more) so the previous release can still be deployed against this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'digital_id_card_index')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedSmallInteger('digital_id_card_index')
                ->default(0)
                ->after('digital_id_revoked_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employees', 'digital_id_card_index')) {
            return;
        }

        // Dropping the column once a plastic card exists would reset every
        // card to 0 when the migration runs again, and bring back cardboard
        // cards and lost plastic cards that were revoked by a re-issue. The
        // column is harmless to the previous release, so it stays.
        if (DB::table('employees')->where('digital_id_card_index', '>', 0)->exists()) {
            Log::warning('digital_id_card_index kept on rollback: issued card indexes would otherwise be lost.');

            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('digital_id_card_index');
        });
    }
};
