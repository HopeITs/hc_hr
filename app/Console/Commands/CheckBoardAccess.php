<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\User;
use App\Services\Board\BoardAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Says why a person does or does not see the board's pages on this host, and (with --chair / --secretary)
 * gives them the chair's or the secretary's permission. Run it on the server after a deploy.
 */
class CheckBoardAccess extends Command
{
    protected $signature = 'board:check {email : The person\'s login email} {--chair : Give board-meetings.manage} {--secretary : Give board-meetings.write-minutes}';

    protected $description = 'Check (and optionally grant) a person\'s access to the board meetings';

    public function handle(BoardAccess $access): int
    {
        $tables = Schema::hasTable('board_meetings');
        $this->line('board tables migrated: '.($tables ? 'yes' : 'NO: run php artisan migrate'));

        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No account with that email on this database.');

            return self::FAILURE;
        }

        foreach (array_filter([$this->option('chair') ? BoardAccess::MANAGE : null, $this->option('secretary') ? BoardAccess::WRITE : null]) as $name) {
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
            $this->info("Granted {$name}.");
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();

        $employee = $user->profile;
        $this->line('employee: '.($employee?->name ?? '— none —').'; is_board_member='.(int) $employee?->is_board_member
            .'; started='.($employee?->board_member_started_at?->toDateString() ?? '—').'; ended='.($employee?->board_member_end_at?->toDateString() ?? '—'));
        $this->line('current board member: '.($access->isMember($user) ? 'yes' : 'no'));
        $this->line('chair (manage): '.($access->canManage($user) ? 'yes' : 'no').'; secretary (write minutes): '.($access->canWrite($user) ? 'yes' : 'no'));
        $this->line('page opens: '.($access->canView($user) ? 'yes' : 'no').'; menu entry: '.($access->inMenu($user) ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
