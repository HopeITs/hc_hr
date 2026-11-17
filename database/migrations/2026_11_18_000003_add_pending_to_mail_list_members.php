<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_list_members', function (Blueprint $table) {
            // 'active': already in the group at the mail provider's control panel.
            // 'pending': added here, waiting for whoever holds the control panel to add them there.
            $table->string('status', 16)->default('active')->after('email');
            $table->foreignId('added_by_user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_user_id')->nullable()->after('added_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by_user_id');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('mail_list_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_by_user_id');
            $table->dropConstrainedForeignId('confirmed_by_user_id');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'confirmed_at']);
        });
    }
};
