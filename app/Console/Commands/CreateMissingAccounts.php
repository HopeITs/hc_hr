<?php

namespace App\Console\Commands;

use App\Enums\ContractType;
use App\Models\Contract;
use App\Models\Employee;
use App\Services\EmployeeActivation;
use Illuminate\Console\Command;

/**
 * Active employees without a login account (T9, HR decision 2026-11-26).
 *
 * B5D-Q8 still holds for the system itself: accounts are made by
 * EmployeeActivation when a contract starts, never by a scheduled sweep. This
 * is the one-off HR runs by hand for people who slipped through (the June
 * import, contracts saved before activation existed). It lists by default;
 * `--apply` creates each account the way a contract would (work email from
 * the username, role "employee" active, password-setup email).
 *
 * Someone without a username or a running contract is only listed: the first
 * has no work email to log in with, the second gets an account at their
 * contract.
 */
class CreateMissingAccounts extends Command
{
    protected $signature = 'employees:create-missing-accounts
        {--apply : Create the accounts and send the password-setup emails}';

    protected $description = 'List active employees without a login account, and create them with --apply';

    public function handle(EmployeeActivation $activation): int
    {
        $apply = (bool) $this->option('apply');
        $created = 0;

        $employees = Employee::query()
            ->completeRecords()
            ->where('employees.is_active', true)
            ->whereDoesntHave('account')
            ->orderBy('id')
            ->get();

        foreach ($employees as $employee) {
            $contract = Contract::query()
                ->activeNow()
                ->where('employee_id', $employee->id)
                ->where('type', '!=', ContractType::Draft)
                ->whereNull('terminated_at')
                ->latest('start_date')
                ->first();

            $label = sprintf('#%d %s', $employee->id, $employee->username ?: '(no username)');

            if (blank($employee->username)) {
                $this->line("{$label}: skipped, no username to make a work email from");

                continue;
            }

            if ($contract === null) {
                $this->line("{$label}: skipped, no running contract (the account comes with the contract)");

                continue;
            }

            if (! $apply) {
                $this->line("{$label}: would get ".strtolower($employee->username).'@hcsyria.org');

                continue;
            }

            $result = $activation->activateForContract($contract);
            $created += $result === EmployeeActivation::RESULT_ACCOUNT_CREATED ? 1 : 0;
            $this->line("{$label}: {$result}");
        }

        $this->info($apply
            ? "{$created} account(s) created."
            : $employees->count().' active employee(s) without an account. Nothing was written; run with --apply to create them.');

        return self::SUCCESS;
    }
}
