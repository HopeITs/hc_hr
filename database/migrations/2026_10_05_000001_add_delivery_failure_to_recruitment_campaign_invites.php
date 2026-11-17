<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why an invitation's email did not arrive, kept on the invitation.
 *
 * Not a new status: an invitation whose email bounced is still a valid
 * invitation (HR can pass the link on by hand), and everything that decides
 * who may apply reads `pending` and `sent`. So the failure sits beside the
 * status, as a short code the screens translate; the server's own wording goes
 * to the log, not to HR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_campaign_invites', function (Blueprint $table) {
            $table->dateTime('failed_at')->nullable()->after('sent_at');
            $table->string('failure_reason', 60)->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_campaign_invites', function (Blueprint $table) {
            $table->dropColumn(['failed_at', 'failure_reason']);
        });
    }
};
