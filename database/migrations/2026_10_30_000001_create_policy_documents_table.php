<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The organisation's policies as plain text, which is what the AI assistant
 * answers from. Today they are copied from a Google Drive folder by
 * `policies:sync`; later they will be written here from the admin panel, and
 * the assistant reads this table either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_documents', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16)->default('drive')->comment('drive | panel');
            $table->string('external_id')->nullable()->unique()->comment('Google Drive file id');
            $table->string('title');
            $table->string('mime_type')->nullable();
            $table->string('source_url')->nullable();
            $table->longText('content')->nullable();
            $table->timestamp('source_modified_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_documents');
    }
};
