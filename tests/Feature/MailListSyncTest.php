<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Timesheets;
use App\Livewire\MailLists\Check;
use App\Livewire\MailLists\Index;
use App\Models\City;
use App\Models\Department;
use App\Models\Employee;
use App\Models\MailList;
use App\Models\MailListMember;
use App\Models\Permission;
use App\Models\Program;
use App\Models\User;
use App\Services\MailListSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Each mail list against the people who should be on it by HR data (MailListSync), the page that
 * shows it, and the "Email this group" buttons that use the list of a city, project or department.
 */
class MailListSyncTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $aleppo;

    private City $damascus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aleppo = $this->makeCity('ALP', '001');
        $this->damascus = $this->makeCity('DMS', '002');
    }

    public function test_the_all_staff_list_reports_who_is_missing_and_who_has_left(): void
    {
        $this->person('anna', $this->aleppo);
        $this->person('basil', $this->aleppo);
        $this->person('carla', $this->damascus, active: false);

        $list = $this->list('team@hcsyria.org', ['rule_type' => 'all_staff'], [
            'anna@hcsyria.org' => 'active',
            'carla@hcsyria.org' => 'active',
            'someone@outside.org' => 'active',
        ]);

        $result = app(MailListSync::class)->compare($list);

        $this->assertSame(['basil@hcsyria.org'], $result['missing']->pluck('email')->all());
        $this->assertSame(['carla@hcsyria.org'], $result['left']->pluck('email')->all());
        // An outside address has nothing in HR data to compare with.
        $this->assertTrue($result['not_matching']->isEmpty());
        $this->assertFalse($result['in_sync']);
    }

    public function test_a_custom_rule_matches_city_project_and_department_and_flags_the_rest(): void
    {
        $education = Program::query()->create(['name' => 'Education', 'code' => 'EDU', 'slug' => 'edu', 'is_active' => true]);
        $health = Program::query()->create(['name' => 'Health', 'code' => 'HLT', 'slug' => 'hlt', 'is_active' => true]);

        $this->person('anna', $this->aleppo, ['program_id' => $education->id]);
        $this->person('basil', $this->aleppo, ['program_id' => $health->id]);
        $this->person('carla', $this->damascus, ['program_id' => $education->id]);

        $list = $this->list('edu.aleppo@hcsyria.org', [
            'rule_type' => 'custom', 'rule_city_id' => $this->aleppo->id, 'rule_program_id' => $education->id,
        ], ['basil@hcsyria.org' => 'active']);

        $result = app(MailListSync::class)->compare($list);

        $this->assertSame(['anna@hcsyria.org'], $result['missing']->pluck('email')->all());
        $this->assertSame(['basil@hcsyria.org'], $result['not_matching']->pluck('email')->all());
        $this->assertSame(['anna@hcsyria.org'], $result['expected']->keys()->all());
    }

    public function test_a_list_with_no_rule_is_not_checked_and_a_matching_list_is_in_sync(): void
    {
        $this->person('anna', $this->aleppo);
        $plain = $this->list('misc@hcsyria.org', []);
        $team = $this->list('team@hcsyria.org', ['rule_type' => 'all_staff'], ['anna@hcsyria.org' => 'pending']);

        $sync = app(MailListSync::class);

        $this->assertSame([$team->id], $sync->all()->pluck('list.id')->all());
        $this->assertTrue($sync->compare($team)['in_sync']);
        // Added here and waiting for the control panel is not "missing".
        $this->assertSame(['anna@hcsyria.org'], $sync->compare($team)['waiting']->pluck('email')->all());
        $this->assertFalse($plain->hasRule());
    }

    public function test_hr_queues_the_missing_as_pending_but_only_people_who_belong(): void
    {
        $this->person('anna', $this->aleppo);
        $this->person('basil', $this->aleppo);
        $list = $this->list('team@hcsyria.org', ['rule_type' => 'all_staff']);
        $hr = $this->makeUser($this->aleppo, 'HR-ALP', ['mail-lists.manage']);

        Livewire::actingAs($hr)->test(Check::class)
            ->assertSee('anna@hcsyria.org')
            ->call('queueOne', $list->id, 'nobody@hcsyria.org')
            ->call('queueOne', $list->id, 'anna@hcsyria.org');

        $this->assertSame(['anna@hcsyria.org'], $list->members()->pluck('email')->all());
        $this->assertSame(MailListMember::PENDING, $list->members()->first()->status);

        Livewire::actingAs($hr)->test(Check::class)->call('queueMissing', $list->id);

        $this->assertEqualsCanonicalizing(
            ['anna@hcsyria.org', 'basil@hcsyria.org'],
            $list->members()->pluck('email')->all(),
        );
        $this->assertSame(0, MailListMember::query()->where('status', MailListMember::ACTIVE)->count());
    }

    public function test_only_hr_opens_the_check_and_edits_a_rule(): void
    {
        $list = $this->list('hope.aleppo@hcsyria.org', []);
        $employee = $this->makeUser($this->aleppo, 'employee', []);
        $hr = $this->makeUser($this->aleppo, 'HR-ALP', ['mail-lists.manage']);

        Livewire::actingAs($employee)->test(Check::class)->call('editRule', $list->id)->assertForbidden();

        Livewire::actingAs($hr)->test(Check::class)
            ->call('editRule', $list->id)
            ->set('ruleType', 'custom')
            ->call('saveRule')
            ->assertHasErrors('ruleType')
            ->set('ruleCity', $this->aleppo->id)
            ->call('saveRule')
            ->assertHasNoErrors();

        $list->refresh();
        $this->assertSame('custom', $list->rule_type);
        $this->assertSame($this->aleppo->id, $list->rule_city_id);
    }

    public function test_the_list_of_a_place_or_project_is_found_by_its_rule_alone(): void
    {
        $program = Program::query()->create(['name' => 'Education', 'code' => 'EDU', 'slug' => 'edu', 'is_active' => true]);
        $department = Department::query()->create(['name' => 'Admin', 'code' => 'ADM']);

        $city = $this->list('hope.aleppo@hcsyria.org', ['rule_type' => 'custom', 'rule_city_id' => $this->aleppo->id]);
        $project = $this->list('edu@hcsyria.org', ['rule_type' => 'custom', 'rule_program_id' => $program->id]);
        $this->list('managers.aleppo@hcsyria.org', ['rule_type' => 'custom', 'rule_city_id' => $this->aleppo->id, 'rule_managers_only' => true]);

        $this->assertSame($city->id, MailList::emailFor($this->aleppo->id, null, null)?->id);
        $this->assertSame($project->id, MailList::emailFor(null, $program->id, null)?->id);
        // A city and a project together is a list nobody made; a department with none is null; no filter is never a list.
        $this->assertNull(MailList::emailFor($this->aleppo->id, $program->id, null));
        $this->assertNull(MailList::emailFor(null, null, $department->id));
        $this->assertNull(MailList::emailFor(null, null, null));
    }

    public function test_the_attendance_page_offers_to_email_a_group_that_has_a_list(): void
    {
        $program = Program::query()->create(['name' => 'Education', 'code' => 'EDUCATION', 'slug' => 'education', 'is_active' => true]);
        $department = Department::query()->firstOrCreate(['code' => 'TEACHING'], ['name' => 'Teaching']);
        $this->person('anna', $this->aleppo, ['program_id' => $program->id, 'department_id' => $department->id]);
        $this->list('hope.aleppo@hcsyria.org', ['rule_type' => 'custom', 'rule_city_id' => $this->aleppo->id]);
        $this->list('education@hcsyria.org', ['rule_type' => 'custom', 'rule_program_id' => $program->id]);

        $hr = $this->makeUser($this->aleppo, 'HR-SYR', ['attendance.view-own', 'attendance.view']);

        Livewire::actingAs($hr)->test(Timesheets::class)
            ->set('month', now()->format('Y-m'))
            ->set('cityId', null)
            ->assertSeeHtml('href="mailto:hope.aleppo@hcsyria.org"')
            ->assertSeeHtml('href="mailto:education@hcsyria.org"')
            ->set('groupBy', 'department')
            ->assertSeeHtml('href="mailto:hope.aleppo@hcsyria.org"')
            ->assertDontSeeHtml('href="mailto:education@hcsyria.org"');

        $this->assertInstanceOf(Employee::class, Employee::query()->first());
    }

    public function test_a_new_account_is_queued_on_every_list_its_rule_matches(): void
    {
        $team = $this->list('team@hcsyria.org', ['rule_type' => 'all_staff']);
        $aleppo = $this->list('hope.aleppo@hcsyria.org', ['rule_type' => 'custom', 'rule_city_id' => $this->aleppo->id]);
        $damascus = $this->list('hope.damascus@hcsyria.org', ['rule_type' => 'custom', 'rule_city_id' => $this->damascus->id]);

        $this->person('anna', $this->aleppo);

        $this->assertSame(['anna@hcsyria.org'], $team->members()->pluck('email')->all());
        $this->assertSame(['anna@hcsyria.org'], $aleppo->members()->pluck('email')->all());
        $this->assertSame(MailListMember::PENDING, $aleppo->members()->first()->status);
        $this->assertSame(0, $damascus->members()->count());

        // Doing it again adds nobody twice.
        $this->assertSame(0, app(MailListSync::class)->queueForPerson(Employee::query()->first()));
    }

    public function test_someone_who_leaves_is_flagged_for_removal_and_comes_back_when_reactivated(): void
    {
        $user = $this->person('anna', $this->aleppo);
        $team = $this->list('team@hcsyria.org', ['rule_type' => 'all_staff'], ['anna@hcsyria.org' => 'active']);
        $other = $this->list('misc@hcsyria.org', [], ['anna@hcsyria.org' => 'pending']);

        $user->profile->update(['is_active' => false]);

        // In the group at the control panel: flagged. Only queued here: dropped.
        $this->assertSame(MailListMember::LEAVING, $team->members()->first()->status);
        $this->assertSame(0, $other->members()->count());

        $user->profile->update(['is_active' => true]);

        $this->assertSame(MailListMember::ACTIVE, $team->members()->first()->status);
    }

    public function test_the_confirmer_clears_a_removal_and_hr_can_queue_one_from_the_check_page(): void
    {
        $user = $this->person('carla', $this->damascus, active: false);
        $team = $this->list('team@hcsyria.org', ['rule_type' => 'all_staff'], ['carla@hcsyria.org' => 'active']);
        $hr = $this->makeUser($this->aleppo, 'HR-ALP', ['mail-lists.manage']);
        $mai = $this->makeUser($this->aleppo, 'employee', []);
        $mai->givePermissionTo(Permission::findOrCreate('mail-lists.confirm', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $member = $team->members()->first();

        Livewire::actingAs($hr)->test(Check::class)->call('queueRemoval', $member->id);
        $this->assertSame(MailListMember::LEAVING, $member->fresh()->status);

        // HR cannot confirm the removal; the holder of the control panel can, and sees the queue.
        Livewire::actingAs($hr)->test(Index::class)->call('confirmRemoval', $member->id)->assertForbidden();
        Livewire::actingAs($mai)->test(Index::class)
            ->assertSeeHtml('data-testid="mail-lists-leaving"')
            ->call('confirmRemovalEmail', 'carla@hcsyria.org');

        $this->assertSame(0, $team->members()->count());
        $this->assertInstanceOf(User::class, $user);
    }

    /** An employee with a contract running today and an account on the organisation's domain. */
    private function person(string $key, City $city, array $position = [], bool $active = true): User
    {
        $employee = $this->makeEmployee($city, $key, ['first_name_en' => ucfirst($key), 'is_active' => $active]);
        $contractPosition = $this->makePosition($city, $position);
        $this->makeContractFor($employee, ['position_id' => $contractPosition->id]);

        $user = User::factory()->create(['employee_id' => $employee->id, 'email' => $key.'@hcsyria.org']);

        return $user;
    }

    private function list(string $address, array $rule, array $members = []): MailList
    {
        $list = MailList::query()->create(['name' => $address, 'address' => $address, 'position' => 1] + $rule);

        foreach ($members as $email => $status) {
            $list->members()->create(['email' => $email, 'status' => $status]);
        }

        return $list;
    }
}
