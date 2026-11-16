<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance, kept to what a timesheet needs:
 *  - attendance_imports: one row per uploaded device report.
 *  - attendance_punches: every punch, once (device number + minute is unique), so the same
 *    file or an overlapping one can be uploaded again safely.
 *  - attendance_device_links: a device number tied to an employee by hand, for a number
 *    that is not the employee's ID.
 *  - attendance_day_decisions: what HR decided for a working day that has no record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('punches_total')->default(0);
            $table->unsignedInteger('punches_new')->default(0);
            $table->unsignedInteger('device_numbers')->default(0);
            $table->timestamps();
        });

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('attendance_imports')->cascadeOnDelete();
            $table->string('device_no', 32);
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('punched_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['device_no', 'punched_at']);
            $table->index(['employee_id', 'punched_at']);
        });

        Schema::create('attendance_device_links', function (Blueprint $table) {
            $table->id();
            $table->string('device_no', 32)->unique();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_day_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('kind', 16); // worked | absent
            $table->string('note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_day_decisions');
        Schema::dropIfExists('attendance_device_links');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_imports');
    }
};
