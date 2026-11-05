<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hours a contract amendment's leave conversion moved a balance away from what
 * pricing the contract from scratch on its new working day gives
 * (LeaveBalanceService::rescaleForDailyHours). Recalculation adds it back, so a
 * converted balance survives a later reprice — an edited end date, say —
 * instead of that reprice being skipped. Zero for a balance never converted.
 *
 * Only a column is added (no change()), so SQLite does not rebuild the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->float('amendment_offset')->default(0)->after('used');
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropColumn('amendment_offset');
        });
    }
};
