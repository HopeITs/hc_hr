<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sync key per device, for the small agent that reads a device on its local network and sends its
 * punches here (tools/attendance-agent). Only a hash of the key is kept: the key itself is shown once, when
 * HR creates it, and cannot be read back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->string('agent_key_hash', 64)->nullable()->unique()->after('push_version');
            // The last four characters, so HR can tell which key a computer holds.
            $table->string('agent_key_hint', 8)->nullable()->after('agent_key_hash');
            $table->timestamp('agent_key_created_at')->nullable()->after('agent_key_hint');
            $table->timestamp('agent_last_sync_at')->nullable()->after('agent_key_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->dropUnique(['agent_key_hash']);
            $table->dropColumn(['agent_key_hash', 'agent_key_hint', 'agent_key_created_at', 'agent_last_sync_at']);
        });
    }
};
