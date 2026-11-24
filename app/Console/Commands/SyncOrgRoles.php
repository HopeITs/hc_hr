<?php

namespace App\Console\Commands;

use App\Services\Access\OrgRoleSync;
use Illuminate\Console\Command;

/**
 * Nightly, after the contract runs (21:30–21:50): give every employee the
 * employee role, give or take `manager` by the org chart, and stand raised
 * roles down (or back up) by whether their holder has a running contract.
 * Also the deploy step that brings existing users in line. See OrgRoleSync.
 */
class SyncOrgRoles extends Command
{
    protected $signature = 'access:sync-org-roles';

    protected $description = 'Align the employee and manager roles with the org chart, and suspend raised roles without a running contract';

    public function handle(OrgRoleSync $sync): int
    {
        $totals = $sync->syncAll();

        $this->table(['Change', 'Users'], collect($totals)->map(fn ($n, $kind) => [$kind, $n])->values()->all());

        return self::SUCCESS;
    }
}
