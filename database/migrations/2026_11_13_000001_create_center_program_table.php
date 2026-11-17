<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The programs a center serves, set by HR on the center. A center may serve
 * several (a Hope Center hosting Micro Project and others); a Study Zone
 * center serves Study Zone only.
 *
 * Read when a position has no center of its own (every Study Zone position):
 * leave pricing then takes the weekend and working hours from the centers of
 * the position's program in its city, and the center's manager list adds that
 * program's project manager in the city (Center::managerContracts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_program', function (Blueprint $table) {
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->primary(['center_id', 'program_id']);
            $table->index('program_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_program');
    }
};
