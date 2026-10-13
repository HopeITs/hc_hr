<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An applicant who signed in to apply for an internal campaign is one of our
 * employees; the link lets HR open their record from the application and
 * later tell an internal move from a new hire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_applicants', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('city_id');

            $table->index('employee_id', 'ra_employee_idx');
            $table->foreign('employee_id', 'ra_employee_fk')
                ->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_applicants', function (Blueprint $table) {
            $table->dropForeign('ra_employee_fk');
            $table->dropIndex('ra_employee_idx');
            $table->dropColumn('employee_id');
        });
    }
};
