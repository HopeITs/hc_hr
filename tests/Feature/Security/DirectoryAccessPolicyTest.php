<?php

namespace Tests\Feature\Security;

use App\Livewire\Dashboard\Index as DashboardIndex;
use App\Livewire\Employee\EmployeeProfile;
use App\Livewire\Position\Directory;
use App\Livewire\Position\OrgChart;
use App\Livewire\Position\ProfileDrawer;
use App\Models\City;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\User;
use App\Support\DirectoryAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The in-app directory policy signed off on 2026-10-20:
 * docs/ux/access-policy.md §4.3 (matrix), §4.4 (deny behaviour), §8 (contract)
 * and §9 (A2D-Q1..Q4, with the employee-own-records and direct-reports-only
 * adjustments).
 *
 * Fixture: two cities, and in each an active and a former (inactive)
 * employee, each still on a running contract so every directory surface
 * would list them if nothing filtered them out. Synthetic data only.
 */
class DirectoryAccessPolicyTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const HOMS_SECRETS = [
        'NID-HOMS-4242',          // national id (P)
        '0944555666',             // personal phone (P)
        'homs.home@example.test', // personal email (P)
        '1990-03-15',             // birthday (P), raw
        '15 Mar 1990',            // birthday (P), as the profile prints it
        'COURSE-HOMS-MARKER',     // course (R)
        'EDUCATION-HOMS-MARKER',  // education (R)
        'SKILL-HOMS-MARKER',      // skills (R)
        '987654',                 // salary (F), raw
        '987,654',                // salary (F), as the profile prints it
    ];

    private City $aleppo;

    private City $homs;

    /** @var array<string, Employee> */
    private array $subjects = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->aleppo = $this->makeCity('ALP', '001');
        $this->homs = $this->makeCity('HMS', '002');

        $this->subjects = [
            'aleppoActive' => $this->subject($this->aleppo, 'AleppoActive'),
            'aleppoFormer' => $this->subject($this->aleppo, 'AleppoFormer', ['is_active' => false]),
            'homsActive' => $this->subject($this->homs, 'HomsActive', [
                'national_number' => 'NID-HOMS-4242',
                'personal_phone' => '0944555666',
                'personal_email' => 'homs.home@example.test',
                'actual_dob' => '1990-03-15',
                'skills' => 'SKILL-HOMS-MARKER',
                'work_phone' => '0933111222',
            ], salary: 987654),
            'homsFormer' => $this->subject($this->homs, 'HomsFormer', ['is_active' => false, 'is_board_member' => true]),
        ];

        $this->subjects['homsActive']->courses()->create(['title' => 'COURSE-HOMS-MARKER']);
        $this->subjects['homsActive']->educations()->create([
            'name' => 'EDUCATION-HOMS-MARKER', 'specialization' => 'Accounting', 'degree' => 1, 'status' => 1,
        ]);
    }

    // --- The matrix (§4.3) -------------------------------------------------

    public function test_employee_sees_active_colleagues_in_every_city_and_no_former_employee(): void
    {
        $viewer = $this->makeUser($this->aleppo, 'employee');

        $this->assertCells($viewer, [
            'aleppoActive' => 'D', 'homsActive' => 'D', 'aleppoFormer' => '-', 'homsFormer' => '-',
        ]);
        $this->assertCell($viewer, $viewer->profile, 'DR');
    }

    public function test_manager_gets_records_for_direct_reports_only_in_any_city(): void
    {
        $managerEmployee = $this->makeEmployee($this->aleppo, 'line.manager');
        $managerPosition = $this->makeContractFor($managerEmployee)->position;
        $viewer = $this->makeUser($this->aleppo, 'manager', [], $managerEmployee);

        // Nobody reports to them yet: Directory tier for everyone.
        $this->assertCells($viewer, [
            'aleppoActive' => 'D', 'homsActive' => 'D', 'aleppoFormer' => '-', 'homsFormer' => '-',
        ]);
        $this->assertCell($viewer, $managerEmployee, 'DR');

        // A direct report in another city: records. The technical line no
        // longer exists, so a tech report stays at the Directory tier, and a
        // former employee who reported directly is still not shown at all.
        $this->positionOf('homsActive')->update(['direct_manager_id' => $managerPosition->id]);
        $this->positionOf('aleppoActive')->update(['tech_manager_id' => $managerPosition->id]);
        $this->positionOf('homsFormer')->update(['direct_manager_id' => $managerPosition->id]);

        $this->assertCells($viewer, [
            'homsActive' => 'DR', 'aleppoActive' => 'D', 'homsFormer' => '-', 'aleppoFormer' => '-',
        ]);
    }

    public function test_general_manager_hr_syr_and_super_admin_see_records_for_everyone_including_former(): void
    {
        foreach (['general-manager', 'HR-SYR', 'super-admin'] as $role) {
            $this->assertCells($this->makeUser($this->aleppo, $role), [
                'aleppoActive' => 'DR', 'homsActive' => 'DR', 'aleppoFormer' => 'DR', 'homsFormer' => 'DR',
            ], $role);
        }
    }

    public function test_regional_hr_sees_its_city_in_full_and_other_cities_at_directory_tier_only(): void
    {
        $viewer = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view']);

        $this->assertCells($viewer, [
            'aleppoActive' => 'DR', 'aleppoFormer' => 'DR', 'homsActive' => 'D', 'homsFormer' => '-',
        ]);
    }

    public function test_the_query_constraint_and_the_record_check_agree_for_every_role(): void
    {
        foreach (['employee', 'manager', 'general-manager', 'HR-ALP', 'HR-HMS', 'HR-SYR', 'super-admin'] as $role) {
            $viewer = $this->makeUser($this->aleppo, $role);
            // Read after makeUser(): it adds the viewer's own employee row.
            $everyone = Employee::query()->get();

            $expected = $everyone->filter(fn (Employee $employee) => Gate::forUser($viewer)->allows('viewDirectory', $employee))
                ->pluck('id')->sort()->values()->all();
            $actual = DirectoryAccess::constrain(Employee::query(), $viewer)->pluck('id')->sort()->values()->all();

            $this->assertSame($expected, $actual, "Row set and record check disagree for {$role}");
        }

        // Self is always in the cell, even a record marked inactive.
        $self = $this->makeEmployee($this->aleppo, 'inactive.self', ['is_active' => false]);
        $viewer = $this->makeUser($this->aleppo, 'employee', [], $self);

        $this->assertTrue(Gate::forUser($viewer)->allows('viewDirectory', $self));
        $this->assertTrue(DirectoryAccess::constrain(Employee::query()->whereKey($self->id), $viewer)->exists());
        $this->assertFalse(DirectoryAccess::constrain(Employee::query()->whereKey($this->subjects['aleppoFormer']->id), $viewer)->exists());
    }

    // --- Photos follow the Directory tier (A2D-Q4, §8) ----------------------

    public function test_view_avatar_follows_view_directory(): void
    {
        $regionalHr = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view']);
        $staff = $this->makeUser($this->aleppo, 'employee');

        $this->assertTrue(Gate::forUser($regionalHr)->allows('viewAvatar', $this->subjects['homsActive']));
        $this->assertFalse(Gate::forUser($regionalHr)->allows('viewAvatar', $this->subjects['homsFormer']));
        $this->assertTrue(Gate::forUser($regionalHr)->allows('viewAvatar', $this->subjects['aleppoFormer']));
        $this->assertFalse(Gate::forUser($staff)->allows('viewAvatar', $this->subjects['aleppoFormer']));

        // Refused like a missing record, not with a 403 (§4.4).
        $this->actingAs($staff)->get(route('employees.avatar', $this->subjects['aleppoFormer']))->assertNotFound();
        $this->actingAs($staff)->get(route('employees.avatar', $this->subjects['homsActive']))->assertOk();
    }

    // --- Profile drawer (§4.4, §8) ------------------------------------------

    public function test_the_drawer_refuses_unknown_malformed_and_former_ids_identically(): void
    {
        $viewer = $this->makeUser($this->aleppo, 'employee');

        foreach ([999999, -5, 0, '12abc', 'abc', '', '1 OR 1=1', 1.5, null, ['id' => 1], $this->subjects['aleppoFormer']->id] as $id) {
            Livewire::actingAs($viewer)
                ->test(ProfileDrawer::class)
                ->call('openProfile', $id)
                ->assertNotFound()
                ->assertSet('employeeId', null);
        }
    }

    public function test_a_directory_only_drawer_carries_no_private_record_or_pay_data(): void
    {
        foreach ([$this->makeUser($this->aleppo, 'employee'), $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view'])] as $viewer) {
            $drawer = Livewire::actingAs($viewer)
                ->test(ProfileDrawer::class)
                ->call('openProfile', $this->subjects['homsActive']->id)
                ->assertOk()
                ->assertSee('HomsActive')
                ->assertSee('0933111222');

            $this->assertPayloadLacks($drawer, self::HOMS_SECRETS);
        }
    }

    public function test_the_drawer_does_not_name_a_former_manager_or_report_to_staff(): void
    {
        $bossPosition = $this->positionOf('aleppoFormer');
        $this->positionOf('aleppoActive')->update(['direct_manager_id' => $bossPosition->id]);
        $this->positionOf('homsFormer')->update(['direct_manager_id' => $this->positionOf('homsActive')->id]);
        $activeReport = $this->subject($this->homs, 'ActiveReport');
        $this->positionOf($activeReport)->update(['direct_manager_id' => $this->positionOf('homsActive')->id]);

        $staff = $this->makeUser($this->aleppo, 'employee');
        $national = $this->makeUser($this->aleppo, 'HR-SYR');

        Livewire::actingAs($staff)->test(ProfileDrawer::class)
            ->call('openProfile', $this->subjects['aleppoActive']->id)
            ->assertDontSee('AleppoFormer');
        Livewire::actingAs($staff)->test(ProfileDrawer::class)
            ->call('openProfile', $this->subjects['homsActive']->id)
            ->assertSee('ActiveReport')
            ->assertDontSee('HomsFormer');

        Livewire::actingAs($national)->test(ProfileDrawer::class)
            ->call('openProfile', $this->subjects['aleppoActive']->id)
            ->assertSee('AleppoFormer');
        Livewire::actingAs($national)->test(ProfileDrawer::class)
            ->call('openProfile', $this->subjects['homsActive']->id)
            ->assertSee('ActiveReport')
            ->assertSee('HomsFormer');
    }

    public function test_a_role_switch_drops_an_open_drawer_on_the_next_request(): void
    {
        $viewer = $this->assignInactiveRole($this->makeUser($this->aleppo, 'HR-ALP', ['employees.view']), 'employee');

        $former = Livewire::actingAs($viewer)
            ->test(ProfileDrawer::class)
            ->call('openProfile', $this->subjects['aleppoFormer']->id)
            ->assertSet('employeeId', $this->subjects['aleppoFormer']->id)
            ->assertSee('AleppoFormer');

        $active = Livewire::actingAs($viewer)
            ->test(ProfileDrawer::class)
            ->call('openProfile', $this->subjects['aleppoActive']->id);

        $viewer->setActiveRole(Role::findByName('employee')->id);

        // The former employee is outside the employee role's cell: closed.
        $former->call('$refresh')
            ->assertOk()
            ->assertSet('employeeId', null)
            ->assertDispatched('profile-unavailable')
            ->assertDontSee('AleppoFormer');

        // An active colleague is still in it (Directory tier): stays open.
        $active->call('$refresh')
            ->assertSet('employeeId', $this->subjects['aleppoActive']->id)
            ->assertNotDispatched('profile-unavailable')
            ->assertSee('AleppoActive');
    }

    // --- Profile page (§4.3 actions, §4.4) ----------------------------------

    public function test_the_profile_page_404s_for_unknown_and_former_employees_alike(): void
    {
        $staff = $this->makeUser($this->aleppo, 'employee');

        $this->actingAs($staff)->get(route('employees.show', ['employee' => 'no.such.person']))->assertNotFound();
        $this->actingAs($staff)->get(route('employees.show', ['employee' => $this->subjects['aleppoFormer']->username]))->assertNotFound();
        $this->actingAs($staff)->get(route('employees.show', ['employee' => $this->subjects['homsActive']->username]))->assertOk();

        Livewire::actingAs($staff)
            ->test(EmployeeProfile::class, ['employee' => $this->subjects['aleppoFormer']])
            ->assertNotFound();
    }

    public function test_regional_hr_gets_another_citys_profile_at_directory_tier_only(): void
    {
        $viewer = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view', 'employees.update', 'badges.view']);

        $profile = Livewire::actingAs($viewer)
            ->test(EmployeeProfile::class, ['employee' => $this->subjects['homsActive']])
            ->assertOk()
            ->assertSet('canViewRecords', false)
            ->assertSet('canViewPrivate', false)
            ->assertSet('canViewFinancial', false)
            ->assertSet('canEdit', false)
            ->assertSet('canViewBadges', false)
            ->assertSee('HomsActive')
            ->assertSee('0933111222')
            ->assertDontSee(__('general.career-history'))
            ->assertDontSee(__('general.years-of-service'));

        $this->assertPayloadLacks($profile, self::HOMS_SECRETS);

        // The same officer still gets the full record in their own city.
        Livewire::actingAs($viewer)
            ->test(EmployeeProfile::class, ['employee' => $this->subjects['aleppoActive']])
            ->assertSet('canViewRecords', true)
            ->assertSet('canViewPrivate', true)
            ->assertSet('canViewFinancial', true)
            ->assertSee(__('general.years-of-service'));
    }

    public function test_a_colleague_at_directory_tier_gets_no_private_record_or_pay_data(): void
    {
        $profile = Livewire::actingAs($this->makeUser($this->aleppo, 'employee'))
            ->test(EmployeeProfile::class, ['employee' => $this->subjects['homsActive']])
            ->assertOk()
            ->assertSet('canViewRecords', false)
            ->assertSee('HomsActive');

        $this->assertPayloadLacks($profile, self::HOMS_SECRETS);
    }

    public function test_the_profile_rechecks_the_active_role_on_every_request(): void
    {
        $viewer = $this->assignInactiveRole($this->makeUser($this->aleppo, 'HR-ALP', ['employees.view']), 'employee');
        $this->subjects['aleppoActive']->update(['national_number' => 'NID-ALEPPO-1']);

        $active = Livewire::actingAs($viewer)
            ->test(EmployeeProfile::class, ['employee' => $this->subjects['aleppoActive']->fresh()])
            ->assertSet('canViewPrivate', true)
            ->assertSee('NID-ALEPPO-1');
        $former = Livewire::actingAs($viewer)
            ->test(EmployeeProfile::class, ['employee' => $this->subjects['aleppoFormer']])
            ->assertOk();

        $viewer->setActiveRole(Role::findByName('employee')->id);

        $active->call('$refresh')
            ->assertSet('canViewPrivate', false)
            ->assertSet('canViewRecords', false)
            ->assertDontSee('NID-ALEPPO-1');
        $former->call('$refresh')->assertNotFound();
    }

    // --- Directory list and CSV (A2D-Q3, §4.3 actions) ----------------------

    public function test_the_directory_row_set_follows_the_viewers_cell_whatever_the_status_filter(): void
    {
        $everyoneVisible = ['AleppoActive', 'AleppoFormer', 'HomsActive', 'HomsFormer'];

        $cases = [
            'employee' => ['AleppoActive', 'HomsActive'],
            'manager' => ['AleppoActive', 'HomsActive'],
            'HR-ALP' => ['AleppoActive', 'AleppoFormer', 'HomsActive'],
            'general-manager' => $everyoneVisible,
            'HR-SYR' => $everyoneVisible,
            'super-admin' => $everyoneVisible,
        ];

        foreach ($cases as $role => $expected) {
            $viewer = $this->makeUser($this->aleppo, $role, ['positions.view', 'positions.export']);
            $directory = Livewire::actingAs($viewer)->test(Directory::class);

            $this->assertSame($expected, $this->directoryNames($directory), "{$role}, no status filter");

            // The board filter must not bring back a former board member.
            $this->assertSame(
                in_array('HomsFormer', $expected, true) ? ['HomsFormer'] : [],
                $this->directoryNames($directory->set('status', 'board')),
                "{$role}, board filter",
            );
        }
    }

    public function test_the_csv_export_has_directory_columns_only_and_the_viewers_row_set(): void
    {
        $viewer = $this->makeUser($this->aleppo, 'HR-ALP', ['positions.view', 'positions.export']);
        $directory = Livewire::actingAs($viewer)->test(Directory::class);
        $listed = $this->directoryNames($directory);

        $csv = base64_decode(data_get($directory->call('exportCsv')->effects, 'download.content'));
        $rows = array_map('str_getcsv', array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $csv))));

        $this->assertSame([
            __('general.name'), __('general.position'), trans_choice('general.program', 1),
            trans_choice('general.department', 1), trans_choice('general.center', 1),
            trans_choice('general.city', 1), __('general.work-phone'), __('general.email'),
        ], array_shift($rows));

        $this->assertSame($listed, array_map(fn (array $row) => explode(' ', $row[0])[0], $rows));

        foreach (self::HOMS_SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $csv);
        }
    }

    // --- Org chart and dashboard team card (A2D-Q3) -------------------------

    public function test_the_org_chart_search_never_names_a_former_employee_to_staff(): void
    {
        $this->positionOf('aleppoFormer')->update(['title_en' => 'Unique Archivist', 'title_ar' => 'Unique Archivist']);

        // The page echoes the search term, so the results are inspected
        // rather than the HTML for the name itself.
        $staff = Livewire::actingAs($this->makeUser($this->aleppo, 'employee'))
            ->test(OrgChart::class)
            ->set('search', 'AleppoFormer');
        $this->assertCount(0, $staff->instance()->searchResults);

        // Found by its title, the seat reads as vacant.
        $staff->set('search', 'Unique Archivist')
            ->assertSee('Unique Archivist')
            ->assertSee(__('general.vacant-position'))
            ->assertDontSee('AleppoFormer');

        $management = Livewire::actingAs($this->makeUser($this->aleppo, 'general-manager'))
            ->test(OrgChart::class)
            ->set('search', 'Unique Archivist')
            ->assertSee('AleppoFormer');
        $this->assertSame(
            ['AleppoFormer'],
            $management->instance()->searchResults->flatMap->activeContracts->pluck('employee.first_name_en')->all(),
        );
    }

    public function test_the_dashboard_team_card_does_not_pin_a_former_deputy_for_staff(): void
    {
        $viewerEmployee = $this->subject($this->aleppo, 'ViewerSelf');
        $executive = JobLevel::query()->firstOrCreate(['grade' => 1], ['name_en' => 'Executive', 'name_ar' => 'تنفيذي']);
        $deputyPosition = $this->makePosition($this->aleppo, [
            'title_en' => 'Deputy CEO', 'title_ar' => 'نائب المدير', 'job_level_id' => $executive->id,
        ]);
        $formerDeputy = $this->makeEmployee($this->aleppo, 'former.deputy', ['first_name_en' => 'FormerDeputy', 'is_active' => false]);
        $this->makeContractFor($formerDeputy, ['position_id' => $deputyPosition->id, 'is_active' => false]);

        $staffTeam = Livewire::actingAs($this->makeUser($this->aleppo, 'employee', [], $viewerEmployee))
            ->test(DashboardIndex::class)
            ->viewData('teamMembers');
        $this->assertFalse($staffTeam->contains('id', $formerDeputy->id));

        $managementTeam = Livewire::actingAs($this->makeUser($this->aleppo, 'general-manager', [], $viewerEmployee))
            ->test(DashboardIndex::class)
            ->viewData('teamMembers');
        $this->assertTrue($managementTeam->contains('id', $formerDeputy->id));
    }

    // --- Helpers ------------------------------------------------------------

    /** An employee with a contract running today, named by first name. */
    private function subject(City $city, string $firstName, array $attributes = [], int $salary = 1500): Employee
    {
        $employee = $this->makeEmployee($city, strtolower($firstName).'.'.uniqid(), array_merge([
            'first_name_en' => $firstName,
            'first_name_ar' => $firstName,
        ], $attributes));

        $this->makeContractFor($employee, [
            'start_date' => today()->subMonth()->toDateString(),
            'end_date' => today()->addYear()->toDateString(),
            'salary' => $salary,
        ]);

        return $employee->fresh();
    }

    private function positionOf(Employee|string $employee)
    {
        $employee = is_string($employee) ? $this->subjects[$employee] : $employee;

        return $employee->allContracts()->first()->position;
    }

    /** @param  array<string, string>  $cells  subject key => '-', 'D' or 'DR' */
    private function assertCells(User $viewer, array $cells, string $label = ''): void
    {
        foreach ($cells as $key => $cell) {
            $this->assertCell($viewer, $this->subjects[$key], $cell, trim("{$label} {$key}"));
        }
    }

    private function assertCell(User $viewer, Employee $employee, string $cell, string $label = 'self'): void
    {
        $gate = Gate::forUser($viewer);

        $this->assertSame(str_contains($cell, 'D'), $gate->allows('viewDirectory', $employee), "{$label}: Directory tier");
        $this->assertSame(str_contains($cell, 'R'), $gate->allows('viewRecords', $employee), "{$label}: records tier");
    }

    private function assertPayloadLacks($component, array $secrets): void
    {
        $payload = $component->html().json_encode($component->snapshot).json_encode($component->effects);

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
    }

    /** First names in the order the directory lists them. */
    private function directoryNames($directory): array
    {
        return collect($directory->viewData('employees')->items())
            ->map(fn (Employee $employee) => $employee->first_name_en)
            ->all();
    }
}
