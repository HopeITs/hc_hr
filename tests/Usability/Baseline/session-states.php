<?php

/*
 * X2 usability baseline: add the session states the task scripts need
 * (docs/ux/x2/task-scripts.md) on top of X1's synthetic database.
 *
 *   php -d extension=pdo_sqlite tests/Usability/Baseline/session-states.php
 *
 * Run it after building X1's fixture into X2's own home:
 *
 *   X2_HOME=<dir outside repo> php -d extension=pdo_sqlite -r "require 'tests/Usability/Baseline/x2-home.php'; require 'tests/Performance/Baseline/build-fixtures.php';"
 *
 * For each session city (ALP, HMS) it creates or names:
 *   - Task A: the staff persona, with a fresh annual balance;
 *   - Task B: a target employee whose surname at least two others in the city share;
 *   - Task C: a manager persona, one pending request from a direct report (the
 *     target) and two more pending requests from other reports;
 *   - Task D: an employee with no contract, and a vacant position;
 *   - Task D': an employee holding a Draft-type reservation.
 * It writes <X2_HOME>/x2-session-manifest.json, which the moderator uses to
 * fill the scenario cards. All people are synthetic; nothing leaves the
 * session database.
 */

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\LeaveRequestStatus;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Notifications\NewLeaveRequest;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/x2-home.php';
require dirname(__DIR__, 2).'/Performance/Baseline/env.php';

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
perf_configure($app);
$app->make(Kernel::class)->bootstrap();
perf_assert_isolated();

if (! User::query()->where('email', 'hr.alp@example.test')->exists()) {
    throw new RuntimeException('Build the X1 fixture into X2_HOME first (see the header of this file).');
}

mt_srand(20261019);
$today = Carbon::today();
$password = Hash::make('perf-baseline');
$log = fn (string $line) => fwrite(STDOUT, $line."\n");

/** Replace a user's roles with exactly one active role. */
$setRole = function (User $user, string $roleName): void {
    $role = Role::findByName($roleName, 'web');
    DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $user->id)->delete();
    DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_type' => User::class, 'model_id' => $user->id, 'is_active' => true]);
};

$accountFor = function (Employee $employee, string $email) use ($password): User {
    $user = User::query()->firstWhere('employee_id', $employee->id)
        ?? User::query()->forceCreate(['employee_id' => $employee->id, 'email' => $email, 'password' => $password, 'email_verified_at' => now()]);
    $user->forceFill(['email' => $email])->save();

    return $user;
};

$currentContract = fn (Employee $e): ?Contract => Contract::query()->activeNow()->where('employee_id', $e->id)->latest('start_date')->first();

// Contracts started too recently have no deserved annual balance yet; give
// the session personas one, in the session database only.
$annualBalance = fn (Contract $c): LeaveBalance => LeaveBalance::query()->where('contract_id', $c->id)->where('type_id', 1)->first()
    ?? LeaveBalance::query()->forceCreate(['contract_id' => $c->id, 'type_id' => 1, 'deserved' => 80, 'used' => 0]);

/** The next working day at least $from days ahead that is not a weekend at the contract's center and not in December. */
// centers.weekends holds day-of-week numbers ("0,6"), read the same way as
// LeaveRequestForm, including its '0,6' fallback for a contract without a center.
$workingDay = function (Contract $contract, int $from, ?Carbon $base = null) use ($today): Carbon {
    $weekends = array_map('intval', explode(',', $contract->position?->center?->weekends ?? '0,6'));
    $day = ($base ?? $today)->copy()->startOfDay()->addDays($from);
    while (in_array($day->dayOfWeek, $weekends, true) || $day->month === 12) {
        $day->addDay();
    }

    return $day;
};

$name = fn (Employee $e) => ['en' => trim("{$e->first_name_en} {$e->last_name_en}"), 'ar' => trim("{$e->first_name_ar} {$e->last_name_ar}")];
$title = fn (?Position $p) => $p ? ['en' => $p->title_en, 'ar' => $p->title_ar] : null;

$manifest = ['built_at' => now()->toIso8601String(), 'x2_home' => getenv('PERF_HOME'), 'password' => 'perf-baseline', 'cities' => []];
$used = collect([]);   // employee ids already given a role in the scripts
$managerPositions = collect([]);

foreach (['ALP', 'HMS'] as $slug) {
    $city = City::query()->where('slug', $slug)->firstOrFail();
    $lower = strtolower($slug);
    $inCity = Employee::query()->where('city_id', $city->id)->where('is_active', true)->with('account')->get();
    $entry = [];

    // --- HR officer --------------------------------------------------------
    $hrUser = User::query()->where('email', "hr.{$lower}@example.test")->first()
        ?? User::query()->whereHas('roles', fn ($q) => $q->where('name', "HR-{$slug}"))->first();
    if (! $hrUser) {
        $candidate = $inCity->first(fn (Employee $e) => $e->account && ! $used->contains($e->id));
        $hrUser = $candidate->account;
        $setRole($hrUser, "HR-{$slug}");
    }
    $hrUser->forceFill(['email' => "hr.{$lower}@example.test"])->save();
    $used->push($hrUser->employee_id);
    $entry['hr'] = ['email' => $hrUser->email, 'role' => "HR-{$slug}"];

    // --- Task C: a manager with at least three direct reports --------------
    $reportsOf = function (Contract $managerContract) use ($city): Illuminate\Support\Collection {
        return Contract::query()->activeNow()
            ->whereHas('position', fn ($q) => $q->where('direct_manager_id', $managerContract->position_id))
            ->whereHas('employee', fn ($q) => $q->where('is_active', true)->where('city_id', $city->id))
            ->where('employee_id', '!=', $managerContract->employee_id)
            ->with('employee', 'position')
            ->get()
            ->unique('employee_id')
            ->values();
    };

    // Each city's manager must hold a different position: managers sharing one
    // (e.g. a head-office post) would see every city's pending requests.
    $managerContract = Contract::query()->activeNow()
        ->whereHas('employee', fn ($q) => $q->where('is_active', true)->where('city_id', $city->id))
        ->whereNotIn('employee_id', $used->all())
        ->whereNotIn('position_id', $managerPositions->all())
        ->with('position')
        ->get()
        ->map(fn (Contract $c) => [$c, $reportsOf($c)->whereNotIn('employee_id', $used->all())->count()])
        ->sortByDesc(1)
        ->first();

    if (! $managerContract || $managerContract[1] < 3) {
        // Not enough reporting lines in the reference positions: make three
        // vacant positions in the city report to one manager's position.
        $managerContract = [Contract::query()->activeNow()
            ->whereHas('employee', fn ($q) => $q->where('is_active', true)->where('city_id', $city->id))
            ->whereNotIn('employee_id', $used->all())->whereNotIn('position_id', $managerPositions->all())->with('position')->firstOrFail(), 0];
        $others = Contract::query()->activeNow()
            ->whereHas('employee', fn ($q) => $q->where('is_active', true)->where('city_id', $city->id))
            ->whereNotIn('employee_id', $used->push($managerContract[0]->employee_id)->all())
            ->where('position_id', '!=', $managerContract[0]->position_id)
            ->take(3)->get();
        foreach ($others as $other) {
            Position::query()->whereKey($other->position_id)->update(['direct_manager_id' => $managerContract[0]->position_id]);
        }
    }
    [$managerContract] = $managerContract;
    $manager = $managerContract->employee;
    $managerUser = $accountFor($manager, "manager.{$lower}@example.test");
    $setRole($managerUser, 'manager');
    $used->push($manager->id);
    $managerPositions->push($managerContract->position_id);

    $reports = $reportsOf($managerContract)->whereNotIn('employee_id', $used->all())->values()->take(3);
    if ($reports->count() < 3) {
        throw new RuntimeException("{$slug}: could not find three direct reports for the manager persona.");
    }

    // Clear the reports' pending requests, so each has only the one made here.
    LeaveRequest::query()->where('status', LeaveRequestStatus::Pending)
        ->whereHas('leaveBalance.contract', fn ($q) => $q->whereIn('employee_id', $reports->pluck('employee_id')))
        ->delete();

    $pending = [];
    foreach ($reports as $n => $report) {
        $balance = $annualBalance($report);
        $start = $workingDay($report, 15 + $n * 5)->setTime(8, 0);
        $end = $workingDay($report, 2, $start)->setTime(16, 0);
        $request = LeaveRequest::query()->forceCreate([
            'leave_balance_id' => $balance->id, 'type' => 'day', 'start_at' => $start, 'end_at' => $end,
            'reason' => 'Synthetic leave request', 'status' => LeaveRequestStatus::Pending, 'hr_approved' => false,
        ]);
        $pending[] = ['request_id' => $request->id, 'employee_id' => $report->employee_id, 'name' => $name($report->employee),
            'start' => $start->toDateString(), 'end' => $end->toDateString()];
        $used->push($report->employee_id);
    }
    // X1 spreads leave notifications at random, so the personas' bells point
    // at requests they cannot open. Give the manager only real notices for
    // the three requests above; the staff persona's bell is cleared further down.
    DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $managerUser->id)->delete();
    foreach ($pending as $n => $row) {
        $created = now()->subHours(3 - $n);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => NewLeaveRequest::class, 'notifiable_type' => User::class,
            'notifiable_id' => $managerUser->id, 'read_at' => null, 'created_at' => $created, 'updated_at' => $created,
            'data' => json_encode((new NewLeaveRequest(LeaveRequest::query()->with('leaveBalance.contract.employee', 'leaveBalance.leaveType')->find($row['request_id'])))->toArray($managerUser)),
        ]);
    }

    $entry['task_c'] = [
        'manager' => ['email' => $managerUser->email, 'role' => 'manager', 'employee_id' => $manager->id, 'position' => $title($managerContract->position)],
        'target' => $pending[0],
        'other_pending' => array_slice($pending, 1),
    ];

    // --- Task A: staff persona with a fresh annual balance -----------------
    $staffUser = User::query()->where('email', "staff.{$lower}@example.test")->first();
    if (! $staffUser) {
        $staff = $inCity->first(fn (Employee $e) => ! $used->contains($e->id) && $currentContract($e) && $e->account);
        $staffUser = $accountFor($staff, "staff.{$lower}@example.test");
        $setRole($staffUser, 'employee');
    }
    $staffContract = $currentContract($staffUser->profile);
    $staffBalance = $annualBalance($staffContract);
    // At least five full days left (balances are kept in hours).
    $perDay = max(1, (int) round(($staffContract->weekly_hours ?: 40) / 5));
    $staffBalance->forceFill(['deserved' => max((float) $staffBalance->deserved, 10 * $perDay), 'used' => 0])->save();
    $used->push($staffUser->employee_id);
    DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $staffUser->id)->delete();
    $entry['task_a'] = ['email' => $staffUser->email, 'role' => 'employee', 'employee_id' => $staffUser->employee_id,
        'target_date' => $workingDay($staffContract, 8)->toDateString(), 'center_weekends' => $staffContract->position?->center?->weekends];

    // --- Task B: a target sharing a surname with at least two others -------
    // The employee list opens sorted by id, 15 rows a page: a target on the
    // first page or two could be read off without searching at all.
    $firstPages = Employee::query()->where('city_id', $city->id)->orderBy('id')->limit(30)->pluck('id');
    $shared = $inCity->groupBy('last_name_en')->filter(fn ($group) => $group->count() >= 3)->sortByDesc(fn ($g) => $g->count());
    $target = $shared->flatten()->first(fn (Employee $e) => ! $used->contains($e->id) && ! $firstPages->contains($e->id)
        && $e->account && $currentContract($e));
    if (! $target) {
        throw new RuntimeException("{$slug}: no employee with a shared surname and an account.");
    }
    $used->push($target->id);
    $entry['task_b'] = ['employee_id' => $target->id, 'name' => $name($target), 'position' => $title($currentContract($target)->position),
        'work_email' => $target->account->email, 'surname_count_in_city' => $inCity->where('last_name_en', $target->last_name_en)->count()];

    // --- Task D: new hire without a contract, and a vacant position --------
    $vacant = Position::query()->inCity($city->id)->where('is_active', true)
        ->whereColumn('exist_emp', '<', 'max_emp')->whereNotNull('center_id')->with('center')->orderBy('id')->first();
    $vacant ??= Position::query()->inCity($city->id)->where('is_active', true)->whereNotNull('center_id')->with('center')->orderBy('id')->firstOrFail();
    // Two free seats: one for Task D, one for completing D''s reservation in
    // the same session (a Draft claims its seat only when it is completed).
    if ($vacant->max_emp - $vacant->exist_emp < 2) {
        $vacant->forceFill(['max_emp' => $vacant->exist_emp + 2])->save();
    }

    $newHire = function (string $key, array $first, array $last) use ($city, $today, $lower): Employee {
        return Employee::query()->updateOrCreate(['username' => "x2.{$key}.{$lower}"], [
            'city_id' => $city->id, 'first_name_en' => $first[0], 'first_name_ar' => $first[1],
            'last_name_en' => $last[0], 'last_name_ar' => $last[1], 'father_name_en' => 'Synthetic', 'father_name_ar' => 'اصطناعي',
            'dob' => $today->copy()->subYears(27)->toDateString(), 'actual_dob' => $today->copy()->subYears(27)->toDateString(),
            'gender' => 'female', 'work_phone' => '0999000'.($key === 'hire' ? '101' : '202'), 'is_active' => true,
            'joined_at' => $today->toDateString(),
        ]);
    };

    $hire = $newHire('hire', ['Nadine', 'نادين'], ['Sarkis', 'سركيس']);
    $start = $workingDay(new Contract(['position_id' => $vacant->id]), 14);
    $entry['task_d'] = ['employee_id' => $hire->id, 'name' => $name($hire),
        'position' => $title($vacant) + ['id' => $vacant->id, 'seats' => "{$vacant->exist_emp}/{$vacant->max_emp}"],
        'center' => $vacant->center ? ['en' => $vacant->center->name_en, 'ar' => $vacant->center->name_ar] : null,
        'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addYear()->subDay()->toDateString(),
        'days_per_week' => 5, 'hours_per_day' => 8, 'monthly_pay' => 'synthetic, chosen by the moderator; never recorded'];

    // --- Task D': an employee with a Draft-type reservation ----------------
    $drafted = $newHire('draft', ['Tony', 'توني'], ['Wehbe', 'وهبة']);
    if (! Contract::query()->where('employee_id', $drafted->id)->where('type', ContractTypeEnum::Draft)->exists()) {
        $annualType = ContractType::all()->first(fn (ContractType $t): bool => $t->category === 'annual');
        Contract::query()->create(['employee_id' => $drafted->id, 'position_id' => $vacant->id,
            'contract_type_id' => $annualType?->id, 'type' => ContractTypeEnum::Draft, 'is_active' => true]);
    }
    $entry['task_d_prime'] = ['employee_id' => $drafted->id, 'name' => $name($drafted), 'reserved_position_id' => $vacant->id];

    $manifest['cities'][$slug] = $entry;
    $log("{$slug}: hr {$entry['hr']['email']}, manager {$managerUser->email}, staff {$staffUser->email}, B target #{$target->id}, D hire #{$hire->id}, D' #{$drafted->id}");
}

app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

file_put_contents(getenv('PERF_HOME').'/x2-session-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$log('manifest: '.getenv('PERF_HOME').'/x2-session-manifest.json');
