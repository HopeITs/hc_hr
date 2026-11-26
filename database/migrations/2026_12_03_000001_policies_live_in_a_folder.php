<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Policies live in one folder of the Folders module (PolicyFolder): every
 * employee reads it, HR manages it, and each PDF, Word or text file in it is
 * a policy. A policy keeps its file (media_id) and is read in the background:
 * read_status says where that stands and read_error why it failed.
 *
 * The folder itself is made on first use, not here, so a fresh database with
 * no users yet migrates cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->boolean('is_policy_library')->default(false)->after('inherits_access');
        });

        Schema::table('policy_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('media_id')->nullable()->unique()->after('id');
            $table->string('read_status', 10)->default('ready')->after('content')->comment('reading | ready | failed');
            $table->string('read_error', 20)->nullable()->after('read_status')->comment('busy | unavailable | unreadable');
        });
    }

    public function down(): void
    {
        Schema::table('policy_documents', function (Blueprint $table) {
            $table->dropUnique(['media_id']);
            $table->dropColumn(['media_id', 'read_status', 'read_error']);
        });

        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn('is_policy_library');
        });
    }
};
