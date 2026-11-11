<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Undoes 2026_07_31_000004_encrypt_employee_pii_columns: PII encryption at
 * rest has been dropped from the application entirely, so the widened
 * columns and blind index are no longer needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['national_number_index']);
            $table->dropColumn('national_number_index');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->char('national_number', 255)->nullable()->change();
            $table->string('civil_registry_office')->nullable()->change();
            $table->string('civil_registry_number')->nullable()->change();
            $table->string('work_phone')->nullable()->change();
            $table->string('personal_phone')->nullable()->change();
            $table->string('personal_email')->nullable()->change();
            $table->string('whatsapp_work')->nullable()->change();
            $table->string('telegram_work')->nullable()->change();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->unique('national_number');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach ([
                'national_number', 'civil_registry_office', 'civil_registry_number',
                'work_phone', 'personal_phone', 'personal_email', 'whatsapp_work', 'telegram_work',
            ] as $column) {
                $table->text($column)->nullable()->change();
            }

            $table->string('national_number_index', 64)->nullable()->after('national_number');
            $table->unique('national_number_index');
        });
    }
};
