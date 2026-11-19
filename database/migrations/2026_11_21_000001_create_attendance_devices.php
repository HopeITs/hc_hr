<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The time-clock devices that push their punches to the system (ZKTeco ADMS "Cloud Server"):
 *  - attendance_devices: one per physical device, found by its serial number and tied to the
 *    center it hangs in, with when it was last heard from and the position it reached in its log.
 *  - attendance_device_sightings: a serial that called but is not registered yet, so HR can add it
 *    with one click instead of copying a serial by hand.
 * Punches and imports remember the device they came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->string('serial', 64)->unique();
            $table->string('name', 120)->nullable();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            // active: its punches are taken in. blocked: it is refused until unblocked.
            $table->string('status', 16)->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->dateTime('last_punch_at')->nullable();
            // The device's own position in its attendance log, handed back to it so it sends only what is new.
            $table->string('attlog_stamp', 32)->nullable();
            $table->string('push_version', 32)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_device_sightings', function (Blueprint $table) {
            $table->id();
            $table->string('serial', 64)->unique();
            $table->string('last_ip', 45)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::table('attendance_imports', function (Blueprint $table) {
            $table->string('source', 16)->default('file')->after('file_name');
            $table->foreignId('device_id')->nullable()->after('source')->constrained('attendance_devices')->nullOnDelete();
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('import_id')->constrained('attendance_devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        });

        Schema::table('attendance_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
            $table->dropColumn('source');
        });

        Schema::dropIfExists('attendance_device_sightings');
        Schema::dropIfExists('attendance_devices');
    }
};
