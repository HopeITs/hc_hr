<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * X2-I task-attempt telemetry. Deliberately no user, employee, city or record
 * reference, and no form content (DEC-10: anonymous). See config/telemetry.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('attempt_uuid')->unique();
            $table->string('task', 32);
            $table->string('role_context', 20);
            $table->string('locale', 5);
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('outcome', 16)->nullable();
            $table->boolean('had_validation_error')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attempts');
    }
};
