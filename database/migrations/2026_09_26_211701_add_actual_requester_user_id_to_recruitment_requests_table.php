<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recruitment_requests', function (Blueprint $table): void {
            $table->foreignId('actual_requester_user_id')
                ->nullable()
                ->after('actual_requester_name');
            $table->index('actual_requester_user_id', 'rr_actual_requester_user_idx');
            $table->foreign('actual_requester_user_id', 'rr_actual_requester_user_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recruitment_requests', function (Blueprint $table): void {
            $table->dropForeign('rr_actual_requester_user_fk');
            $table->dropIndex('rr_actual_requester_user_idx');
            $table->dropColumn('actual_requester_user_id');
        });
    }
};
