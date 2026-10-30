<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Each person's own dashboard, per role they work under: which tiles they
 * show, in what order and size, which are pinned. Kept on the server so the
 * layout follows them from the office computer to the phone. Every stored
 * layout is cleaned by App\Support\Dashboard\DashboardLayout before use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->comment('The active role name as stored (HR-ALP, HR-SYR, manager...), not collapsed');
            $table->json('layout')->nullable()->comment('[{key, size: CxR, pinned, hidden, style?}] in display order');
            $table->timestamps();

            $table->unique(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_layouts');
    }
};
