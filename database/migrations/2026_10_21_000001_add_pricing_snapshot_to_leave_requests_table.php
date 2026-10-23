<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pricing snapshot of a leave request (A3-D decisions D-5 and D-7).
     *
     * - computed_hours / rule_version: the hours LeaveRequestPricing priced the
     *   request at when it was created, and the rule set that priced it. A
     *   pending or approved amount is then immune to later changes of
     *   weekends, holidays or contract hours.
     * - is_exceptional: the requester marked a December leave as exceptional
     *   (D-7); it still needs manager and HR approval.
     *
     * Existing rows are deliberately NOT backfilled: historical requests keep
     * the legacy rule (LeaveRequestPricing::quoteLegacy()) that produced the
     * deduction already in leave_balances.used. A null computed_hours means
     * "priced before this snapshot existed".
     *
     * Only columns are added (no change()), so SQLite does not rebuild the
     * table.
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->decimal('computed_hours', 8, 2)->nullable()->after('end_at');
            $table->string('rule_version', 32)->nullable()->after('computed_hours');
            $table->boolean('is_exceptional')->default(false)->after('rule_version');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['computed_hours', 'rule_version', 'is_exceptional']);
        });
    }
};
