<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The interface language each user last worked in (A4 decision, 2026-10-21),
 * so emails sent outside a request (the contract renewal and expiry notices)
 * go out in that language. Nullable with no backfill: an empty value keeps
 * today's behaviour, the app's default locale. See
 * docs/ux/a4-implementation.md §5 for compatibility and rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
