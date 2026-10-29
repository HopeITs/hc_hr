<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Services\TeamCoverage;
use App\Support\CommandPalette;
use App\Support\Dashboard\DashboardLayout;
use App\Support\Navigation;
use App\Support\NavigationMemory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Release QA (prompt 11), ported from media-request's AllLinksTest: for every
 * kind of person, open the dashboard, every tile they may have, every
 * shortcut, every favourite and every command target. Each must open (200),
 * and none may carry a name outside the viewer's scope or a pay figure the
 * viewer may not see.
 *
 * Roles are the real ones: employee, manager, HR-SYR and super-admin come
 * from the access-control migration with their baselines; HR-ALP and HR-HMS
 * are created through App\Models\Role, whose created hook grants a city HR
 * role what production grants it.
 *
 *   SYR  Nadia (HR-SYR), Adam (super-admin)
 *   HMS  Sami (manager) ← Lina (employee); Karim (other center, no manager); Huda (HR-HMS)
 *   ALP  Rana (employee); Hala (HR-ALP)
 *
 * Everyone is out today, has a pending request, a birthday this week and a
 * contract ending soon, so every tile has rows to leak if its scope is wrong.
 */
class ReleaseLinksTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    /**
     * The statistics tiles count the whole organisation, as the statistics
     * page does today: city scoping them is an open decision (release QA,
     * open issue c), so they are opened but not held to the name rule.
     */
    private const UNSCOPED_TILES = ['stats-department', 'stats-job-level', 'stats-contract-type', 'stats-no-leaves', 'stats-salary',
        // "Contact the team" is the organisation's contact directory by design (head office, own city, then
        // the other cities; the Directory policy hides former staff), as before this work.
        'team'];

    /**
     * Pages whose own, older rule is wider than the city: the organisation's
     * positions and org chart (everyone's directory), and done requests (the
     * deliver-responsible department across cities). Opened, pay checked,
     * names not held to the city rule.
     */
    private const ORGANISATION_PAGES = ['nav.positions', 'nav.org.chart', 'nav.hr.done-requests'];

    /** Who each viewer must never see (by first name). */
    private const OUT_OF_SCOPE = [
        'lina' => ['Rana', 'Karim', 'Hala', 'Huda', 'Nadia', 'Adam'],
        'sami' => ['Rana', 'Karim', 'Hala', 'Nadia', 'Adam'],
        'hala' => ['Lina', 'Sami', 'Karim', 'Huda'],
        'huda' => ['Rana', 'Hala'],
        'nadia' => [],
        'adam' => [],
    ];

    protected array $p = [];

    private array $cities = [];

    private array $centers = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Carbon::setTestNow('2026-10-27 10:00:00');
        CarbonImmutable::setTestNow('2026-10-27 10:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->buildPersonas();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_every_dashboard_target_opens_and_shows_nothing_out_of_scope(): void
    {
        $failures = [];
        $opened = 0;

        foreach (array_keys(self::OUT_OF_SCOPE) as $who) {
            foreach (['en', 'ar'] as $locale) {
                app()->setLocale($locale);
                $user = $this->user($who);
                $layout = new DashboardLayout;

                // The dashboard page itself (header, grid, sidebar, shortcut counters).
                $html = $this->open($user, route('dashboard'), $locale, $failures, "{$who}/{$locale} dashboard");
                $opened++;
                $this->checkHtml($who, $html, "{$who}/{$locale} dashboard", $failures);

                if (! str_contains($html, 'lang="'.$locale.'"')) {
                    $failures[] = "{$who}/{$locale} dashboard is not in {$locale}";
                }

                // Every widget the person may have, drawn as the lazy endpoint draws it.
                foreach (DashboardLayout::COMPONENTS as $key => $component) {
                    if (! $layout->allowed($user, $key)) {
                        continue;
                    }

                    app()->forgetInstance(TeamCoverage::class);
                    $this->actingAs($user);
                    $opened++;

                    try {
                        $tile = Livewire::test($component)->assertStatus(200);
                        $this->checkHtml($who, $tile->html(), "{$who}/{$locale} tile {$key}", $failures, names: ! in_array($key, self::UNSCOPED_TILES, true));
                    } catch (Throwable $e) {
                        $failures[] = "{$who}/{$locale} tile {$key} threw ".class_basename($e).': '.$e->getMessage();
                    }
                }

                // Pages open in the interface language they are linked from; one language is enough for them.
                if ($locale !== 'en') {
                    continue;
                }

                // Every shortcut the gallery offers (menu pages and New actions).
                $pages = [];

                foreach ($layout->apps($user) as $key => $app) {
                    $pages[route($app['route'], $app['params'])] = ["shortcut {$key}", in_array($app['id'], self::ORGANISATION_PAGES, true)];
                }

                // Every command target that opens a page (most are New actions, opened once).
                foreach (CommandPalette::for($user, url('/')) as $command) {
                    if ($command['href'] !== null && $command['action'] === 'open') {
                        $pages[$command['href']] ??= ["command {$command['id']}", false];
                    }
                }

                foreach ($pages as $url => [$label, $organisation]) {
                    $opened++;
                    $html = $this->open($user, $url, $locale, $failures, "{$who}/{$locale} {$label}");
                    $this->checkHtml($who, $html, "{$who}/{$locale} {$label}", $failures, names: ! $organisation);
                }
            }

            // Favourites: star up to eight pages of the menu, then open each from the sidebar.
            app()->setLocale('en');
            $user = $this->user($who);
            $shortcuts = Navigation::shortcuts($user, Navigation::isDeliverResponsible($user));

            foreach (array_slice(array_keys($shortcuts), 0, NavigationMemory::MAX_FAVOURITES) as $id) {
                NavigationMemory::toggleFavourite($user, $id, $shortcuts);
            }

            $sidebar = $this->open($user, route('dashboard'), 'en', $failures, "{$who} sidebar");

            foreach (NavigationMemory::favourites($user, $shortcuts) as $id) {
                if (! str_contains($sidebar, 'data-nav-favourite="'.$id.'"')) {
                    $failures[] = "{$who} favourite {$id} is not in the sidebar";
                }

                // The page itself was opened as a shortcut above; the favourite link must point at it.
                if (! str_contains($sidebar, 'href="'.route($shortcuts[$id]['route']).'"')) {
                    $failures[] = "{$who} favourite {$id} does not link to its page";
                }
            }
        }

        $this->assertGreaterThan(120, $opened, 'The walk should reach every target');
        $this->assertSame([], $failures, "Release QA failures:\n".implode("\n", $failures));
    }

    public function test_the_pay_check_catches_a_figure_and_super_admin_never_gets_one(): void
    {
        $failures = [];
        $contract = route('contracts.show', $this->p['rana']['contract']);

        // HR-SYR sees Rana's pay on her contract: proves the patterns find a rendered figure.
        $html = $this->open($this->user('nadia'), $contract, 'en', $failures, 'nadia contract');
        $this->assertSame([], $failures);
        $this->assertTrue(collect($this->payPatterns($this->p['rana']['salary']))->contains(fn (string $pattern) => preg_match($pattern, $html)), 'HR-SYR should see the figure');

        // super-admin opens the same contract without it.
        $this->checkHtml('adam', $this->open($this->user('adam'), $contract, 'en', $failures, 'adam contract'), 'adam contract', $failures);
        $this->assertSame([], $failures, implode('
', $failures));
    }

    public function test_every_role_family_has_a_default_dashboard_with_shortcuts(): void
    {
        $layout = new DashboardLayout;

        foreach (['lina' => 'employee', 'sami' => 'manager', 'hala' => 'hr-city', 'huda' => 'hr-city', 'nadia' => 'hr-national', 'adam' => 'leadership'] as $who => $family) {
            $user = $this->user($who);
            $shown = array_filter($layout->defaults($user), fn (array $item) => ! $item['hidden']);

            $this->assertSame($family, $layout->family($user), $who);
            $this->assertNotEmpty(array_filter($shown, fn (array $item) => $layout->isApp($item['key'])), "{$who} has no default shortcut");
            $this->assertFalse($layout->allowed($user, 'stats-salary') && $who === 'adam', 'super-admin is salary-blind');
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    protected function user(string $who): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->p[$who]['user']->fresh();
    }

    // ── Checks ───────────────────────────────────────────────────────────

    private function open(User $user, string $url, string $locale, array &$failures, string $label): string
    {
        app()->setLocale($locale);
        $response = $this->actingAs($user)->get($url);
        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400) {
            // A screen may send the person on (e.g. ?new=1 straight into a form); follow once.
            $response = $this->actingAs($user)->get($response->headers->get('Location'));
            $status = $response->getStatusCode();
        }

        if ($status !== 200) {
            $failures[] = "{$label} {$url} -> {$status}";

            return '';
        }

        return (string) $response->getContent();
    }

    private function checkHtml(string $who, string $html, string $label, array &$failures, bool $names = true): void
    {
        // Raw HTML on purpose: a name in an attribute or a Livewire snapshot reached the browser too.
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($names) {
            foreach (self::OUT_OF_SCOPE[$who] as $name) {
                // Both scripts: the Arabic name is the Latin one with a marker the fixtures add.
                foreach ([$this->p[strtolower($name)]['employee']->first_name_en, $this->p[strtolower($name)]['employee']->first_name_ar] as $shown) {
                    if (preg_match('/(?<![\p{L}])'.preg_quote($shown, '/').'(?![\p{L}])/u', $html)) {
                        $failures[] = "{$label} shows out-of-scope {$shown}";
                    }
                }
            }
        }

        foreach ($this->p as $owner => $person) {
            if ($this->maySeePay($who, $owner)) {
                continue;
            }

            foreach ($this->payPatterns($person['salary']) as $pattern) {
                if (preg_match($pattern, $html)) {
                    $failures[] = "{$label} shows {$owner}'s pay";
                }
            }
        }
    }

    /** City HR and HR-SYR see pay in their scope; nobody else sees anyone's, super-admin included. */
    private function maySeePay(string $viewer, string $owner): bool
    {
        return match ($viewer) {
            'nadia' => true,
            'hala' => $this->p[$owner]['city'] === 'ALP',
            'huda' => $this->p[$owner]['city'] === 'HMS',
            default => $viewer === $owner,
        };
    }

    /** 739251 as stored, grouped, decimal, or in Arabic-Indic digits. */
    private function payPatterns(int $salary): array
    {
        $plain = (string) $salary;
        $grouped = number_format($salary);
        $arabic = strtr($plain, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);

        return array_map(fn (string $s) => '/(?<![\d٠-٩])'.preg_quote($s, '/').'(?![\d٠-٩])/u', array_unique([$plain, $grouped, $arabic]));
    }

    private function buildPersonas(): void
    {
        $this->cities = [
            'SYR' => $this->makeCity('SYR', '000'),
            'ALP' => $this->makeCity('ALP', '001'),
            'HMS' => $this->makeCity('HMS', '002'),
        ];

        foreach (['A' => 'HMS', 'B' => 'ALP', 'C' => 'HMS', 'S' => 'SYR'] as $center => $city) {
            $this->centers[$center] = Center::query()->create([
                'name_en' => "Center {$center}", 'name_ar' => "المركز {$center}", 'city_id' => $this->cities[$city]->id,
                'is_active' => true, 'weekends' => '5,6', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
            ]);
        }

        // Real city HR roles, granted what production grants them (App\Models\Role's created hook).
        Role::create(['name' => 'HR-ALP', 'guard_name' => 'web']);
        Role::create(['name' => 'HR-HMS', 'guard_name' => 'web']);

        foreach ([LeaveTypeCases::Annual => 'Annual', LeaveTypeCases::FullSick => 'Full Sick'] as $id => $name) {
            LeaveType::query()->forceCreate(['id' => $id, 'name_en' => $name, 'name_ar' => $name, 'base_days' => 14]);
        }

        $program = Program::query()->forceCreate(['name' => 'Study Zone', 'slug' => 'study-zone', 'code' => 'SZ', 'is_active' => true]);
        $level = JobLevel::query()->forceCreate(['name_en' => 'Team', 'name_ar' => 'Team', 'grade' => 6]);
        $salary = 739251;

        $people = [
            // key, first name, city, center, department, manager, role
            ['sami', 'Sami', 'HMS', 'A', 'MGMT', null, 'manager'],
            ['lina', 'Lina', 'HMS', 'A', 'Tchr', 'sami', 'employee'],
            ['karim', 'Karim', 'HMS', 'C', 'Fin', null, 'employee'],
            ['rana', 'Rana', 'ALP', 'B', 'Fin', null, 'employee'],
            ['hala', 'Hala', 'ALP', 'B', 'HR', null, 'HR-ALP'],
            ['huda', 'Huda', 'HMS', 'C', 'HR', null, 'HR-HMS'],
            ['nadia', 'Nadia', 'SYR', 'S', 'HR', null, 'HR-SYR'],
            ['adam', 'Adam', 'SYR', 'S', 'MGMT', null, 'super-admin'],
        ];

        foreach ($people as $i => [$key, $first, $city, $center, $code, $manager, $role]) {
            $department = Department::query()->firstOrCreate(['code' => $code], ['name' => $code]);
            $employee = $this->makeEmployee($this->cities[$city], $key, [
                'first_name_en' => $first, 'last_name_en' => 'Q'.$key,
                'first_name_ar' => $first.'ـس', 'last_name_ar' => 'ق'.$key,
                // A birthday this week for everyone (never today, so it is a scope question, not the org-wide "today").
                'dob' => '1990-10-'.(28 + ($i % 3)), 'actual_dob' => '1990-10-'.(28 + ($i % 3)),
            ]);
            $position = Position::query()->forceCreate([
                'center_id' => $this->centers[$center]->id,
                'city_id' => $this->cities[$city]->id,
                'department_id' => $department->id,
                'job_level_id' => $level->id,
                'program_id' => $program->id,
                'direct_manager_id' => $manager ? $this->p[$manager]['position']->id : null,
                'title_en' => "{$first} position", 'title_ar' => "منصب {$first}",
                'max_emp' => 5, 'exist_emp' => 0, 'is_active' => true,
            ]);
            $contract = Contract::query()->create([
                'employee_id' => $employee->id, 'position_id' => $position->id,
                'contract_type_id' => ContractType::query()->firstOrCreate(['id' => 1], ['name_en' => 'Annual', 'name_ar' => 'سنوي'])->id,
                // Ending within the month: on every HR "ending soon" list in scope.
                'start_date' => '2026-01-01', 'end_date' => '2026-11-15', 'currency' => 2, 'salary_per' => 1,
                'salary' => $salary + $i, 'weekly_hours' => 40, 'is_active' => true, 'auto_renew' => false,
            ]);
            $balance = LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => LeaveTypeCases::Annual, 'deserved' => 112, 'used' => 0]);
            LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => LeaveTypeCases::FullSick, 'deserved' => 112, 'used' => 0]);

            // Out today (accepted) and a request waiting for a decision.
            foreach ([['2026-10-27', '2026-10-27', LeaveRequestStatus::Accepted], ['2026-11-10', '2026-11-11', LeaveRequestStatus::Pending]] as [$start, $end, $status]) {
                LeaveRequest::query()->forceCreate([
                    'leave_balance_id' => $balance->id, 'type' => 'day', 'reason' => 'private', 'status' => $status,
                    'start_at' => "{$start} 00:00:00", 'end_at' => "{$end} 00:00:00",
                ]);
            }

            $user = $this->makeUser($this->cities[$city], $role, [], $employee);
            $this->p[$key] = compact('employee', 'position', 'contract', 'user', 'city') + ['salary' => $salary + $i];
        }
    }
}
