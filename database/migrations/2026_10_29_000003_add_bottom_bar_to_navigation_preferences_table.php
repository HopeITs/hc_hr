<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The four shortcuts a person chose for the phone bar (prompt 10), on the row
 * that already keeps their favourites and recents: one more short list of ids
 * read on the same requests, so no table of its own. Null until they choose;
 * the bar then shows the default. Like the other lists it grants nothing:
 * App\Support\BottomBar keeps only ids the active role may open, on every read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('navigation_preferences', function (Blueprint $table) {
            $table->json('bottom_bar')->nullable()->after('recent_people')->comment('Phone bar shortcuts (App\Support\BottomBar keys), in order; null = the default bar');
        });
    }

    public function down(): void
    {
        Schema::table('navigation_preferences', function (Blueprint $table) {
            $table->dropColumn('bottom_bar');
        });
    }
};
