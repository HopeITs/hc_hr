<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * B5 (DEC-04, B5-D): an employee record is either a draft (incomplete, being
 * entered by HR) or complete. Additive only: every existing row becomes
 * 'complete' through the default, and nothing else about it changes
 * (is_active, accounts and contracts are untouched; docs/ux/employee-lifecycle.md §7).
 *
 * Rollback: the columns can simply be dropped. A draft is always stored with
 * is_active = false, so code that predates this column already treats it as
 * inactive and never as staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('record_status', 16)->default('complete')->index();
            // Who saved the record last, for "Saved at hh:mm by …" (B5D-Q10).
            $table->foreignId('last_saved_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_saved_by');
            $table->dropIndex(['record_status']);
            $table->dropColumn('record_status');
        });
    }
};
