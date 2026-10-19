<?php

/*
 * X1 performance baseline: build the synthetic measurement database.
 *
 *   PERF_HOME=<dir> php tests/Performance/Baseline/build-fixtures.php
 *
 * Writes <PERF_HOME>/perf.sqlite and <PERF_HOME>/fixture-manifest.json. The
 * data is synthetic and deterministic (fixed seed), and its volume matches
 * the dev database's row counts on 2026-10-16 (counts only were read; see
 * docs/ux/performance-baseline.md). Only the organisational reference seeders
 * run (cities, centers, programs, departments, positions, leave rules...);
 * EmployeeSeeder, UserRoleSeeder and EmployeeContractSeeder do not, so no
 * person from config/samples/sampleData.php enters this database.
 */

use App\Enums\LeaveRequestStatus;
use App\Models\Checklist;
use App\Models\CheckListItem;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\EmployeeNote;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Notifications\NewLeaveRequest;
use App\Services\LeaveBalanceService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/env.php';

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
perf_configure($app);
$app->make(Kernel::class)->bootstrap();
perf_assert_isolated();

// Volumes observed in the dev database (row counts only).
const TARGET = [
    'employees' => 278,
    'active_employees' => 196,
    'users' => 261,
    'contracts' => 314,
    'positions' => 168,
    'leave_requests' => 63,
    'avatars' => 191,
    'notifications' => 1050,
];

// Employees per city in the dev database: [active, inactive].
const CITY_MIX = [
    'ALP' => [60, 11], 'DMS' => [29, 60], 'HMS' => [62, 2], 'LAT' => [30, 6], 'RHO' => [9, 0], 'TAR' => [6, 3],
];

$started = microtime(true);
mt_srand(20261016);
$today = Carbon::today();
$log = fn (string $line) => fwrite(STDOUT, sprintf("[%5.1fs] %s\n", microtime(true) - $started, $line));

$dbFile = getenv('PERF_HOME').'/perf.sqlite';
foreach ([$dbFile, "{$dbFile}-wal", "{$dbFile}-shm"] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}
touch($dbFile);

// Synthetic avatars from a previous build would otherwise collide with the
// fresh media ids.
foreach (['app/private', 'app/public', 'framework/views', 'framework/cache/data', 'framework/sessions'] as $dir) {
    $app['files']->cleanDirectory(storage_path($dir));
}

Artisan::call('migrate', ['--force' => true]);
$log('migrated');

foreach ([
    'ProgramSeeder', 'CitySeeder', 'ContractTypeSeeder', 'DepartmentSeeder', 'CenterSeeder',
    'CheckListItemSeeder', 'LeaveTypeSeeder', 'AnnualLeaveRuleSeeder',
    'JobLevelSeeder', 'PositionsSeeder', 'EducationFieldSeeder', 'CourseCategorySeeder', 'TerminationReasonSeeder',
] as $seeder) {
    Artisan::call('db:seed', ['--class' => "Database\\Seeders\\{$seeder}", '--force' => true]);
}
$log('reference seeders done');

// RolesPermissionsSeeder uses Role::create(), which throws for the roles the
// migrations already made; create its same list idempotently instead.
foreach (['super-admin', 'general-manager', 'HR-SYR', 'HR-ALP', 'HR-HMS', 'HR-DMS', 'HR-LAT', 'HR-TAR', 'manager', 'employee'] as $roleName) {
    Role::findOrCreate($roleName, 'web');
}

// On production the HR-* roles existed before the access-control migrations
// ran, so those migrations granted them their permissions. Here the roles are
// seeded after migrating; replay the two migrations to reach the same state.
foreach (['2026_07_30_000001_seed_access_control_permissions', '2026_07_30_000002_backfill_active_role_permission_baselines'] as $migration) {
    (require $root."/database/migrations/{$migration}.php")->up();
}
$log('permissions granted');

// ---------------------------------------------------------------------------
// Positions: top up the reference set to the observed count.
// ---------------------------------------------------------------------------

$referencePositions = Position::query()->where('is_active', true)->get();
$clone = 0;
while (Position::query()->count() < TARGET['positions']) {
    $source = $referencePositions[$clone % $referencePositions->count()];
    $copy = $source->replicate(['exist_emp']);
    $copy->title_en = $source->title_en.' '.($clone + 2);
    $copy->title_ar = $source->title_ar.' '.($clone + 2);
    $copy->max_emp = max(1, (int) $source->max_emp);
    $copy->exist_emp = 0;
    $copy->direct_manager_id = $source->direct_manager_id;
    $copy->save();
    $clone++;
}
$log('positions: '.Position::query()->count());

// ---------------------------------------------------------------------------
// People
// ---------------------------------------------------------------------------

$firstNames = [
    ['Adam', 'آدم'], ['Lina', 'لينا'], ['Omar', 'عمر'], ['Rana', 'رنا'], ['Sami', 'سامي'], ['Dana', 'دانا'],
    ['Fadi', 'فادي'], ['Maya', 'مايا'], ['Hadi', 'هادي'], ['Nour', 'نور'], ['Karim', 'كريم'], ['Hala', 'هالة'],
    ['Rami', 'رامي'], ['Yara', 'يارا'], ['Tarek', 'طارق'], ['Reem', 'ريم'], ['Jad', 'جاد'], ['Lara', 'لارا'],
    ['Nabil', 'نبيل'], ['Salma', 'سلمى'], ['Elias', 'إلياس'], ['Rita', 'ريتا'], ['Bassel', 'باسل'], ['Mira', 'ميرا'],
    ['George', 'جورج'], ['Sara', 'سارة'], ['Joseph', 'جوزيف'], ['Maria', 'ماريا'], ['Fares', 'فارس'], ['Zeina', 'زينة'],
];
$lastNames = [
    ['Haddad', 'حداد'], ['Khoury', 'خوري'], ['Nassar', 'نصار'], ['Saliba', 'صليبا'], ['Mansour', 'منصور'],
    ['Hanna', 'حنا'], ['Bitar', 'بيطار'], ['Sabbagh', 'صباغ'], ['Atallah', 'عطالله'], ['Daher', 'ضاهر'],
    ['Kassab', 'قصاب'], ['Shami', 'شامي'], ['Halabi', 'حلبي'], ['Najjar', 'نجار'], ['Tannous', 'طنوس'],
    ['Issa', 'عيسى'], ['Farah', 'فرح'], ['Ghanem', 'غانم'], ['Antoun', 'أنطون'], ['Rizk', 'رزق'],
];

// Live contracts are mostly the regular volunteer type; trial and service
// contracts carry fewer (or no) leave balances.
$contractTypes = ContractType::query()->orderBy('id')->pluck('id')->all();
$pickType = fn () => match (true) {
    mt_rand(1, 100) <= 85 => $contractTypes[0],
    mt_rand(1, 2) === 1 => $contractTypes[1] ?? $contractTypes[0],
    default => $contractTypes[2] ?? $contractTypes[0],
};
// Collection::shuffle() takes no seed any more; sort on mt_rand() keys instead.
$shuffle = fn ($items) => $items->map(fn ($item) => [mt_rand(), $item])->sortBy(0)->map(fn ($pair) => $pair[1])->values();
$positions = Position::query()->with('center')->where('is_active', true)->get();
$positions = $shuffle($positions);
$headOffice = City::query()->where('slug', City::NATIONAL_SLUG)->value('id');
$fallbackCity = City::query()->where('slug', 'ALP')->value('id');

$cityOf = fn (Position $p) => $p->center?->city_id ?: ($p->city_id === $headOffice ? $fallbackCity : $p->city_id) ?: $fallbackCity;

$makeEmployee = function (int $i, int $cityId, bool $active) use ($firstNames, $lastNames, $today): Employee {
    [$fe, $fa] = $firstNames[mt_rand(0, count($firstNames) - 1)];
    [$le, $la] = $lastNames[mt_rand(0, count($lastNames) - 1)];
    $dob = $today->copy()->subYears(mt_rand(20, 60))->subDays(mt_rand(0, 364));

    return Employee::query()->create([
        'city_id' => $cityId,
        'first_name_en' => $fe,
        'first_name_ar' => $fa,
        'last_name_en' => $le,
        'last_name_ar' => $la,
        'father_name_en' => 'Synthetic',
        'father_name_ar' => 'اصطناعي',
        'username' => 'synth.'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        'dob' => $dob->toDateString(),
        'actual_dob' => $dob->toDateString(),
        'gender' => mt_rand(0, 1) ? 'male' : 'female',
        'work_phone' => '0900'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
        'telegram_work' => mt_rand(0, 2) ? '0900'.str_pad((string) $i, 6, '0', STR_PAD_LEFT) : null,
        'is_active' => $active,
        'joined_at' => $today->copy()->subDays(mt_rand(30, 3000))->toDateString(),
    ]);
};

$makeContract = function (Employee $employee, Position $position, Carbon $start, Carbon $end, bool $active) use ($pickType): Contract {
    return Contract::query()->create([
        'employee_id' => $employee->id,
        'position_id' => $position->id,
        'contract_type_id' => $pickType(),
        'start_date' => $start->toDateString(),
        'end_date' => $end->toDateString(),
        'currency' => 2,
        'salary_per' => 1,
        'salary' => mt_rand(8, 40) * 50,
        'weekly_hours' => 40,
        'is_active' => $active,
    ]);
};

// Each city gets the dev database's head-count: [active, inactive]. City
// scope decides what an HR-ALP officer's lists contain, so this matters more
// than the total.
$employees = collect();
$i = 0;
foreach (CITY_MIX as $slug => [$activeCount, $inactiveCount]) {
    $cityId = City::query()->where('slug', $slug)->value('id');
    $cityPositions = $positions->filter(fn (Position $p) => $cityOf($p) === $cityId)->values();
    if ($cityPositions->isEmpty()) {
        $cityPositions = $positions;
    }

    // Active staff: one running contract each.
    for ($n = 0; $n < $activeCount; $n++) {
        $position = $cityPositions[$n % $cityPositions->count()];
        $employee = $makeEmployee(++$i, $cityId, true);
        $start = $today->copy()->subDays(mt_rand(10, 330));
        // About one in eight ends inside the next 45 days (soon-ending card).
        $end = mt_rand(1, 8) === 1 ? $today->copy()->addDays(mt_rand(1, 45)) : $start->copy()->addYear()->subDay();
        if ($end->lte($today)) {
            $end = $today->copy()->addDays(mt_rand(30, 200));
        }
        $makeContract($employee, $position, $start, $end, true);
        $employees->push($employee);
    }

    // Former staff: inactive, expired contracts only.
    for ($n = 0; $n < $inactiveCount; $n++) {
        $position = $cityPositions[($activeCount + $n) % $cityPositions->count()];
        $employee = $makeEmployee(++$i, $cityId, false);
        $end = $today->copy()->subDays(mt_rand(30, 900));
        $makeContract($employee, $position, $end->copy()->subYear()->addDay(), $end, false);
        $employees->push($employee);
    }
}

// Earlier, expired contracts for current staff, up to the observed total.
$active = $employees->where('is_active', true)->values();
while (Contract::query()->count() < TARGET['contracts']) {
    $employee = $active[mt_rand(0, $active->count() - 1)];
    $current = $employee->contracts()->orderBy('start_date')->first();
    $end = Carbon::parse($current->start_date)->subDay();
    $makeContract($employee, $current->position, $end->copy()->subYear()->addDay(), $end, false);
}
$log('employees: '.Employee::query()->count().', contracts: '.Contract::query()->count());

// Leave balances, generated the way leaves:backfill does (oldest first).
$service = app(LeaveBalanceService::class);
$balanceFailures = 0;
Contract::query()->orderBy('employee_id')->orderBy('start_date')->orderBy('id')->get()
    ->each(function (Contract $contract) use ($service, &$balanceFailures) {
        try {
            DB::transaction(fn () => $service->create($contract));
        } catch (Throwable) {
            $balanceFailures++;
        }
    });
$log('leave balances: '.LeaveBalance::query()->count()." (failures {$balanceFailures})");

// ---------------------------------------------------------------------------
// Accounts and roles
// ---------------------------------------------------------------------------

$password = Hash::make('perf-baseline');
$employeeRole = Role::findByName('employee', 'web');
$withAccount = $active->concat($employees->where('is_active', false)->take(TARGET['users'] - $active->count()));

$accounts = $withAccount->mapWithKeys(function (Employee $employee) use ($password) {
    $user = User::query()->forceCreate([
        'employee_id' => $employee->id,
        'email' => $employee->username.'@example.test',
        'password' => $password,
        'email_verified_at' => now(),
    ]);

    return [$employee->id => $user];
});

DB::table('model_has_roles')->insert($accounts->map(fn (User $u) => [
    'role_id' => $employeeRole->id, 'model_type' => User::class, 'model_id' => $u->id, 'is_active' => true,
])->values()->all());

// Measurement personas: an Aleppo HR officer, and an Aleppo staff member who
// requests leave. Both hold an active contract with leave balances.
$aleppo = City::query()->where('slug', 'ALP')->value('id');
$aleppoStaff = $active->where('city_id', $aleppo)->values();
$personaEmployee = fn (int $n) => $aleppoStaff[$n];

$hrUser = $accounts[$personaEmployee(0)->id];
$hrUser->forceFill(['email' => 'hr.alp@example.test'])->save();
$hrRole = Role::findByName('HR-ALP', 'web');
// Login activates the lowest role id, so an HR officer holds only the HR role.
DB::table('model_has_roles')->where('model_id', $hrUser->id)->delete();
DB::table('model_has_roles')->insert(['role_id' => $hrRole->id, 'model_type' => User::class, 'model_id' => $hrUser->id, 'is_active' => true]);

$staffUser = $accounts[$personaEmployee(1)->id];
$staffUser->forceFill(['email' => 'staff.alp@example.test'])->save();

// Other cities get an HR officer too, so notifications fan out as they do live.
foreach (['HMS', 'DMS', 'LAT'] as $slug) {
    $cityId = City::query()->where('slug', $slug)->value('id');
    $candidate = $active->first(fn (Employee $e) => $e->city_id === $cityId);
    if ($candidate && isset($accounts[$candidate->id])) {
        $role = Role::findByName("HR-{$slug}", 'web');
        DB::table('model_has_roles')->where('model_id', $accounts[$candidate->id]->id)->delete();
        DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_type' => User::class, 'model_id' => $accounts[$candidate->id]->id, 'is_active' => true]);
    }
}
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
$log('users: '.User::query()->count());

// ---------------------------------------------------------------------------
// Leave requests (around today, so calendar and upcoming cards are populated)
// ---------------------------------------------------------------------------

$statuses = array_merge(
    array_fill(0, 36, LeaveRequestStatus::Accepted),
    array_fill(0, 7, LeaveRequestStatus::Rejected),
    array_fill(0, 20, LeaveRequestStatus::Pending),
);
shuffle($statuses);

$requestable = $shuffle(LeaveBalance::query()
    ->whereHas('contract', fn ($q) => $q->where('is_active', true))
    ->where('type_id', 1)
    ->with('contract')
    ->get());

$staffBalance = LeaveBalance::query()
    ->where('type_id', 1)
    ->whereHas('contract', fn ($q) => $q->where('is_active', true)->where('employee_id', $staffUser->employee_id))
    ->first();

foreach ($statuses as $n => $status) {
    // The staff persona gets four of the 63, so their list is not empty.
    $balance = $n < 4 && $staffBalance ? $staffBalance : $requestable[$n % $requestable->count()];
    $start = $today->copy()->addDays(mt_rand(-60, 45))->setTime(8, 0);
    $days = mt_rand(1, 3);
    LeaveRequest::query()->forceCreate([
        'leave_balance_id' => $balance->id,
        'type' => 'day',
        'start_at' => $start,
        'end_at' => $start->copy()->addDays($days - 1)->setTime(16, 0),
        'reason' => 'Synthetic leave request',
        'status' => $status,
        'hr_approved' => $status === LeaveRequestStatus::Accepted,
        'approved_at' => $status === LeaveRequestStatus::Pending ? null : $start->copy()->subDays(3),
        'approved_by' => $status === LeaveRequestStatus::Pending ? null : $hrUser->id,
    ]);
}
$log('leave requests: '.LeaveRequest::query()->count());

// ---------------------------------------------------------------------------
// Notifications: real NewLeaveRequest payloads, fanned out to the observed total.
// ---------------------------------------------------------------------------

$requests = LeaveRequest::query()->with('leaveBalance.contract.employee', 'leaveBalance.leaveType')->get();
$userIds = $accounts->map->id->values();
$rows = [];
for ($n = 0; $n < TARGET['notifications']; $n++) {
    // The HR persona carries 60 of them (12 unread); the rest spread across staff.
    $notifiable = $n < 60 ? $hrUser->id : $userIds[mt_rand(0, $userIds->count() - 1)];
    $payload = (new NewLeaveRequest($requests[$n % $requests->count()]))->toArray($hrUser);
    $created = now()->subMinutes(mt_rand(5, 60 * 24 * 120));
    $rows[] = [
        'id' => (string) Str::uuid(),
        'type' => NewLeaveRequest::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $notifiable,
        'data' => json_encode($payload),
        'read_at' => ($n < 60 ? $n >= 12 : mt_rand(0, 3) > 0) ? $created->copy()->addHour() : null,
        'created_at' => $created,
        'updated_at' => $created,
    ];
}
foreach (array_chunk($rows, 200) as $chunk) {
    DB::table('notifications')->insert($chunk);
}
$log('notifications: '.DB::table('notifications')->count());

// Dashboard cards for both personas.
foreach ([$hrUser, $staffUser] as $persona) {
    foreach (range(1, 5) as $order) {
        EmployeeNote::query()->forceCreate([
            'employee_id' => $persona->employee_id, 'type' => 'note', 'content' => "Synthetic note {$order}",
            'is_done' => $order > 3, 'order' => $order,
        ]);
    }
}
$items = CheckListItem::query()->pluck('id');
foreach (range(1, 8) as $n) {
    Checklist::query()->forceCreate([
        'item_id' => $items[$n % max(1, $items->count())] ?? null,
        'employee_id' => $n <= 3 ? $hrUser->employee_id : $active[mt_rand(0, $active->count() - 1)]->id,
        'delivered_id' => $n > 5 ? $hrUser->employee_id : $active[mt_rand(0, $active->count() - 1)]->id,
        'delivered_status' => $n > 5 ? 'pending' : 'delivered',
        'delivered_at' => $n > 5 ? null : $today->copy()->subDays($n * 7),
        'notes' => 'Synthetic',
    ]);
}

// ---------------------------------------------------------------------------
// Avatars: synthetic PNGs with the observed average weight (~245 KB).
// ---------------------------------------------------------------------------

$avatarDir = getenv('PERF_HOME').'/avatar-sources';
@mkdir($avatarDir, 0777, true);
$sources = [];
for ($k = 0; $k < 8; $k++) {
    $path = "{$avatarDir}/synthetic-{$k}.png";
    if (! is_file($path)) {
        $size = 330;
        $img = imagecreatetruecolor($size, $size);
        [$r0, $g0, $b0] = [mt_rand(40, 200), mt_rand(40, 200), mt_rand(40, 200)];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                // A gradient plus per-pixel noise: compresses roughly like a photo.
                $noise = mt_rand(-40, 40);
                imagesetpixel($img, $x, $y, imagecolorallocate($img,
                    max(0, min(255, $r0 + ($x >> 2) + $noise)),
                    max(0, min(255, $g0 + ($y >> 2) + $noise)),
                    max(0, min(255, $b0 - ($x >> 3) + $noise)),
                ));
            }
        }
        imagepng($img, $path, 6);
    }
    $sources[] = $path;
}

$withAvatar = $shuffle($employees)
    ->sortByDesc('is_active')   // most avatars belong to current staff, as live
    ->take(TARGET['avatars']);
foreach ($withAvatar->values() as $n => $employee) {
    $employee->addMedia($sources[$n % count($sources)])
        ->preservingOriginal()
        ->usingFileName('avatar.png')
        ->toMediaCollection('avatar');
}
$avatarBytes = DB::table('media')->where('collection_name', 'avatar')->avg('size');
$log('avatars: '.DB::table('media')->where('collection_name', 'avatar')->count().', avg '.round($avatarBytes).' B');

// ---------------------------------------------------------------------------
// Manifest
// ---------------------------------------------------------------------------

$searchTerm = 'Haddad';
$hrCityIds = $hrUser->fresh()->activeCityIds();

$manifest = [
    'built_at' => now()->toIso8601String(),
    'seed' => 20261016,
    'database' => 'sqlite: '.$dbFile,
    'targets' => TARGET,
    'counts' => collect(['employees', 'users', 'contracts', 'positions', 'centers', 'cities', 'leave_balances',
        'leave_requests', 'leave_types', 'notifications', 'media', 'checklists', 'employee_notes', 'news'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all()
        + [
            'active_employees' => Employee::query()->where('is_active', true)->count(),
            'active_contracts' => Contract::query()->where('is_active', true)->count(),
            'avatar_avg_bytes' => (int) round($avatarBytes),
            'leave_balance_failures' => $balanceFailures,
        ],
    'personas' => [
        'hr' => ['email' => 'hr.alp@example.test', 'password' => 'perf-baseline', 'role' => 'HR-ALP', 'employee_id' => $hrUser->employee_id],
        'staff' => ['email' => 'staff.alp@example.test', 'password' => 'perf-baseline', 'role' => 'employee', 'employee_id' => $staffUser->employee_id],
    ],
    'search' => [
        'term' => $searchTerm,
        'expected_matches_in_hr_scope' => Employee::query()
            ->when($hrCityIds, fn ($q) => $q->whereIn('city_id', $hrCityIds))
            ->where(fn ($q) => $q->where('first_name_en', 'like', "%{$searchTerm}%")->orWhere('last_name_en', 'like', "%{$searchTerm}%"))
            ->count(),
        'employees_in_hr_scope' => Employee::query()->when($hrCityIds, fn ($q) => $q->whereIn('city_id', $hrCityIds))->count(),
    ],
    'contract_pick' => [
        // An Aleppo employee with no running contract, as a new contract would be for.
        'employee_id' => Employee::query()->where('city_id', $aleppo)->where('is_active', true)
            ->whereNotIn('id', [$hrUser->employee_id, $staffUser->employee_id])->value('id'),
    ],
];

file_put_contents(getenv('PERF_HOME').'/fixture-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$log('manifest written: '.getenv('PERF_HOME').'/fixture-manifest.json');
