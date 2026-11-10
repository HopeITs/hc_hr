<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Spatie\Permission\Models\Role;

/**
 * Sign-in accounts for trying the app under each role on a local copy
 * (php artisan db:seed --class=LocalTestAccountsSeeder). Local only: it
 * refuses any other environment. Safe to run again: it updates the same
 * accounts. The password is LOCAL_TEST_ACCOUNTS_PASSWORD in .env; the list
 * is in LOCAL-TEST-ACCOUNTS.md.
 *
 * Sign-in needs an active employee record (Auth\Login), so each account is
 * linked to an active employee: one who already holds that role, or one in
 * that role's city. The account then sees that person's own pages too.
 */
class LocalTestAccountsSeeder extends Seeder
{
    /** email => [roles (the first is the one active after sign-in), employee id] */
    public const ACCOUNTS = [
        'super-admin@hopehr.test' => [['super-admin'], 1],
        'general-manager@hopehr.test' => [['general-manager'], 1],
        'hr-syr@hopehr.test' => [['HR-SYR'], 11],
        'hr-alp@hopehr.test' => [['HR-ALP'], 1],
        'hr-dms@hopehr.test' => [['HR-DMS'], 346],
        'hr-hms@hopehr.test' => [['HR-HMS'], 192],
        'hr-lat@hopehr.test' => [['HR-LAT'], 539],
        'hr-tar@hopehr.test' => [['HR-TAR'], 315],
        'manager@hopehr.test' => [['manager'], 3],
        'employee@hopehr.test' => [['employee'], 14],
        // Several roles, to try the role switcher.
        'multi-role@hopehr.test' => [['manager', 'HR-ALP', 'employee'], 3],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new LogicException('Local test accounts are only for a local environment.');
        }

        $password = (string) env('LOCAL_TEST_ACCOUNTS_PASSWORD', '');

        if (strlen($password) < 12) {
            throw new LogicException('Set LOCAL_TEST_ACCOUNTS_PASSWORD (12 characters or more) in .env first.');
        }

        foreach (self::ACCOUNTS as $email => [$roles, $employeeId]) {
            if (! Employee::query()->whereKey($employeeId)->where('is_active', true)->exists()) {
                $this->command?->warn("{$email}: employee {$employeeId} is not active here; skipped.");

                continue;
            }

            $user = User::query()->updateOrCreate(['email' => $email], [
                'employee_id' => $employeeId,
                'password' => Hash::make($password),
            ]);

            $user->syncRoles($roles);

            // The first role is the active one (sign-in picks again; this is for a fresh session).
            $first = Role::findByName($roles[0], 'web');
            $user->roles()->updateExistingPivot($user->roles()->pluck('roles.id')->all(), ['is_active' => false]);
            $user->roles()->updateExistingPivot($first->id, ['is_active' => true]);

            $this->command?->info("{$email}: ".implode(', ', $roles));
        }
    }
}
