<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The life of one person's month: confirmed by the employee (optional), approved by the
 * manager (which approves the overtime), locked by HR (which gives the overtime back as free
 * days) and the signed paper kept with it. Also the hours of overtime credit an annual leave
 * balance holds, kept apart from what the contract earns so a recalculation never loses them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('month'); // the first day of the month
            $table->timestamp('employee_confirmed_at')->nullable();
            $table->foreignId('employee_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('overtime_hours_approved', 6, 2)->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('credited_hours', 6, 2)->default(0);
            $table->foreignId('credited_balance_id')->nullable()->constrained('leave_balances')->nullOnDelete();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'month']);
        });

        Schema::table('leave_balances', function (Blueprint $table) {
            $table->decimal('overtime_credit', 8, 2)->default(0)->after('amendment_offset');
        });

        // Punches of a locked month are not taken in: a locked timesheet never changes by itself.
        Schema::table('attendance_imports', function (Blueprint $table) {
            $table->unsignedInteger('punches_locked')->default(0)->after('punches_new');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_imports', function (Blueprint $table) {
            $table->dropColumn('punches_locked');
        });

        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropColumn('overtime_credit');
        });

        Schema::dropIfExists('attendance_months');
    }
};
