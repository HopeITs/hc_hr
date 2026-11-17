<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the generated URL encrypted so HR can copy it again after logging out.
 *
 * The token itself stays hash-only (token_hash); what we add here is the full
 * URL, which is not a secret to authorized HR users — they could always share
 * it with anyone. Encrypting it at rest just keeps the careers URL out of any
 * plain-text backup that might reach an unauthorized reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_recruitment_links', function (Blueprint $table): void {
            $table->text('url_encrypted')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('public_recruitment_links', function (Blueprint $table): void {
            $table->dropColumn('url_encrypted');
        });
    }
};
