<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Policies are uploaded by HR now (decided 2026-11-25); the Google Drive sync
 * is gone. The documents it copied are deleted (their acknowledgements go
 * with them, ON DELETE CASCADE) and so are its columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('policy_documents')->where('source', 'drive')->delete();

        Schema::table('policy_documents', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
        });

        Schema::table('policy_documents', function (Blueprint $table) {
            $table->dropColumn(['source', 'external_id', 'source_url', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::table('policy_documents', function (Blueprint $table) {
            $table->string('source', 16)->default('panel');
            $table->string('external_id')->nullable()->unique();
            $table->string('source_url')->nullable();
            $table->timestamp('synced_at')->nullable();
        });
    }
};
