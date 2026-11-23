<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Study Zone print toggle: "Friday is considered a day off…", the
            // day taken from the centers' weekend. Off by default, so contracts
            // printed before it existed keep reading as they were signed.
            $table->boolean('show_weekend_clause')->default(false)->after('show_day_range');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('show_weekend_clause');
        });
    }
};
