<?php

use App\Models\MailList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which people a mailing list is for, so the system can say who should be on it and who should not.
 *  - rule_type 'all_staff': everyone with a contract running today;
 *  - 'board': the board members;
 *  - 'custom': people whose position matches every filter that is set (city, project, department),
 *    and, when rule_managers_only is on, who lead a team.
 * A list with no rule is not checked. The system never changes the group at the mail provider: it
 * only reports, and queues additions for the person who holds the control panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_lists', function (Blueprint $table) {
            $table->string('rule_type', 16)->nullable()->after('position');
            $table->foreignId('rule_city_id')->nullable()->after('rule_type')->constrained('cities')->nullOnDelete();
            $table->foreignId('rule_program_id')->nullable()->after('rule_city_id')->constrained('programs')->nullOnDelete();
            $table->foreignId('rule_department_id')->nullable()->after('rule_program_id')->constrained('departments')->nullOnDelete();
            $table->boolean('rule_managers_only')->default(false)->after('rule_department_id');
        });

        // The three whose meaning is not in doubt. The rest are set by HR from the page.
        MailList::query()->where('address', MailList::ALL_STAFF_ADDRESS)->update(['rule_type' => MailList::RULE_ALL_STAFF]);
        MailList::query()->where('address', 'board@'.MailList::DOMAIN)->update(['rule_type' => MailList::RULE_BOARD]);
        MailList::query()->where('address', 'managers@'.MailList::DOMAIN)->update(['rule_type' => MailList::RULE_CUSTOM, 'rule_managers_only' => true]);
    }

    public function down(): void
    {
        Schema::table('mail_lists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rule_city_id');
            $table->dropConstrainedForeignId('rule_program_id');
            $table->dropConstrainedForeignId('rule_department_id');
            $table->dropColumn(['rule_type', 'rule_managers_only']);
        });
    }
};
