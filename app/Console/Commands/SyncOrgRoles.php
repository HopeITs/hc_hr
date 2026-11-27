<?php

namespace App\Console\Commands;

use App\Services\Access\OrgRoleSync;
use App\Services\Access\SeatSuccession;
use Illuminate\Console\Command;

/**
 * Nightly, after the contract runs (21:30–21:50): give every employee the
 * employee role, give or take `manager` by the org chart, and stand raised
 * roles down (or back up) by whether their holder has a running contract,
 * and give a position's raised roles to whoever took it over. Then hand what
 * people who left still held (items to hand over or take back, their place
 * in the delivery list) to whoever holds their position now. Also the deploy
 * step that brings existing users in line. See OrgRoleSync, SeatSuccession.
 */
class SyncOrgRoles extends Command
{
    protected $signature = 'access:sync-org-roles';

    protected $description = 'Align roles with the org chart and contracts, and hand what people who left held to whoever took their position';

    public function handle(OrgRoleSync $sync, SeatSuccession $succession): int
    {
        $totals = $sync->syncAll();

        $this->table(['Change', 'Users'], collect($totals)->map(fn ($n, $kind) => [$kind, $n])->values()->all());

        $moved = $succession->handOverOpenWork();

        $this->table(['Handed over', 'Rows'], collect($moved)->map(fn ($n, $kind) => [$kind, $n])->values()->all());

        return self::SUCCESS;
    }
}
