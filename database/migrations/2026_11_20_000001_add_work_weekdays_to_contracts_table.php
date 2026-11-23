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
            // The days actually worked, picked one by one ("0,1,3,4,6", Carbon
            // numbering, Sunday = 0) instead of the from–to range, for a week
            // a range cannot say: Study Zone's center day off plus a second
            // day the volunteer chooses. Every other day is a day off for
            // leave. Null = the range and the center's weekend, as before.
            $table->string('work_weekdays', 20)->nullable()->after('work_day_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('work_weekdays');
        });
    }
};
