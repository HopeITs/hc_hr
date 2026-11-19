<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The in-site chat: private conversations between two people and groups.
 *
 * Message text, attachment names and attachment contents are encrypted at
 * rest (the text with the app's encrypter, files by ChatFileVault). Search
 * runs on messenger_search_tokens: keyed hashes of normalised three-letter pieces,
 * never the words themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messenger_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16); // private | group
            $table->string('title', 128)->nullable(); // groups only
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            // "smallerUserId:largerUserId" for a private chat, so the same two
            // people can never end up with two of them.
            $table->string('direct_key', 64)->nullable()->unique();
            // Kept by ChatService; no foreign key, because messages reference
            // conversations and the two tables would point at each other.
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('messenger_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('messenger_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16)->default('member'); // owner | admin | member
            // Administrator rights (Telegram's model); ignored unless role = admin.
            $table->boolean('can_change_info')->default(false);
            $table->boolean('can_delete_messages')->default(false);
            $table->boolean('can_remove_members')->default(false);
            $table->boolean('can_add_members')->default(false);
            $table->boolean('can_add_admins')->default(false);
            $table->foreignId('promoted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'left_at']);
        });

        Schema::create('messenger_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('messenger_conversations')->cascadeOnDelete();
            // Null for service messages ("Rana created the group").
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 16)->default('text'); // text | file | service
            $table->text('body')->nullable(); // encrypted
            // Service messages: what happened and to whom, as ids. No free text.
            $table->json('meta')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('messenger_messages')->nullOnDelete();
            $table->foreignId('forwarded_from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('forwarded_from_message_id')->nullable()->constrained('messenger_messages')->nullOnDelete();
            $table->boolean('forward_hidden')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });

        // "Delete for me": the message stays for everyone else.
        Schema::create('messenger_message_hidden', function (Blueprint $table) {
            $table->foreignId('message_id')->constrained('messenger_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['message_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('messenger_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messenger_messages')->cascadeOnDelete();
            // A random name on the chat-files disk; tells nothing about the file.
            $table->string('path', 191);
            $table->text('original_name'); // encrypted
            $table->string('mime', 127);
            $table->unsignedBigInteger('size'); // plaintext bytes
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('thumbnail_path', 191)->nullable(); // encrypted too
            $table->timestamps();
        });

        Schema::create('messenger_search_tokens', function (Blueprint $table) {
            $table->foreignId('message_id')->constrained('messenger_messages')->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id');
            // Hex of a 16-byte keyed hash; hex rather than raw bytes so no
            // connection charset can mangle it.
            $table->char('token', 32);

            $table->index(['conversation_id', 'token']);
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messenger_search_tokens');
        Schema::dropIfExists('messenger_attachments');
        Schema::dropIfExists('messenger_message_hidden');
        Schema::dropIfExists('messenger_messages');
        Schema::dropIfExists('messenger_participants');
        Schema::dropIfExists('messenger_conversations');
    }
};
