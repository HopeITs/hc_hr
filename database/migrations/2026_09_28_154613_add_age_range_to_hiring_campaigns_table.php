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
        Schema::table('hiring_campaigns', function (Blueprint $table) {
            $table->unsignedTinyInteger('minimum_age')->nullable()->after('link_mode');
            $table->unsignedTinyInteger('maximum_age')->nullable()->after('minimum_age');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hiring_campaigns', function (Blueprint $table) {
            $table->dropColumn(['minimum_age', 'maximum_age']);
        });
    }
};
