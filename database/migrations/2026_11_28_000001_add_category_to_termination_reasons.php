<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each termination reason (the list HR picks from on a contract) is grouped as
 * voluntary, involuntary, the natural end of a contract or other, so the
 * "Why people leave" report can say how many left by their own choice.
 * Null = not classified yet: the report asks HR to classify it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('termination_reasons', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('title_ar');
        });
    }

    public function down(): void
    {
        Schema::table('termination_reasons', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
