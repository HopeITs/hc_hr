<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minutes of meetings held before the system: the original file (PDF or Word) is kept as it was signed, the
 * meeting gets its real number and date from it, and its text is kept for the minutes search.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_meetings', function (Blueprint $table) {
            // system | archive
            $table->string('source', 20)->default('system')->after('status');
            $table->string('archive_path')->nullable()->after('source');
            $table->string('archive_name')->nullable()->after('archive_path');
            $table->string('archive_mime', 120)->nullable()->after('archive_name');
            $table->longText('archive_text')->nullable()->after('archive_mime');
        });
    }

    public function down(): void
    {
        Schema::table('board_meetings', function (Blueprint $table) {
            $table->dropColumn(['source', 'archive_path', 'archive_name', 'archive_mime', 'archive_text']);
        });
    }
};
