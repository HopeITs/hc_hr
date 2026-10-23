<?php

namespace App\Services;

use App\Enums\ContractType as ContractTypeEnum;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * What a real contract starting does to its employee (B5, DEC-04, DEC-09;
 * docs/ux/employee-lifecycle.md §5.2 "C → A"):
 *
 *  - a complete volunteer awaiting their first contract becomes active;
 *  - an employee without a login account gets one (work email from the
 *    username, role "employee") and a password-setup email. This is also how
 *    existing staff without an account get one: at their next contract, never
 *    in bulk (B5D-Q8).
 *
 * Idempotent and serialized on the employee row: a retried or concurrent
 * contract save activates once and creates one account. A Draft-type
 * reservation does neither, and a draft employee is never activated.
 */
class EmployeeActivation
{
    public const RESULT_NONE = 'none';

    public const RESULT_ACCOUNT_CREATED = 'account-created';

    /** The username's work email is already another account's login. */
    public const RESULT_EMAIL_TAKEN = 'email-taken';

    public function activateForContract(Contract $contract): string
    {
        if ((int) $contract->type === ContractTypeEnum::Draft || ! $contract->is_active) {
            return self::RESULT_NONE;
        }

        $result = DB::transaction(function () use ($contract): array {
            $employee = Employee::query()->lockForUpdate()->find($contract->employee_id);

            if (! $employee || $employee->isDraft()) {
                return [self::RESULT_NONE, null];
            }

            if (! $employee->is_active) {
                $employee->is_active = true;
                $employee->digital_id_token ??= Str::random(64);
                $employee->save();
            }

            return $this->ensureAccount($employee);
        });

        [$outcome, $email] = $result;

        // After the commit: the account exists, so the link can be used.
        if ($outcome === self::RESULT_ACCOUNT_CREATED) {
            Password::sendResetLink(['email' => $email]);
        }

        return $outcome;
    }

    /** @return array{0: string, 1: ?string} */
    private function ensureAccount(Employee $employee): array
    {
        if ($employee->account()->exists()) {
            return [self::RESULT_NONE, null];
        }

        $username = strtolower(trim((string) $employee->username));

        if ($username === '') {
            return [self::RESULT_NONE, null];
        }

        $email = $username.'@hcsyria.org';

        if (User::query()->where('email', $email)->exists()) {
            Log::warning('Employee activation: work email already in use, no account created.', ['employee_id' => $employee->id]);

            return [self::RESULT_EMAIL_TAKEN, null];
        }

        $user = User::create([
            'email' => $email,
            'password' => Hash::make(Str::password(64)),
            'employee_id' => $employee->id,
        ]);

        if (Role::query()->where('name', 'employee')->exists()) {
            $user->assignRole('employee');
        }

        return [self::RESULT_ACCOUNT_CREATED, $email];
    }
}
