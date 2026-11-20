<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each person's own chat settings (the ⚙ in the chats list). One row per
 * user, created the first time they change something; no row means the
 * defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messenger_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->boolean('sound')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messenger_settings');
    }
};
