<?php

namespace App\Console\Commands;

use App\Enums\ContractType;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Notifications\EmployeeRecordsDisagree;
use App\Services\AccessRevoker;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * People, contracts, roles and positions that disagree (T8, scenario run
 * 2026-11-26). Five checks:
 *
 *  - roles        an inactive employee whose account still has an active role.
 *                 EmployeeObserver stands roles down on deactivation since
 *                 2026-07-31; older deactivations and the June import did not.
 *                 The only case repaired here (AccessRevoker), because it is
 *                 access, not an HR decision.
 *  - ended        a contract still flagged active after its end date or its
 *                 termination date (a future-dated termination keeps the
 *                 contract running until then, R2-3). The nightly
 *                 deactivate:contract (21:30) closes them; one left here
 *                 means that run did not happen.
 *  - inactive     a running contract of an inactive employee: it holds a seat
 *                 for someone who left. Deactivation is now refused while a
 *                 contract runs (EmployeeTable); older ones are reported only
 *                 and HR decides each (HR decision 2026-11-26).
 *  - no_contract  an active employee with no running or future contract,
 *                 split into "never had one" and "last one ended" (the latter
 *                 waits for employees:deactivate-departed's grace period).
 *  - no_manager   an active position without a direct manager, other than
 *                 config('contracts.top_positions').
 *
 * `--check` is the nightly guard (routes/console.php): it writes nothing,
 * logs what it found, tells HR Syria when the findings changed since the last
 * notice, and exits 1 when there is any.
 */
class CheckEmployeeIntegrity extends Command
{
    public const NOTICE_CACHE_KEY = 'employees:integrity-check:last-notice';

    protected $signature = 'employees:integrity-check
        {--check : Write nothing; log, tell HR Syria and exit 1 when anything disagrees}';

    protected $description = 'List employees, contracts, roles and positions that disagree; stand down roles of inactive employees';

    public function handle(AccessRevoker $revoker): int
    {
        $check = (bool) $this->option('check');
        $findings = $this->findings();

        $this->report($findings);

        if (! $check) {
            foreach ($findings['roles'] as $userId) {
                $revoker->revoke(User::query()->findOrFail($userId), 'integrity_repair');
            }

            $this->info(count($findings['roles']).' inactive employee account(s) stood down. Everything else is for HR to decide.');

            return self::SUCCESS;
        }

        if (array_filter($findings) === []) {
            Cache::forget(self::NOTICE_CACHE_KEY);
            $this->info('Employees, contracts, roles and positions agree.');

            return self::SUCCESS;
        }

        Log::warning('employees:integrity-check found records that disagree', $findings);
        $this->notifyHrSyria($findings);

        return self::FAILURE;
    }

    /**
     * @return array{roles: list<int>, ended: list<int>, inactive: list<int>, never_contracted: list<int>, last_contract_ended: list<int>, no_manager: list<int>}
     */
    public function findings(): array
    {
        $today = today()->toDateString();

        $running = fn (Builder $q): Builder => $q
            ->where('type', '!=', ContractType::Draft)
            ->whereNull('terminated_at')
            ->activeNow();

        $roles = User::query()
            ->whereHas('profile', fn (Builder $q) => $q->where('is_active', false))
            ->whereHas('roles', fn (Builder $q) => $q->where('model_has_roles.is_active', true))
            ->orderBy('id')
            ->pluck('id');

        $ended = Contract::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->whereDate('end_date', '<', $today)
                ->orWhereDate('terminated_at', '<', $today))
            ->orderBy('id')
            ->pluck('id');

        $inactive = Contract::query()
            ->tap($running)
            ->whereHas('employee', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy('id')
            ->pluck('id');

        $withoutContract = Employee::query()
            ->completeRecords()
            ->where('employees.is_active', true)
            ->whereDoesntHave('realContracts', $running)
            ->whereDoesntHave('realContracts', fn (Builder $q) => $q
                ->whereNull('terminated_at')
                ->whereDate('start_date', '>', $today))
            ->withExists('realContracts as has_real_contract')
            ->orderBy('id')
            ->get(['employees.id']);

        $noManager = Position::query()
            ->where('is_active', true)
            ->whereNull('direct_manager_id')
            ->whereNotIn('title_en', config('contracts.top_positions', []))
            ->orderBy('id')
            ->pluck('id');

        return [
            'roles' => $this->ints($roles),
            'ended' => $this->ints($ended),
            'inactive' => $this->ints($inactive),
            'never_contracted' => $this->ints($withoutContract->reject->has_real_contract->pluck('id')),
            'last_contract_ended' => $this->ints($withoutContract->filter->has_real_contract->pluck('id')),
            'no_manager' => $this->ints($noManager),
        ];
    }

    /** @param  array<string, list<int>>  $findings */
    private function report(array $findings): void
    {
        $labels = [
            'roles' => 'Inactive employees whose account still has an active role (user ids)',
            'ended' => 'Contracts flagged active after their end or termination date',
            'inactive' => 'Running contracts of inactive employees',
            'never_contracted' => 'Active employees who never had a contract',
            'last_contract_ended' => 'Active employees whose last contract ended',
            'no_manager' => 'Active positions without a direct manager',
        ];

        foreach ($labels as $kind => $label) {
            $ids = $findings[$kind];
            $this->line(sprintf('%s: %d%s', $label, count($ids), $ids === [] ? '' : ' — #'.implode(', #', $ids)));
        }
    }

    /**
     * Once per distinct set of findings: the same list every night is not news,
     * a new or a cleared case is.
     *
     * @param  array<string, list<int>>  $findings
     */
    private function notifyHrSyria(array $findings): void
    {
        $signature = sha1(json_encode($findings));

        if (Cache::get(self::NOTICE_CACHE_KEY) === $signature) {
            return;
        }

        $recipients = User::query()
            ->whereHas('roles', fn (Builder $q) => $q->where('name', 'HR-SYR'))
            ->whereHas('profile', fn (Builder $q) => $q->where('is_active', true))
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new EmployeeRecordsDisagree(array_map('count', $findings)));
        }

        Cache::forever(self::NOTICE_CACHE_KEY, $signature);
    }

    /** @return list<int> */
    private function ints(iterable $ids): array
    {
        return collect($ids)->map(fn ($id) => (int) $id)->values()->all();
    }
}
