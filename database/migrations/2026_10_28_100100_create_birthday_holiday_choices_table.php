<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day a volunteer takes instead of a birthday that falls on a weekend or
 * another holiday (team-coverage-contract §6.1, H-4): one per holiday and
 * year, in the birthday's ISO week, approved by the direct manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birthday_holiday_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->date('chosen_date');
            $table->unsignedTinyInteger('status')->default(0);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['holiday_id', 'employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_holiday_choices');
    }
};
