<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A raised role (HR, general manager, administrator) stood down because its
 * holder no longer has a running contract (decided 2026-11-24). The row keeps
 * what was taken so it comes back by itself when a contract runs again
 * (App\Services\Access\OrgRoleSync).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_role_suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('reason', 40);
            $table->timestamp('suspended_at');
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_suspensions');
    }
};
