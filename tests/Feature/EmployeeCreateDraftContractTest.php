<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Livewire\Employee\EmployeeCreate;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class EmployeeCreateDraftContractTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_saving_personal_info_shows_the_draft_position_prompt_instead_of_redirecting(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('infoForm.first_name_en', 'Test')->set('infoForm.first_name_ar', 'اختبار')
            ->set('infoForm.last_name_en', 'Person')->set('infoForm.last_name_ar', 'شخص')
            ->set('infoForm.father_name_en', 'Father')->set('infoForm.father_name_ar', 'أب')
            ->set('infoForm.mother_name_en', 'Mother')->set('infoForm.mother_name_ar', 'أم')
            ->set('infoForm.gender', 1)
            ->set('infoForm.marital_status', 1)
            ->set('infoForm.nationality', 1)
            ->set('infoForm.national_number', '12345678901')
            ->set('infoForm.city_id', $fixtures['city']->id)
            ->set('infoForm.username', 'draft.prompt.employee')
            ->set('infoForm.create_account', false)
            ->call('saveInformation')
            ->assertHasNoErrors()
            ->assertSet('showDraftPrompt', true)
            ->assertNoRedirect();

        $this->assertDatabaseHas('employees', ['username' => 'draft.prompt.employee']);
        $this->assertSame(0, Contract::count());
    }

    /**
     * Regression coverage for a real bug: username lives on the Contact tab,
     * but clicking "Next" on the Profile tab validates the whole form. A
     * missing username failed validation while the user was still on
     * Profile, where that field (and its error) is not rendered at all —
     * the click just silently "did nothing" from their point of view.
     */
    public function test_a_validation_error_on_a_contact_only_field_switches_to_the_contact_tab(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('infoForm.first_name_en', 'Test')->set('infoForm.first_name_ar', 'اختبار')
            ->set('infoForm.last_name_en', 'Person')->set('infoForm.last_name_ar', 'شخص')
            ->set('infoForm.father_name_en', 'Father')->set('infoForm.father_name_ar', 'أب')
            ->set('infoForm.mother_name_en', 'Mother')->set('infoForm.mother_name_ar', 'أم')
            ->set('infoForm.gender', 1)
            ->set('infoForm.marital_status', 1)
            ->set('infoForm.nationality', 1)
            ->set('infoForm.national_number', '12345678903')
            ->set('infoForm.city_id', $fixtures['city']->id)
            // username cleared on purpose (it follows the English name) — it lives on Contact, never visited.
            ->set('infoForm.username', '')
            ->call('saveInformation')
            ->assertHasErrors('infoForm.username')
            ->assertSet('activeTab', 'contact')
            ->assertSet('showDraftPrompt', false);
    }

    /** Symmetric case: a Profile-only error while on the Contact tab jumps back to Profile. */
    public function test_a_validation_error_on_a_profile_only_field_switches_back_from_the_contact_tab(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('activeTab', 'contact')
            ->set('infoForm.username', 'draft.tab-switch-back.employee')
            ->set('infoForm.create_account', false)
            // Every Profile field (first_name, gender, city_id, ...) left blank.
            ->call('saveInformation')
            ->assertHasErrors('infoForm.first_name_en')
            ->assertSet('activeTab', 'information');
    }

    /**
     * saveInformation() is bound to a Save/Next button on more than one tab
     * (Profile and Contact both call it on the same underlying Form object),
     * but the draft-position prompt only lives in the Profile tab's view.
     * Regression coverage for a bug caught in manual browser verification:
     * saving from Contact left the user stuck on Contact with a success
     * toast and no visible next step at all.
     */
    public function test_saving_from_the_contact_tab_still_surfaces_the_draft_prompt(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('activeTab', 'contact')
            ->set('infoForm.first_name_en', 'Test')->set('infoForm.first_name_ar', 'اختبار')
            ->set('infoForm.last_name_en', 'Person')->set('infoForm.last_name_ar', 'شخص')
            ->set('infoForm.father_name_en', 'Father')->set('infoForm.father_name_ar', 'أب')
            ->set('infoForm.mother_name_en', 'Mother')->set('infoForm.mother_name_ar', 'أم')
            ->set('infoForm.gender', 1)
            ->set('infoForm.marital_status', 1)
            ->set('infoForm.nationality', 1)
            ->set('infoForm.national_number', '12345678902')
            ->set('infoForm.city_id', $fixtures['city']->id)
            ->set('infoForm.username', 'draft.via.contact.tab')
            ->set('infoForm.create_account', false)
            ->call('saveInformation')
            ->assertHasNoErrors()
            ->assertSet('showDraftPrompt', true)
            ->assertSet('activeTab', 'information');
    }

    public function test_reserving_a_position_creates_a_draft_contract_and_redirects_to_the_edit_page(): void
    {
        $fixtures = $this->makeFixtures();

        $employee = Employee::query()->create([
            'city_id' => $fixtures['city']->id,
            'first_name_en' => 'Test', 'first_name_ar' => 'اختبار',
            'last_name_en' => 'Person', 'last_name_ar' => 'شخص',
            'username' => 'draft.reserve.employee',
            'is_active' => true,
        ]);

        Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('infoForm.employee', $employee)
            ->set('draftCityId', $fixtures['city']->id)
            ->set('draftForm.position_id', $fixtures['position']->id)
            ->call('createDraftAndContinue')
            ->assertRedirect(route('employees.edit', $employee->id));

        $contract = Contract::where('employee_id', $employee->id)->first();

        $this->assertNotNull($contract);
        $this->assertSame(ContractTypeEnum::Draft, (int) $contract->type);
        $this->assertSame($fixtures['position']->id, $contract->position_id);
        $this->assertNull($contract->start_date);
        $this->assertNull($contract->weekly_hours);
    }

    public function test_reserving_a_position_outside_the_employee_city_is_rejected(): void
    {
        $fixtures = $this->makeFixtures();
        $otherCity = $this->makeCity('HMS', '002');
        $otherPosition = Position::query()->forceCreate([
            'city_id' => $otherCity->id,
            'department_id' => $fixtures['department']->id,
            'job_level_id' => $fixtures['jobLevel']->id,
            'program_id' => $fixtures['program']->id,
            'title_en' => 'Remote Officer', 'title_ar' => 'مسؤول عن بعد',
            'max_emp' => 2, 'exist_emp' => 0, 'is_active' => true,
        ]);

        $employee = Employee::query()->create([
            'city_id' => $fixtures['city']->id,
            'first_name_en' => 'Test', 'first_name_ar' => 'اختبار',
            'last_name_en' => 'Person', 'last_name_ar' => 'شخص',
            'username' => 'draft.reject.employee',
            'is_active' => true,
        ]);

        Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('infoForm.employee', $employee)
            ->set('draftCityId', $fixtures['city']->id)
            ->set('draftForm.position_id', $otherPosition->id)
            ->call('createDraftAndContinue')
            ->assertHasErrors('draftForm.position_id');

        $this->assertSame(0, Contract::where('employee_id', $employee->id)->count());
    }

    public function test_skipping_the_draft_prompt_redirects_without_creating_a_contract(): void
    {
        $fixtures = $this->makeFixtures();

        $employee = Employee::query()->create([
            'city_id' => $fixtures['city']->id,
            'first_name_en' => 'Test', 'first_name_ar' => 'اختبار',
            'last_name_en' => 'Person', 'last_name_ar' => 'شخص',
            'username' => 'draft.skip.employee',
            'is_active' => true,
        ]);

        Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('infoForm.employee', $employee)
            ->call('skipDraft')
            ->assertRedirect(route('employees.edit', $employee->id));

        $this->assertSame(0, Contract::where('employee_id', $employee->id)->count());
    }

    public function test_a_draft_does_not_block_on_a_position_already_at_capacity(): void
    {
        $fixtures = $this->makeFixtures();
        $fixtures['position']->update(['max_emp' => 1, 'exist_emp' => 1]);

        $employee = Employee::query()->create([
            'city_id' => $fixtures['city']->id,
            'first_name_en' => 'Test', 'first_name_ar' => 'اختبار',
            'last_name_en' => 'Person', 'last_name_ar' => 'شخص',
            'username' => 'draft.overcapacity.employee',
            'is_active' => true,
        ]);

        Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('infoForm.employee', $employee)
            ->set('draftCityId', $fixtures['city']->id)
            ->set('draftForm.position_id', $fixtures['position']->id)
            ->call('createDraftAndContinue')
            ->assertHasNoErrors();

        $this->assertSame(1, Contract::where('employee_id', $employee->id)->count());
    }

    public function test_a_headquarters_level_position_is_labeled_with_its_own_national_city(): void
    {
        $fixtures = $this->makeFixtures();
        $syria = $this->makeCity('SYR', '000');
        $hqPosition = Position::query()->forceCreate([
            'center_id' => $fixtures['center']->id, // the center itself sits in $fixtures['city'] (Aleppo)
            'city_id' => $syria->id, // but this position's own label is the national scope
            'department_id' => $fixtures['department']->id,
            'job_level_id' => $fixtures['jobLevel']->id,
            'program_id' => $fixtures['program']->id,
            'title_en' => 'Chief Executive Officer',
            'title_ar' => 'المدير التنفيذي',
            'max_emp' => 1,
            'exist_emp' => 0,
            'is_active' => true,
        ]);
        $employee = $this->makeSavedEmployee($fixtures, 'draft.hq-label.employee');

        $component = Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('infoForm.employee', $employee)
            ->set('draftCityId', $fixtures['city']->id)
            ->set('draftProgramId', $fixtures['program']->id);

        $options = $component->instance()->positionOptionsForDraft();

        // makeCity() names a city after its own slug, so the suffix here is
        // literally "(SYR)" — in the real app (config/samples/sampleData.php)
        // that same row is named "Syria" / "سوريا".
        $this->assertArrayHasKey($hqPosition->id, $options);
        $this->assertSame('Chief Executive Officer (SYR)', $options[$hqPosition->id]);
        // A position whose own city genuinely is $fixtures['city'] must stay unlabeled.
        $this->assertSame('Program Officer', $options[$fixtures['position']->id]);
    }

    public function test_position_options_are_empty_until_a_program_is_chosen(): void
    {
        $fixtures = $this->makeFixtures();
        $employee = $this->makeSavedEmployee($fixtures, 'draft.no-program.employee');

        $component = Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('infoForm.employee', $employee)
            ->set('draftCityId', $fixtures['city']->id);

        $this->assertSame([], $component->instance()->positionOptionsForDraft());
        $this->assertCount(2, $component->instance()->programOptionsForDraft());
    }

    public function test_choosing_a_program_narrows_the_position_options_to_it(): void
    {
        $fixtures = $this->makeFixtures();
        $employee = $this->makeSavedEmployee($fixtures, 'draft.narrowed.employee');

        $component = Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('infoForm.employee', $employee)
            ->set('draftCityId', $fixtures['city']->id)
            ->set('draftProgramId', $fixtures['program']->id);

        $options = $component->instance()->positionOptionsForDraft();

        $this->assertArrayHasKey($fixtures['position']->id, $options);
        $this->assertArrayNotHasKey($fixtures['otherPosition']->id, $options);
    }

    public function test_changing_the_program_clears_a_previously_chosen_position(): void
    {
        $fixtures = $this->makeFixtures();
        $employee = $this->makeSavedEmployee($fixtures, 'draft.reset.employee');

        Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('infoForm.employee', $employee)
            ->set('draftCityId', $fixtures['city']->id)
            ->set('draftProgramId', $fixtures['program']->id)
            ->set('draftForm.position_id', $fixtures['position']->id)
            ->set('draftProgramId', $fixtures['otherProgram']->id)
            ->assertSet('draftForm.position_id', null);
    }

    public function test_changing_the_city_clears_a_previously_chosen_program_and_position(): void
    {
        $fixtures = $this->makeFixtures();
        $otherCity = $this->makeCity('HMS', '002');
        $employee = $this->makeSavedEmployee($fixtures, 'draft.city-reset.employee');
        $nationwideHr = $this->makeUser($fixtures['city'], 'HR-SYR', ['employees.create', 'employees.update']);

        Livewire::actingAs($nationwideHr)
            ->test(EmployeeCreate::class)
            ->set('infoForm.employee', $employee)
            ->set('draftCityId', $fixtures['city']->id)
            ->set('draftProgramId', $fixtures['program']->id)
            ->set('draftForm.position_id', $fixtures['position']->id)
            ->set('draftCityId', $otherCity->id)
            ->assertSet('draftProgramId', null)
            ->assertSet('draftForm.position_id', null);
    }

    public function test_reserving_a_position_from_a_different_program_than_selected_is_rejected(): void
    {
        $fixtures = $this->makeFixtures();
        $employee = $this->makeSavedEmployee($fixtures, 'draft.program-mismatch.employee');

        Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('infoForm.employee', $employee)
            ->set('draftCityId', $fixtures['city']->id)
            ->set('draftProgramId', $fixtures['program']->id)
            // Bypasses the dropdown entirely: position belongs to otherProgram,
            // not the program the (still-set) draftProgramId claims.
            ->set('draftForm.position_id', $fixtures['otherPosition']->id)
            ->call('createDraftAndContinue')
            ->assertHasErrors('draftForm.position_id');

        $this->assertSame(0, Contract::where('employee_id', $employee->id)->count());
    }

    public function test_a_city_scoped_hr_role_cannot_pick_any_city(): void
    {
        $fixtures = $this->makeFixtures();

        $component = Livewire::actingAs($fixtures['hr'])->test(EmployeeCreate::class);

        $this->assertFalse($component->instance()->canPickAnyDraftCity());
    }

    public function test_hr_syr_can_pick_any_city_and_reserve_a_position_outside_the_employee_city(): void
    {
        $fixtures = $this->makeFixtures();
        $otherCity = $this->makeCity('HMS', '002');
        $otherCityDepartment = Department::query()->forceCreate(['name' => 'HMS Programs', 'code' => 'HPRG']);
        $otherCityPosition = Position::query()->forceCreate([
            'city_id' => $otherCity->id,
            'department_id' => $otherCityDepartment->id,
            'job_level_id' => $fixtures['jobLevel']->id,
            'program_id' => $fixtures['program']->id,
            'title_en' => 'HMS Officer', 'title_ar' => 'مسؤول حمص',
            'max_emp' => 2, 'exist_emp' => 0, 'is_active' => true,
        ]);

        $employee = $this->makeSavedEmployee($fixtures, 'draft.hrsyr.employee'); // lives in $fixtures['city'] (ALP)
        $nationwideHr = $this->makeUser($fixtures['city'], 'HR-SYR', ['employees.create', 'employees.update']);

        $component = Livewire::actingAs($nationwideHr)
            ->test(EmployeeCreate::class)
            ->set('infoForm.employee', $employee);

        $this->assertTrue($component->instance()->canPickAnyDraftCity());

        $component
            ->set('draftCityId', $otherCity->id)
            ->set('draftProgramId', $fixtures['program']->id)
            ->set('draftForm.position_id', $otherCityPosition->id)
            ->call('createDraftAndContinue')
            ->assertHasNoErrors();

        $contract = Contract::where('employee_id', $employee->id)->first();
        $this->assertNotNull($contract);
        $this->assertSame($otherCityPosition->id, $contract->position_id);
    }

    public function test_a_city_scoped_hr_role_cannot_bypass_its_own_city_by_forging_draft_city_id(): void
    {
        $fixtures = $this->makeFixtures();
        $otherCity = $this->makeCity('HMS', '002');
        $otherCityDepartment = Department::query()->forceCreate(['name' => 'HMS Programs 2', 'code' => 'HPRG2']);
        $otherCityPosition = Position::query()->forceCreate([
            'city_id' => $otherCity->id,
            'department_id' => $otherCityDepartment->id,
            'job_level_id' => $fixtures['jobLevel']->id,
            'program_id' => $fixtures['program']->id,
            'title_en' => 'HMS Officer 2', 'title_ar' => 'مسؤول حمص 2',
            'max_emp' => 2, 'exist_emp' => 0, 'is_active' => true,
        ]);

        $employee = $this->makeSavedEmployee($fixtures, 'draft.forged-city.employee');

        // HR-ALP has no open city dropdown in the view at all, but a direct
        // request could still try to set draftCityId to another city.
        Livewire::actingAs($fixtures['hr'])
            ->test(EmployeeCreate::class)
            ->set('infoForm.employee', $employee)
            ->set('draftCityId', $otherCity->id)
            ->set('draftProgramId', $fixtures['program']->id)
            ->set('draftForm.position_id', $otherCityPosition->id)
            ->call('createDraftAndContinue')
            ->assertHasErrors('draftForm.position_id');

        $this->assertSame(0, Contract::where('employee_id', $employee->id)->count());
    }

    private function makeSavedEmployee(array $fixtures, string $username): Employee
    {
        return Employee::query()->create([
            'city_id' => $fixtures['city']->id,
            'first_name_en' => 'Test', 'first_name_ar' => 'اختبار',
            'last_name_en' => 'Person', 'last_name_ar' => 'شخص',
            'username' => $username,
            'is_active' => true,
        ]);
    }

    private function makeFixtures(): array
    {
        $city = $this->makeCity('ALP', '001');

        $center = Center::query()->create([
            'name_en' => 'Hope Center',
            'name_ar' => 'مركز الرجاء',
            'city_id' => $city->id,
            'is_active' => true,
            'weekends' => 'friday',
            'open_at' => '08:00:00',
            'close_at' => '16:00:00',
        ]);

        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $program = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => 'CORE', 'is_active' => true]);
        $otherProgram = Program::query()->forceCreate(['name' => 'Outreach Program', 'slug' => 'outreach-program', 'code' => 'OUT', 'is_active' => true]);

        $position = Position::query()->forceCreate([
            'center_id' => $center->id,
            'city_id' => $city->id,
            'department_id' => $department->id,
            'job_level_id' => $jobLevel->id,
            'program_id' => $program->id,
            'title_en' => 'Program Officer',
            'title_ar' => 'مسؤول برنامج',
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);

        $otherPosition = Position::query()->forceCreate([
            'center_id' => $center->id,
            'city_id' => $city->id,
            'department_id' => $department->id,
            'job_level_id' => $jobLevel->id,
            'program_id' => $otherProgram->id,
            'title_en' => 'Outreach Coordinator',
            'title_ar' => 'منسق برنامج التوعية',
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);

        ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);

        $hr = $this->makeUser($city, 'HR-ALP', ['employees.create', 'employees.update']);

        return compact('city', 'center', 'department', 'jobLevel', 'program', 'otherProgram', 'position', 'otherPosition', 'hr');
    }
}
