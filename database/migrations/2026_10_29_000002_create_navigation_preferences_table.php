<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Each person's way through the menu, kept on the server so it follows them
 * from the office computer to the phone: the pages they starred and, most
 * recent first, the pages and colleagues' profiles they opened. One row per
 * user, apart from `users`, so the row read on every request stays narrow.
 * Nothing stored here grants anything: App\Support\NavigationMemory filters
 * every list through the active role's menu and the directory scope on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->json('favourites')->nullable()->comment('Menu item ids (Navigation), in the order shown, at most 8');
            $table->json('recent_pages')->nullable()->comment('Menu item ids, most recent first');
            $table->json('recent_people')->nullable()->comment('employees.id, most recent first');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_preferences');
    }
};
