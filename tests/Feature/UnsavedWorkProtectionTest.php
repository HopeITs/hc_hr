<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Livewire\Contract\ContractCreate;
use App\Livewire\Employee\EmployeeCreate;
use App\Livewire\Employee\EmployeeEdit;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * B2: users can fix errors and leave forms deliberately without losing
 * unexplained work. The navigation and unload prompts themselves run in the
 * browser (resources/js/unsaved.js) and are covered by the browser checks in
 * docs/ux/b2-implementation.md; these tests cover the server side of each AC.
 */
class UnsavedWorkProtectionTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ── AC-B2-01: each summary error reaches its field ────────────────

    public function test_an_error_on_another_tab_links_to_that_tab(): void
    {
        [$hr, $employee] = $this->hrAndEmployee();

        // Saved from the Profile tab; username lives on Contact.
        Livewire::actingAs($hr)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->set('infoForm.username', '')
            ->call('saveInformation')
            ->assertHasErrors('infoForm.username')
            ->assertSet('activeTab', 'information')
            ->assertSeeHtml("go('infoForm.username', 'contact')");
    }

    public function test_a_profile_error_links_to_the_profile_tab(): void
    {
        [$hr, $employee] = $this->hrAndEmployee();

        Livewire::actingAs($hr)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->set('activeTab', 'contact')
            ->set('infoForm.first_name_en', '')
            ->call('saveInformation')
            ->assertHasErrors('infoForm.first_name_en')
            ->assertSeeHtml("go('infoForm.first_name_en', 'information')");
    }

    public function test_every_contact_field_counts_as_contact_when_create_picks_a_tab(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-ALP', ['employees.create']);

        // Before B2 only four contact fields were known, so an error on any
        // other one (here a malformed personal phone) sent the user to Profile.
        Livewire::actingAs($hr)
            ->test(EmployeeCreate::class)
            ->set('activeTab', 'contact')
            ->set('infoForm.first_name_en', 'Test')->set('infoForm.first_name_ar', 'اختبار')
            ->set('infoForm.last_name_en', 'Person')->set('infoForm.last_name_ar', 'شخص')
            ->set('infoForm.father_name_en', 'Father')->set('infoForm.father_name_ar', 'أب')
            ->set('infoForm.mother_name_en', 'Mother')->set('infoForm.mother_name_ar', 'أم')
            ->set('infoForm.gender', 1)
            ->set('infoForm.marital_status', 1)
            ->set('infoForm.nationality', 1)
            ->set('infoForm.national_number', '12345678905')
            ->set('infoForm.city_id', $city->id)
            ->set('infoForm.username', 'b2.contact.tab')
            ->set('infoForm.create_account', false)
            ->set('infoForm.personal_phone', '12')
            ->call('saveInformation')
            ->assertHasErrors('infoForm.personal_phone')
            ->assertSet('activeTab', 'contact');
    }

    public function test_a_composer_error_links_to_its_section(): void
    {
        $fixtures = $this->contractFixtures();
        $payload = $this->contractPayload($fixtures);
        unset($payload['salary']);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $payload)
            ->assertHasErrors('form.salary')
            ->assertSeeHtml("go('form.salary', 'pay')");

        $this->assertSame(0, Contract::query()->count());
    }

    // ── AC-B2-02: the guard resets only after a confirmed save ───────

    public function test_a_successful_create_resets_the_unsaved_guard(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-ALP', ['employees.create']);

        Livewire::actingAs($hr)
            ->test(EmployeeCreate::class)
            ->set('infoForm.first_name_en', 'Test')->set('infoForm.first_name_ar', 'اختبار')
            ->set('infoForm.last_name_en', 'Person')->set('infoForm.last_name_ar', 'شخص')
            ->set('infoForm.father_name_en', 'Father')->set('infoForm.father_name_ar', 'أب')
            ->set('infoForm.mother_name_en', 'Mother')->set('infoForm.mother_name_ar', 'أم')
            ->set('infoForm.gender', 1)
            ->set('infoForm.marital_status', 1)
            ->set('infoForm.nationality', 1)
            ->set('infoForm.national_number', '12345678907')
            ->set('infoForm.city_id', $city->id)
            ->set('infoForm.username', 'b2.saved')
            ->set('infoForm.create_account', false)
            ->call('saveInformation')
            ->assertHasNoErrors()
            ->assertDispatched('form-saved', form: 'infoForm');
    }

    public function test_a_failed_save_keeps_the_entries_and_does_not_reset_the_guard(): void
    {
        [$hr, $employee] = $this->hrAndEmployee();

        Livewire::actingAs($hr)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->set('infoForm.first_name_en', 'Changed but unsaved')
            ->set('infoForm.username', '')
            ->call('saveInformation')
            ->assertHasErrors('infoForm.username')
            ->assertNotDispatched('form-saved')
            ->assertSet('infoForm.first_name_en', 'Changed but unsaved');

        $this->assertNotSame('Changed but unsaved', $employee->fresh()->first_name_en);
    }

    public function test_every_editable_page_form_is_watched_by_the_guard(): void
    {
        [$hr, $employee] = $this->hrAndEmployee();

        // The registry in resources/js/unsaved.js learns of a form only
        // through <x-forms.dirty-gate>, in create mode too.
        $registers = '$unsaved.watchForm($wire.$id, \'infoForm\')';

        Livewire::actingAs($hr)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->assertSeeHtml($registers);

        $city = $this->makeCity('HMS', '002');
        Livewire::actingAs($this->makeUser($city, 'HR-HMS', ['employees.create']))
            ->test(EmployeeCreate::class)
            ->assertSeeHtml($registers);
    }

    // ── AC-B2-03: failures keep input, explain files; retries do not duplicate ──

    public function test_a_chosen_file_explains_that_it_is_not_saved_yet(): void
    {
        Storage::fake('local');
        Storage::fake('tmp-for-tests');
        [$hr, $employee] = $this->hrAndEmployee();

        Livewire::actingAs($hr)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->set('activeTab', 'attachments')
            ->assertDontSee(__('employees.file-input.staged'))
            ->call('addAttachment')
            ->set('attachmentForm.attachments.0.file', UploadedFile::fake()->create('letter.pdf', 20, 'application/pdf'))
            ->assertSee(__('employees.file-input.staged'));
    }

    public function test_a_resent_contract_submit_creates_one_contract(): void
    {
        $fixtures = $this->contractFixtures();
        $this->ignoreLeaveBalances();

        $composer = Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class);

        $composer->call('submit', $this->contractPayload($fixtures))->assertHasNoErrors();
        $contract = Contract::query()->sole();
        $composer->assertRedirect(route('contracts.show', $contract->id));

        // The same composer sends again: a double click, or a retry after the
        // first response was lost. It lands on the contract already created.
        $composer->call('submit', $this->contractPayload($fixtures))
            ->assertHasNoErrors()
            ->assertRedirect(route('contracts.show', $contract->id));

        $this->assertSame(1, Contract::query()->count());
        $this->assertSame(1, $fixtures['position']->fresh()->exist_emp);
    }

    /** X1-I: a lookup by the composer's token, never a second write. */
    public function test_x1i_reconciling_a_lost_create_goes_to_the_contract_it_made(): void
    {
        $fixtures = $this->contractFixtures();
        $this->ignoreLeaveBalances();
        $composer = Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class);

        $composer->call('submit', $this->contractPayload($fixtures))->assertHasNoErrors();
        $contract = Contract::query()->sole();

        $composer->call('reconcileOperation')
            ->assertRedirect(route('contracts.show', $contract->id));

        $this->assertSame(1, Contract::query()->count());
        $this->assertSame(1, $fixtures['position']->fresh()->exist_emp);
    }

    public function test_x1i_reconciling_a_create_that_never_arrived_writes_nothing_and_keeps_the_composer(): void
    {
        $fixtures = $this->contractFixtures();

        Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class)
            ->call('reconcileOperation')
            ->assertNoRedirect()
            ->assertDispatched('toast', type: 'info', message: __('general.unknown-result.not-applied'));

        $this->assertSame(0, Contract::query()->count());
    }

    public function test_x1i_reconciling_after_the_role_lost_contract_creation_is_forbidden(): void
    {
        $fixtures = $this->contractFixtures();
        $composer = Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class);

        $fixtures['hr']->getRoleNames()->each(fn ($role) => $fixtures['hr']->removeRole($role));
        $fixtures['hr']->revokePermissionTo($fixtures['hr']->getAllPermissions());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $composer->call('reconcileOperation')->assertForbidden();
    }

    public function test_a_second_composer_is_a_new_contract(): void
    {
        $fixtures = $this->contractFixtures();
        $this->ignoreLeaveBalances();
        $other = $this->makeEmployee($fixtures['city'], 'b2-second-hire');

        Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class)
            ->call('submit', $this->contractPayload($fixtures))->assertHasNoErrors();
        Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class)
            ->call('submit', ['employeeId' => $other->id] + $this->contractPayload($fixtures))->assertHasNoErrors();

        $this->assertSame(2, Contract::query()->count());
    }

    public function test_a_send_while_the_first_is_still_saving_writes_nothing(): void
    {
        $fixtures = $this->contractFixtures();
        $this->ignoreLeaveBalances();
        $composer = Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class);

        $key = 'contract-create-submission:'.$fixtures['hr']->id.':'.sha1($composer->get('submissionToken'));
        $held = Cache::lock($key.':lock', 30);
        $this->assertTrue($held->get());

        $composer->call('submit', $this->contractPayload($fixtures))
            ->assertDispatched('toast', type: 'info', message: __('general.composer.submit-in-progress'));

        $held->release();
        $this->assertSame(0, Contract::query()->count());
    }

    public function test_the_browser_cannot_swap_the_submission_token(): void
    {
        $fixtures = $this->contractFixtures();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class)->set('submissionToken', 'forged');
    }

    public function test_a_token_never_leads_to_a_contract_the_user_may_not_see(): void
    {
        $fixtures = $this->contractFixtures();
        $this->ignoreLeaveBalances();
        $composer = Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class);

        // A consumed token pointing at a contract in another city.
        $otherCity = $this->makeCity('HMS', '002');
        $elsewhere = Contract::query()->forceCreate([
            'employee_id' => $this->makeEmployee($otherCity, 'b2-elsewhere')->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::Draft,
            'is_active' => true,
        ]);
        Cache::put('contract-create-submission:'.$fixtures['hr']->id.':'.sha1($composer->get('submissionToken')), $elsewhere->id);

        $composer->call('submit', $this->contractPayload($fixtures))->assertHasNoErrors();

        $created = Contract::query()->whereKeyNot($elsewhere->id)->sole();
        $composer->assertRedirect(route('contracts.show', $created->id));
    }

    // ── fixtures ─────────────────────────────────────────────────────

    /** @return array{0: \App\Models\User, 1: \App\Models\Employee} */
    private function hrAndEmployee(): array
    {
        $city = $this->makeCity('ALP', '001');

        return [
            $this->makeUser($city, 'HR-ALP', ['employees.update']),
            $this->makeEmployee($city, 'b2-subject-'.uniqid()),
        ];
    }

    private function ignoreLeaveBalances(): void
    {
        $this->mock(LeaveBalanceService::class)->shouldReceive('create')->andReturnNull();
    }

    private function contractPayload(array $fixtures): array
    {
        return [
            'cityId' => $fixtures['city']->id,
            'employeeId' => $fixtures['employee']->id,
            'programId' => $fixtures['program']->id,
            'centerId' => $fixtures['center']->id,
            'positionId' => $fixtures['position']->id,
            'draftContractId' => null,
            'contractTypeId' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'startDate' => now()->addMonth()->startOfMonth()->toDateString(),
            'endDate' => now()->endOfYear()->toDateString(),
            'workHoursFrom' => '09:00',
            'workHoursTo' => '17:00',
            'weeklyHours' => 40,
            'currency' => 1,
            'salary' => 100,
            'salaryPer' => 1,
        ];
    }

    /** Same shape as ContractComposerDraftUpgradeTest's fixtures. */
    private function contractFixtures(): array
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

        $employee = $this->makeEmployee($city, 'b2-contract-employee');
        $annualType = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.create', 'contracts.view']);

        return compact('city', 'center', 'position', 'program', 'employee', 'annualType', 'hr');
    }
}
