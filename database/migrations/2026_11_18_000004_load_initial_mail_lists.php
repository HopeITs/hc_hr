<?php

use App\Models\MailList;
use App\Services\MailListImporter;
use Illuminate\Database\Migrations\Migration;

/**
 * The organisation's mailing lists, as they stood in the "Mail Lists" sheet
 * (database/data/mail-lists.json), loaded once when no list exists yet. Every
 * member loaded this way is already in the group at the mail provider, so they
 * are 'active'; only people added afterwards wait as 'pending'. Re-run by hand
 * with `php artisan mail-lists:load`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A test database starts empty on purpose; production and development get the lists.
        if (app()->environment('testing') || MailList::query()->exists()) {
            return;
        }

        app(MailListImporter::class)->importFile(database_path('data/mail-lists.json'));
    }

    public function down(): void
    {
        // The lists are data, not structure: rolling back the tables removes them.
    }
};
