<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The AI assistant's conversations, one row per chat, so an employee can
 * start a new one and go back to earlier ones. The messages may quote the
 * employee's own record and pay, so they are stored encrypted (the model
 * casts them); only the title (the first question) is plain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->longText('messages')->nullable()->comment('encrypted JSON');
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_conversations');
    }
};
