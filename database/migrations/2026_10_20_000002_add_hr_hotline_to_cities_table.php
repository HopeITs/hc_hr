<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fixed HR hotline per city, set by HR (docs/ux/access-policy.md §9
 * A2D-Q7). The public digital-ID card shows it as the emergency number and
 * falls back to the first active HR-department employee's work phone in the
 * city while it is empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->string('hr_hotline', 32)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('hr_hotline');
        });
    }
};
