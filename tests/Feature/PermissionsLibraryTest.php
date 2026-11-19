<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Livewire\Employee\EntitlementsPanel;
use App\Livewire\Employee\OffboardingJourneyPanel;
use App\Livewire\Entitlements\Library;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\EmployeeEntitlement;
use App\Models\Entitlement;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\PositionEntitlement;
use App\Models\Program;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\OffboardingJourneys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Permissions Library (meeting 19/11, track 5): professional access outside
 * the system, recorded per person from their position's defaults, kept by HR
 * of their city and their direct manager, and never a Spatie permission.
 */
class PermissionsLibraryTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
        $this->travelTo('2026-11-19 10:00:00');
        $this->f = $this->fixtures();
    }

    // ── Defaults from a contract ─────────────────────────────────────

    public function test_a_new_contract_records_the_position_and_job_level_defaults_as_required(): void
    {
        $database = $this->entitlement('IT database', 'database', position: $this->f['position']);
        $cameras = $this->entitlement('Cameras', 'camera', jobLevel: $this->f['officerLevel']);
        $retired = $this->entitlement('Old group', 'telegram', position: $this->f['position']);
        $retired->update(['is_active' => false]);
        $this->entitlement('Elsewhere', 'other', position: $this->f['otherPosition']);

        $contract = $this->contract('new.hire');

        $rows = EmployeeEntitlement::query()->where('employee_id', $contract->employee_id)->get();
        $this->assertEqualsCanonicalizing([$database->id, $cameras->id], $rows->pluck('entitlement_id')->all());
        $this->assertTrue($rows->every(fn ($row) => $row->state === EmployeeEntitlement::STATE_REQUIRED && $row->source === EmployeeEntitlement::SOURCE_DEFAULT));
    }

    public function test_a_draft_records_nothing_until_it_becomes_a_real_contract(): void
    {
        $item = $this->entitlement('IT database', 'database', position: $this->f['position']);
        $draft = $this->contract('drafted', ['type' => ContractTypeEnum::Draft]);

        $this->assertSame(0, EmployeeEntitlement::query()->count());

        $draft->update(['type' => ContractTypeEnum::FullTime]);

        $this->assertSame([$item->id], EmployeeEntitlement::query()->where('employee_id', $draft->employee_id)->pluck('entitlement_id')->all());
    }

    public function test_a_renewal_does_not_duplicate_and_keeps_what_was_decided(): void
    {
        $database = $this->entitlement('IT database', 'database', position: $this->f['position']);
        $group = $this->entitlement('IT group', 'telegram', position: $this->f['position']);
        $first = $this->contract('renewed', ['end_date' => '2026-12-31']);
        $employee = $first->employee;

        $rows = EmployeeEntitlement::query()->where('employee_id', $employee->id)->get()->keyBy('entitlement_id');
        $library = app(Entitlements::class);
        $library->markGranted($rows[$database->id], $this->f['hr']);
        $library->revoke($rows[$group->id], $this->f['hr']);

        // The renewal is issued while the first contract still runs.
        $this->contract('renewed', ['employee_id' => $employee->id, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

        $rows = EmployeeEntitlement::query()->where('employee_id', $employee->id)->get()->keyBy('entitlement_id');
        $this->assertCount(2, $rows);
        $this->assertSame(EmployeeEntitlement::STATE_GRANTED, $rows[$database->id]->state);
        $this->assertSame(EmployeeEntitlement::STATE_REVOKED, $rows[$group->id]->state);
    }

    public function test_coming_back_after_leaving_makes_revoked_defaults_required_again(): void
    {
        $group = $this->entitlement('IT group', 'telegram', position: $this->f['position']);
        $first = $this->contract('returning', ['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        $row = EmployeeEntitlement::query()->where('employee_id', $first->employee_id)->sole();
        app(Entitlements::class)->revoke($row, $this->f['hr']);
        $first->update(['is_active' => false]);

        $this->contract('returning', ['employee_id' => $first->employee_id]);

        $this->assertSame(EmployeeEntitlement::STATE_REQUIRED, $row->fresh()->state);
        $this->assertSame(1, EmployeeEntitlement::query()->where('employee_id', $first->employee_id)->count());
    }

    public function test_a_move_adds_the_new_defaults_and_marks_the_old_ones_for_review_without_revoking(): void
    {
        $old = $this->entitlement('Officer database', 'database', position: $this->f['position']);
        $new = $this->entitlement('Other group', 'telegram', position: $this->f['otherPosition']);
        $manual = $this->entitlement('Printer', 'it_maintenance');
        $contract = $this->contract('mover');
        $employee = $contract->employee;
        app(Entitlements::class)->add($employee, $manual, $this->f['hr']);

        $contract->update(['position_id' => $this->f['otherPosition']->id]);

        $rows = EmployeeEntitlement::query()->where('employee_id', $employee->id)->get()->keyBy('entitlement_id');
        $this->assertSame(EmployeeEntitlement::STATE_REQUIRED, $rows[$new->id]->state);
        $this->assertSame(EmployeeEntitlement::STATE_REQUIRED, $rows[$old->id]->state, 'Never revoked by the move.');
        $this->assertNotNull($rows[$old->id]->needs_review_at);
        $this->assertNull($rows[$manual->id]->needs_review_at, 'An item added by hand is not a default to review.');

        Livewire::actingAs($this->f['hr'])->test(EntitlementsPanel::class, ['employee' => $employee])
            ->call('keep', $rows[$old->id]->id);

        $this->assertNull($rows[$old->id]->fresh()->needs_review_at);
    }

    // ── The panel: add, mark done, revoke ────────────────────────────

    public function test_city_hr_adds_marks_done_and_revokes_an_item_by_hand(): void
    {
        $item = $this->entitlement('Cameras', 'camera');
        $employee = $this->contract('hand')->employee;

        $panel = Livewire::actingAs($this->f['hr'])->test(EntitlementsPanel::class, ['employee' => $employee])
            ->assertSee(__('entitlements.panel_title'))
            ->set('addId', (string) $item->id)
            ->set('note', 'Center gate only')
            ->call('add')
            ->assertHasNoErrors();

        $row = EmployeeEntitlement::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame([EmployeeEntitlement::SOURCE_MANUAL, EmployeeEntitlement::STATE_REQUIRED, 'Center gate only'], [$row->source, $row->state, $row->note]);

        $panel->call('markGranted', $row->id);
        $row->refresh();
        $this->assertSame(EmployeeEntitlement::STATE_GRANTED, $row->state);
        $this->assertSame($this->f['hr']->id, $row->granted_by);

        $panel->call('revoke', $row->id);
        $row->refresh();
        $this->assertSame(EmployeeEntitlement::STATE_REVOKED, $row->state);
        $this->assertSame($this->f['hr']->id, $row->revoked_by);

        // Adding it again brings the same row back, never a second one.
        $panel->set('addId', (string) $item->id)->call('add');
        $this->assertSame(1, EmployeeEntitlement::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(EmployeeEntitlement::STATE_REQUIRED, $row->fresh()->state);

        $this->assertSame(
            ['entitlement_added', 'entitlement_granted', 'entitlement_revoked', 'entitlement_added'],
            Activity::query()->where('log_name', 'entitlements')->orderBy('id')->pluck('description')->all(),
        );
    }

    public function test_the_direct_manager_acts_on_a_direct_report(): void
    {
        $item = $this->entitlement('Team group', 'telegram');
        $employee = $this->contract('report')->employee;

        Livewire::actingAs($this->f['manager'])->test(EntitlementsPanel::class, ['employee' => $employee])
            ->assertSee(__('entitlements.panel_title'))
            ->set('addId', (string) $item->id)
            ->call('add')
            ->assertHasNoErrors();

        $this->assertSame(1, EmployeeEntitlement::query()->where('employee_id', $employee->id)->count());
    }

    public function test_hr_of_another_city_and_a_manager_who_is_not_the_direct_one_are_refused(): void
    {
        $item = $this->entitlement('Team group', 'telegram', position: $this->f['position']);
        $employee = $this->contract('scoped')->employee;
        $row = EmployeeEntitlement::query()->where('employee_id', $employee->id)->sole();

        $otherCity = $this->makeCity('HMS', '002');
        $otherHr = $this->makeUser($otherCity, 'HR-HMS', ['employees.update', 'entitlements.assign']);
        $otherManager = $this->otherManager();
        $plainEmployee = $this->makeUser($this->f['city'], 'employee', [], $employee);

        foreach (['other-city HR' => $otherHr, 'not the direct manager' => $otherManager, 'the employee' => $plainEmployee] as $who => $user) {
            $this->assertFalse(app(Entitlements::class)->canAssign($user, $employee), $who);

            Livewire::actingAs($user)->test(EntitlementsPanel::class, ['employee' => $employee])
                ->assertDontSee(__('entitlements.panel_title'))
                ->call('markGranted', $row->id)
                ->assertForbidden();

            Livewire::actingAs($user)->test(EntitlementsPanel::class, ['employee' => $employee])
                ->set('addId', (string) $item->id)
                ->call('add')
                ->assertForbidden();
        }

        $this->assertSame(EmployeeEntitlement::STATE_REQUIRED, $row->fresh()->state);
    }

    public function test_a_row_of_another_person_cannot_be_reached_through_the_panel(): void
    {
        $this->entitlement('Team group', 'telegram', position: $this->f['position']);
        $mine = $this->contract('mine')->employee;
        $theirs = $this->contract('theirs')->employee;
        $theirRow = EmployeeEntitlement::query()->where('employee_id', $theirs->id)->sole();

        Livewire::actingAs($this->f['hr'])->test(EntitlementsPanel::class, ['employee' => $mine])
            ->call('markGranted', $theirRow->id)
            ->assertNotFound();

        $this->assertSame(EmployeeEntitlement::STATE_REQUIRED, $theirRow->fresh()->state);
    }

    public function test_no_spatie_permission_is_created_or_granted(): void
    {
        $permissions = Permission::query()->count();
        $item = $this->entitlement('IT database', 'database', position: $this->f['position']);
        $contract = $this->contract('nospatie');
        $user = $this->makeUser($this->f['city'], 'employee', [], $contract->employee);
        $before = $user->getAllPermissions()->pluck('name')->sort()->values()->all();

        $row = EmployeeEntitlement::query()->where('employee_id', $contract->employee_id)->sole();
        app(Entitlements::class)->markGranted($row, $this->f['hr']);
        app(Entitlements::class)->add($contract->employee, $this->entitlement('Cameras', 'camera'), $this->f['hr']);

        $this->assertSame($permissions, Permission::query()->count());
        $this->assertSame($before, $user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all());
        $this->assertFalse(Permission::query()->where('name', 'like', '%'.$item->name_en.'%')->exists());
    }

    // ── The library page ─────────────────────────────────────────────

    public function test_hr_creates_an_item_and_links_only_positions_of_its_city(): void
    {
        $otherCity = $this->makeCity('HMS', '002');
        $foreign = $this->position('Foreign IT', ['city_id' => $otherCity->id, 'center_id' => null]);
        $hr = $this->libraryUser($this->f['city'], 'HR-ALP');

        $page = Livewire::actingAs($hr)->test(Library::class)
            ->call('newItem')
            ->set('item.name_en', 'IT database')
            ->set('item.name_ar', 'قاعدة بيانات IT')
            ->set('item.category', 'database')
            ->call('save')
            ->assertHasNoErrors();

        $item = Entitlement::query()->sole();
        $this->assertSame(['IT database', 'database', true], [$item->name_en, $item->category, $item->is_active]);

        $page->call('open', $item->id)
            ->call('addPosition', $this->f['position']->id);
        $this->assertTrue(PositionEntitlement::query()->where(['entitlement_id' => $item->id, 'position_id' => $this->f['position']->id])->exists());

        // Each refused call on a page of its own: a failed request leaves no state to continue from.
        $opened = fn () => Livewire::actingAs($hr)->test(Library::class)->call('open', $item->id);

        $opened()->call('addPosition', $foreign->id)->assertNotFound();
        $this->assertFalse(PositionEntitlement::query()->where('position_id', $foreign->id)->exists());

        // A job level is every city's: only the administrators.
        $opened()->set('jobLevelId', (string) $this->f['officerLevel']->id)->call('addJobLevel')->assertForbidden();
        $this->assertFalse(PositionEntitlement::query()->whereNotNull('job_level_id')->exists());

        $foreignLink = PositionEntitlement::query()->create(['entitlement_id' => $item->id, 'position_id' => $foreign->id]);
        $opened()->call('removeLink', $foreignLink->id)->assertForbidden();
        $this->assertNotNull($foreignLink->fresh());
    }

    public function test_an_administrator_links_a_job_level(): void
    {
        $admin = $this->libraryUser($this->f['city'], 'HR-SYR');
        $item = $this->entitlement('Cameras', 'camera');

        Livewire::actingAs($admin)->test(Library::class)
            ->call('open', $item->id)
            ->set('jobLevelId', (string) $this->f['officerLevel']->id)
            ->call('addJobLevel')
            ->assertHasNoErrors();

        $this->assertTrue(PositionEntitlement::query()->where(['entitlement_id' => $item->id, 'job_level_id' => $this->f['officerLevel']->id])->exists());
    }

    public function test_an_item_someone_held_is_switched_off_not_deleted(): void
    {
        $item = $this->entitlement('IT database', 'database', position: $this->f['position']);
        $this->contract('holder');
        $admin = $this->libraryUser($this->f['city'], 'HR-SYR');

        Livewire::actingAs($admin)->test(Library::class)->call('deleteItem', $item->id);
        $this->assertNotNull($item->fresh());

        Livewire::actingAs($admin)->test(Library::class)->call('toggleActive', $item->id);
        $this->assertFalse($item->fresh()->is_active);
    }

    public function test_the_library_page_is_closed_to_managers_and_employees(): void
    {
        $manager = $this->makeUser($this->f['city'], 'manager', ['entitlements.manage-library']);

        Livewire::actingAs($manager)->test(Library::class)->assertForbidden();
        Livewire::actingAs($this->makeUser($this->f['city'], 'employee'))->test(Library::class)->assertForbidden();
        $this->actingAs($this->makeUser($this->f['city'], 'employee'))
            ->get(route('entitlements.index'))
            ->assertForbidden();
    }

    // ── The exit journey ─────────────────────────────────────────────

    public function test_the_exit_journey_lists_items_still_held_until_they_are_revoked(): void
    {
        $item = $this->entitlement('IT database', 'database', position: $this->f['position']);
        $contract = $this->contract('leaver', ['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $employee = $contract->employee;
        $row = EmployeeEntitlement::query()->where('employee_id', $employee->id)->sole();
        app(Entitlements::class)->markGranted($row, $this->f['hr']);

        $contract->update(['terminated_at' => today()->toDateString(), 'is_active' => false]);

        $steps = collect(app(OffboardingJourneys::class)->steps($employee->fresh()))->keyBy('key');
        $this->assertSame('todo', $steps['entitlements']['state']);
        $this->assertSame([$item->name], $steps['entitlements']['data']['names']);
        $this->assertContains('entitlements', OffboardingJourneys::requiredKeys($steps->values()->all()));

        Livewire::actingAs($this->f['hr'])->test(OffboardingJourneyPanel::class, ['employee' => $employee])
            ->assertSee(__('offboarding.step.entitlements'));

        app(Entitlements::class)->revoke($row, $this->f['hr']);

        $steps = collect(app(OffboardingJourneys::class)->steps($employee->fresh()))->keyBy('key');
        $this->assertSame('done', $steps['entitlements']['state']);
    }

    public function test_a_journey_without_items_keeps_its_steps(): void
    {
        $contract = $this->contract('plain');
        $contract->update(['terminated_at' => today()->toDateString(), 'is_active' => false]);

        $keys = collect(app(OffboardingJourneys::class)->steps($contract->employee->fresh()))->pluck('key')->all();

        $this->assertSame(OffboardingJourneys::STEPS, $keys);
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function entitlement(string $name, string $category, ?Position $position = null, ?JobLevel $jobLevel = null): Entitlement
    {
        $entitlement = Entitlement::query()->create(['name_en' => $name, 'name_ar' => $name, 'category' => $category, 'is_active' => true]);

        if ($position) {
            PositionEntitlement::query()->create(['entitlement_id' => $entitlement->id, 'position_id' => $position->id]);
        }
        if ($jobLevel) {
            PositionEntitlement::query()->create(['entitlement_id' => $entitlement->id, 'job_level_id' => $jobLevel->id]);
        }

        return $entitlement;
    }

    private function contract(string $username, array $overrides = []): Contract
    {
        $employeeId = $overrides['employee_id'] ?? $this->makeEmployee($this->f['city'], 'ent.'.$username.'.'.uniqid())->id;

        return Contract::query()->create(array_merge([
            'employee_id' => $employeeId,
            'position_id' => $this->f['position']->id,
            'contract_type_id' => $this->f['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 2,
            'salary' => 1000,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '17:00:00',
            'is_active' => true,
        ], $overrides))->fresh();
    }

    private function position(string $title, array $overrides = []): Position
    {
        return Position::query()->forceCreate(array_merge($this->f['base'], ['title_en' => $title, 'title_ar' => $title], $overrides));
    }

    /** A manager running a position that is not the report's direct manager. */
    private function otherManager(): User
    {
        $position = $this->position('Other manager');
        $employee = $this->makeEmployee($this->f['city'], 'ent.other.manager');
        $this->contract('other-manager-seat', ['employee_id' => $employee->id, 'position_id' => $position->id]);

        return $this->makeUser($this->f['city'], 'manager', ['entitlements.assign'], $employee);
    }

    private function libraryUser($city, string $role): User
    {
        return $this->makeUser($city, $role, ['entitlements.manage-library', 'entitlements.assign', 'employees.update']);
    }

    private function fixtures(): array
    {
        $city = $this->makeCity('ALP', '001');
        $center = Center::query()->create([
            'name_en' => 'Hope Center', 'name_ar' => 'مركز الرجاء', 'city_id' => $city->id,
            'is_active' => true, 'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);
        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $officerLevel = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 5]);
        $managerLevel = JobLevel::query()->forceCreate(['name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 3]);
        $program = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => 'CORE', 'is_active' => true]);
        $base = [
            'center_id' => $center->id, 'city_id' => $city->id, 'department_id' => $department->id,
            'job_level_id' => $officerLevel->id, 'program_id' => $program->id,
            'max_emp' => 50, 'exist_emp' => 0, 'is_active' => true,
        ];
        $managerPosition = Position::query()->forceCreate(['job_level_id' => $managerLevel->id] + $base + ['title_en' => 'Manager', 'title_ar' => 'مدير']);
        $position = Position::query()->forceCreate($base + ['title_en' => 'IT Specialist', 'title_ar' => 'أخصائي IT', 'direct_manager_id' => $managerPosition->id]);
        $otherPosition = Position::query()->forceCreate(['job_level_id' => $managerLevel->id] + $base + ['title_en' => 'Coordinator', 'title_ar' => 'منسق', 'direct_manager_id' => $managerPosition->id]);

        $annualType = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);

        $this->f = compact('city', 'position', 'annualType', 'base');

        $managerEmployee = $this->makeEmployee($city, 'ent.manager');
        $this->contract('manager-seat', ['employee_id' => $managerEmployee->id, 'position_id' => $managerPosition->id]);
        $manager = $this->makeUser($city, 'manager', ['entitlements.assign'], $managerEmployee);
        $hr = $this->makeUser($city, 'HR-ALP', ['employees.update', 'employees.view', 'entitlements.assign', 'entitlements.manage-library']);

        return compact('city', 'position', 'otherPosition', 'officerLevel', 'annualType', 'base', 'manager', 'hr');
    }
}
