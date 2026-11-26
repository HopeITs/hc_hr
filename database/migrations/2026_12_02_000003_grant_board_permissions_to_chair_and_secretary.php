<?php

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * The board's chair (Freddy) holds board-meetings.manage and its secretary (Fadi) holds
 * board-meetings.write-minutes from the first deploy, by their login emails in config/board.php.
 * An account that does not exist on this database is skipped; the migration only adds, so
 * nothing handed out later from Access Management is taken back.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'board.chair_email' => 'board-meetings.manage',
            'board.secretary_email' => 'board-meetings.write-minutes',
        ] as $configKey => $permission) {
            $email = config($configKey);
            $user = $email ? User::query()->where('email', $email)->first() : null;

            if ($user) {
                $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Left as they are: the permissions may have been granted or kept by hand since.
    }
};
