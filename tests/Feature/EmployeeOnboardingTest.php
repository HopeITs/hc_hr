<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Livewire\Contract\ContractCreate;
use App\Livewire\Employee\EmployeeCreate;
use App\Livewire\Employee\EmployeeEdit;
use App\Livewire\Employee\EmployeeTable;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeActivation;
use App\Services\LeaveBalanceService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * B5: resumable onboarding (DEC-04, docs/ux/employee-lifecycle.md).
 * One section per acceptance criterion.
 */
class EmployeeOnboardingTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
        Role::findOrCreate('employee', 'web');
    }

    // ── AC-B5-01: the approved minimum can be saved and resumed ──────

    public function test_the_draft_minimum_saves_an_inactive_incomplete_record_with_nothing_issued(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->hr($city);

        $this->draftMinimum(Livewire::actingAs($hr)->test(EmployeeCreate::class), $city)
            ->call('saveDraftRecord')
            ->assertHasNoErrors()
            ->assertSet('showDraftPrompt', true)
            ->assertDispatched('form-saved', form: 'infoForm');

        $draft = Employee::query()->where('username', 'b5.draft')->sole();
        $this->assertTrue($draft->isDraft());
        $this->assertFalse($draft->is_active);
        $this->assertNull($draft->gender);
        $this->assertNull($draft->digital_id_token);
        $this->assertSame($hr->id, $draft->last_saved_by);
        $this->assertNull($draft->account);
        Notification::assertNothingSent();
    }

    public function test_a_draft_needs_names_governorate_national_id_and_username(): void
    {
        $city = $this->makeCity('ALP', '001');

        Livewire::actingAs($this->hr($city))->test(EmployeeCreate::class)
            ->set('infoForm.first_name_en', 'Only')
            ->call('saveDraftRecord')
            ->assertHasErrors(['infoForm.first_name_ar', 'infoForm.mother_name_en', 'infoForm.city_id', 'infoForm.national_number', 'infoForm.username'])
            ->assertHasNoErrors(['infoForm.gender', 'infoForm.marital_status']);

        $this->assertSame(0, Employee::query()->drafts()->count());
    }

    public function test_another_hr_officer_in_the_city_resumes_and_completes_the_draft(): void
    {
        $city = $this->makeCity('ALP', '001');
        $draft = $this->makeDraft($city);
        $colleague = $this->hr($city, 'employees.update');

        Livewire::actingAs($colleague)->test(EmployeeEdit::class, ['employee' => $draft])
            ->assertSee(__('employees.onboarding.state-draft'))
            ->set('infoForm.gender', 1)
            ->set('infoForm.marital_status', 1)
            ->set('infoForm.nationality', 1)
            ->call('saveInformation')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success', message: __('employees.onboarding.completed'));

        $draft->refresh();
        $this->assertFalse($draft->isDraft());
        $this->assertFalse($draft->is_active, 'Complete is "Ready, awaiting contract", not active (B5D-Q4).');
        $this->assertTrue($draft->isAwaitingContract());
        $this->assertNull($draft->account);
        $this->assertSame($colleague->id, $draft->last_saved_by);
    }

    public function test_completing_a_draft_still_requires_gender_and_marital_status(): void
    {
        $city = $this->makeCity('ALP', '001');
        $draft = $this->makeDraft($city);

        Livewire::actingAs($this->hr($city, 'employees.update'))->test(EmployeeEdit::class, ['employee' => $draft])
            ->call('saveInformation')
            ->assertHasErrors(['infoForm.gender', 'infoForm.marital_status']);

        $this->assertTrue($draft->fresh()->isDraft());
    }

    public function test_a_draft_can_be_saved_again_as_a_draft(): void
    {
        $city = $this->makeCity('ALP', '001');
        $draft = $this->makeDraft($city);

        Livewire::actingAs($this->hr($city, 'employees.update'))->test(EmployeeEdit::class, ['employee' => $draft])
            ->set('infoForm.address_en', 'Second visit')
            ->call('saveDraftRecord')
            ->assertHasNoErrors();

        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertSame('Second visit', $draft->fresh()->address_en);
    }

    public function test_hr_of_another_city_cannot_open_the_draft(): void
    {
        $draft = $this->makeDraft($this->makeCity('ALP', '001'));
        $elsewhere = $this->makeCity('HMS', '002');

        Livewire::actingAs($this->makeUser($elsewhere, 'HR-HMS', ['employees.update']))
            ->test(EmployeeEdit::class, ['employee' => $draft])
            ->assertForbidden();
    }

    public function test_a_complete_record_never_goes_back_to_draft(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'b5.complete');

        Livewire::actingAs($this->hr($city, 'employees.update'))->test(EmployeeEdit::class, ['employee' => $employee])
            ->call('saveDraftRecord')
            ->assertForbidden();

        $this->assertFalse($employee->fresh()->isDraft());
    }

    public function test_a_save_resent_after_a_lost_response_continues_on_the_same_record(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->hr($city);
        $first = Livewire::actingAs($hr)->test(EmployeeCreate::class);
        $this->draftMinimum($first, $city)->call('saveDraftRecord');
        $created = Employee::query()->where('username', 'b5.draft')->sole();

        // A second component standing in for the same form whose response was
        // lost: same token, no record in its state yet.
        $retry = Livewire::actingAs($hr)->test(EmployeeCreate::class);
        Cache::put('employee-create-submission:'.$hr->id.':'.sha1($retry->get('submissionToken')), $created->id);
        $this->draftMinimum($retry, $city)->set('infoForm.address_en', 'resent')->call('saveDraftRecord')->assertHasNoErrors();

        $this->assertSame(1, Employee::query()->where('username', 'b5.draft')->count());
        $this->assertSame('resent', $created->fresh()->address_en);
    }

    public function test_a_duplicate_national_id_names_the_existing_record_and_its_city(): void
    {
        $city = $this->makeCity('ALP', '001');
        $elsewhere = $this->makeCity('HMS', '002');
        $this->makeEmployee($elsewhere, 'b5.existing', ['national_number' => '12345678901', 'first_name_en' => 'Rana', 'last_name_en' => 'Saleh']);

        $component = $this->draftMinimum(Livewire::actingAs($this->hr($city))->test(EmployeeCreate::class), $city)
            ->set('infoForm.national_number', '12345678901')
            ->call('saveDraftRecord')
            ->assertHasErrors('infoForm.national_number');

        $this->assertSame(
            __('employees.validation.national-number-taken', ['name' => 'Rana Saleh', 'city' => 'HMS']),
            $component->errors()->first('infoForm.national_number'),
        );
    }

    public function test_the_weekly_job_leaves_drafts_and_awaiting_volunteers_alone(): void
    {
        $city = $this->makeCity('ALP', '001');
        $draft = $this->makeDraft($city);
        $awaiting = $this->makeEmployee($city, 'b5.awaiting', ['is_active' => false]);
        $activeWithoutContract = $this->makeEmployee($city, 'b5.legacy');

        $this->artisan('deactivate:employee')->assertSuccessful();

        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertFalse($draft->fresh()->is_active);
        $this->assertTrue($awaiting->fresh()->isAwaitingContract());
        $this->assertFalse($activeWithoutContract->fresh()->is_active, 'Existing behaviour for active staff is unchanged.');
    }

    // ── AC-B5-02: a draft activates nothing ───────────────────────────

    public function test_a_draft_is_never_offered_in_the_contract_composer_and_cannot_be_given_a_contract(): void
    {
        $fixtures = $this->contractFixtures();
        $draft = $this->makeDraft($fixtures['city']);

        Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class)
            ->call('locationOptions', $fixtures['city']->id)
            ->assertReturned(fn ($options) => ! collect($options['employees'])->contains('value', $draft->id));

        Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class)
            ->call('submit', ['employeeId' => $draft->id] + $this->contractPayload($fixtures))
            ->assertHasErrors('form.employee_id');

        $this->assertSame(0, Contract::query()->count());
        $this->assertFalse($draft->fresh()->is_active);
    }

    public function test_an_awaiting_volunteer_is_offered_in_the_composer(): void
    {
        $fixtures = $this->contractFixtures();
        $awaiting = $this->makeEmployee($fixtures['city'], 'b5.awaiting', ['is_active' => false]);

        Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class)
            ->call('locationOptions', $fixtures['city']->id)
            ->assertReturned(fn ($options) => collect($options['employees'])->contains('value', $awaiting->id));
    }

    public function test_the_status_toggle_cannot_activate_a_draft(): void
    {
        $city = $this->makeCity('ALP', '001');
        $draft = $this->makeDraft($city);

        Livewire::actingAs($this->hr($city, 'employees.update'))->test(EmployeeTable::class)
            ->call('toggleItemStatusConfirmed', ['id' => $draft->id])
            ->assertForbidden();

        $this->assertFalse($draft->fresh()->is_active);
    }

    public function test_the_status_toggle_cannot_activate_an_awaiting_volunteer_either(): void
    {
        $city = $this->makeCity('ALP', '001');
        $awaiting = $this->makeEmployee($city, 'b5.waiting', ['is_active' => false]);

        Livewire::actingAs($this->hr($city, 'employees.update'))->test(EmployeeTable::class)
            ->call('toggleItemStatusConfirmed', ['id' => $awaiting->id])
            ->assertForbidden();

        $this->assertFalse($awaiting->fresh()->is_active);
    }

    public function test_former_staff_can_still_be_reactivated_by_hand(): void
    {
        $fixtures = $this->contractFixtures();
        $former = $this->makeEmployee($fixtures['city'], 'b5.former', ['is_active' => false]);
        Contract::query()->forceCreate([
            'employee_id' => $former->id, 'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['annualType']->id, 'type' => ContractTypeEnum::FullTime,
            'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'is_active' => false,
        ]);

        Livewire::actingAs($this->hr($fixtures['city'], 'employees.update'))->test(EmployeeTable::class)
            ->call('toggleItemStatusConfirmed', ['id' => $former->id])
            ->assertHasNoErrors();

        $this->assertTrue($former->fresh()->is_active);
    }

    public function test_the_browser_cannot_activate_or_give_an_account_to_a_draft(): void
    {
        $city = $this->makeCity('ALP', '001');

        $this->draftMinimum(Livewire::actingAs($this->hr($city))->test(EmployeeCreate::class), $city)
            ->set('infoForm.is_active', true)
            ->set('infoForm.create_account', true)
            ->call('saveDraftRecord')
            ->assertHasNoErrors();

        $draft = Employee::query()->where('username', 'b5.draft')->sole();
        $this->assertFalse($draft->is_active);
        $this->assertNull($draft->account);
        $this->assertSame(0, User::query()->where('email', 'b5.draft@hcsyria.org')->count());
    }

    public function test_editing_a_draft_cannot_activate_it_either(): void
    {
        $city = $this->makeCity('ALP', '001');
        $draft = $this->makeDraft($city);

        Livewire::actingAs($this->hr($city, 'employees.update'))->test(EmployeeEdit::class, ['employee' => $draft])
            ->set('infoForm.is_active', true)
            ->set('infoForm.address_en', 'forged')
            ->call('saveDraftRecord')
            ->assertHasNoErrors();

        $this->assertFalse($draft->fresh()->is_active);
        $this->assertTrue($draft->fresh()->isDraft());
    }

    public function test_a_complete_new_record_is_not_active_and_has_no_account(): void
    {
        $city = $this->makeCity('ALP', '001');

        $this->draftMinimum(Livewire::actingAs($this->hr($city))->test(EmployeeCreate::class), $city)
            ->set('infoForm.gender', 1)
            ->set('infoForm.marital_status', 1)
            ->set('infoForm.nationality', 1)
            ->set('infoForm.create_account', true)
            ->call('saveInformation')
            ->assertHasNoErrors();

        $employee = Employee::query()->where('username', 'b5.draft')->sole();
        $this->assertFalse($employee->isDraft());
        $this->assertFalse($employee->is_active);
        $this->assertNull($employee->account);
        Notification::assertNothingSent();
    }

    public function test_a_draft_and_its_reservation_can_be_discarded_after_a_confirmation(): void
    {
        $fixtures = $this->contractFixtures();
        $draft = $this->makeDraft($fixtures['city']);
        $reservation = $this->reserve($draft, $fixtures);

        Livewire::actingAs($this->hr($fixtures['city'], 'employees.update'))->test(EmployeeEdit::class, ['employee' => $draft])
            ->call('confirmDiscardDraftRecord')
            ->assertDispatched('confirm-dialog')
            ->call('discardDraftRecord')
            ->assertRedirect(route('employees.index', ['view' => 'incomplete']));

        $this->assertNull(Employee::query()->find($draft->id));
        $this->assertNull(Contract::query()->find($reservation->id));
    }

    public function test_a_complete_record_cannot_be_discarded(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'b5.keep', ['is_active' => false]);

        Livewire::actingAs($this->hr($city, 'employees.update'))->test(EmployeeEdit::class, ['employee' => $employee])
            ->call('discardDraftRecord')
            ->assertForbidden();

        $this->assertNotNull($employee->fresh());
    }

    public function test_the_first_real_contract_activates_the_volunteer_and_creates_one_account(): void
    {
        $fixtures = $this->contractFixtures();
        $this->ignoreLeaveBalances();
        $awaiting = $this->makeEmployee($fixtures['city'], 'b5.newcomer', ['is_active' => false]);

        Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class)
            ->call('submit', ['employeeId' => $awaiting->id] + $this->contractPayload($fixtures))
            ->assertHasNoErrors();

        // A redirect follows, so the toast is carried to the next page (SupportToasts).
        $this->assertTrue(collect(session('toasts'))->contains(fn ($t) => $t['type'] === 'info' && $t['message'] === __('employees.onboarding.account-created')));

        $awaiting->refresh();
        $this->assertTrue($awaiting->is_active);
        $this->assertNotNull($awaiting->digital_id_token);
        $account = $awaiting->account;
        $this->assertSame('b5.newcomer@hcsyria.org', $account->email);
        $this->assertTrue($account->hasRole('employee'));
        Notification::assertSentTo($account, ResetPassword::class);

        // A second contract (or a retry) creates no second account.
        $this->assertSame(EmployeeActivation::RESULT_NONE, app(EmployeeActivation::class)->activateForContract(Contract::query()->sole()));
        $this->assertSame(1, User::query()->where('employee_id', $awaiting->id)->count());
    }

    public function test_upgrading_a_reservation_activates_the_volunteer_too(): void
    {
        $fixtures = $this->contractFixtures();
        $this->ignoreLeaveBalances();
        $awaiting = $this->makeEmployee($fixtures['city'], 'b5.reserved', ['is_active' => false]);
        $reservation = $this->reserve($awaiting, $fixtures);

        Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class)
            ->call('submit', ['employeeId' => $awaiting->id, 'draftContractId' => $reservation->id] + $this->contractPayload($fixtures))
            ->assertHasNoErrors();

        $this->assertSame(1, Contract::query()->count(), 'Upgraded in place, not a second contract.');
        $this->assertTrue($awaiting->fresh()->is_active);
        $this->assertNotNull($awaiting->fresh()->account);
    }

    public function test_a_reservation_alone_activates_nothing(): void
    {
        $fixtures = $this->contractFixtures();
        $draft = $this->makeDraft($fixtures['city']);
        $reservation = $this->reserve($draft, $fixtures);

        $this->assertSame(EmployeeActivation::RESULT_NONE, app(EmployeeActivation::class)->activateForContract($reservation));
        $this->assertFalse($draft->fresh()->is_active);
        $this->assertNull($draft->fresh()->account);
    }

    public function test_a_work_email_already_in_use_creates_no_account(): void
    {
        $fixtures = $this->contractFixtures();
        $this->ignoreLeaveBalances();
        $awaiting = $this->makeEmployee($fixtures['city'], 'b5.taken', ['is_active' => false]);
        User::factory()->create(['email' => 'b5.taken@hcsyria.org', 'employee_id' => $this->makeEmployee($fixtures['city'], 'b5.other')->id]);

        Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class)
            ->call('submit', ['employeeId' => $awaiting->id] + $this->contractPayload($fixtures))
            ->assertHasNoErrors();

        $this->assertTrue(collect(session('toasts'))->contains(fn ($t) => $t['type'] === 'error' && $t['message'] === __('employees.onboarding.account-email-taken')));

        $this->assertTrue($awaiting->fresh()->is_active);
        $this->assertNull($awaiting->fresh()->account);
    }

    // ── AC-B5-03: existing staff and draft upgrades keep their behaviour ──

    public function test_existing_rows_are_complete_and_editing_them_changes_nothing_else(): void
    {
        $city = $this->makeCity('ALP', '001');
        $active = $this->makeEmployee($city, 'b5.staff', [
            'father_name_en' => 'F', 'father_name_ar' => 'ف', 'mother_name_en' => 'M', 'mother_name_ar' => 'م',
            'national_number' => '12345678903', 'gender' => 1, 'marital_status' => 1, 'nationality' => 1, 'is_board_member' => false,
        ]);

        $this->assertSame(Employee::RECORD_COMPLETE, $active->fresh()->record_status, 'The column default backfills every existing row.');

        Livewire::actingAs($this->hr($city, 'employees.update'))->test(EmployeeEdit::class, ['employee' => $active])
            ->set('infoForm.address_en', 'Edited')
            ->call('saveInformation')
            ->assertHasNoErrors();

        $active->refresh();
        $this->assertTrue($active->is_active);
        $this->assertFalse($active->isDraft());
        $this->assertNull($active->account, 'No account is created for existing staff by an edit (B5D-Q8).');
        Notification::assertNothingSent();
    }

    public function test_hr_can_still_create_an_account_for_an_active_employee_deliberately(): void
    {
        $city = $this->makeCity('ALP', '001');
        $active = $this->makeEmployee($city, 'b5.deliberate', [
            'father_name_en' => 'F', 'father_name_ar' => 'ف', 'mother_name_en' => 'M', 'mother_name_ar' => 'م',
            'national_number' => '12345678904', 'gender' => 1, 'marital_status' => 1, 'nationality' => 1, 'is_board_member' => false,
        ]);

        Livewire::actingAs($this->hr($city, 'employees.update'))->test(EmployeeEdit::class, ['employee' => $active])
            ->set('infoForm.create_account', true)
            ->call('saveInformation')
            ->assertHasNoErrors();

        $this->assertSame('b5.deliberate@hcsyria.org', $active->fresh()->account?->email);
    }

    public function test_the_views_keep_drafts_apart_from_active_and_inactive_staff(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->hr($city, 'employees.view');
        $draft = $this->makeDraft($city);
        $former = $this->makeEmployee($city, 'b5.former', ['is_active' => false]);
        $active = $this->makeEmployee($city, 'b5.active');

        $ids = function (string $view) use ($hr): array {
            $table = Livewire::actingAs($hr)->test(EmployeeTable::class)->call('setView', $view)->instance();

            // query() is protected: it is exactly what the table lists.
            return (fn () => $this->query())->call($table)->pluck('employees.id')->all();
        };

        $this->assertNotContains($draft->id, $ids('active'));
        $this->assertContains($active->id, $ids('active'));
        $this->assertNotContains($draft->id, $ids('inactive'));
        $this->assertContains($former->id, $ids('inactive'), 'Existing inactive staff stay under Inactive.');
        $this->assertSame([$draft->id], $ids('incomplete'));
    }

    public function test_the_general_manager_does_not_see_drafts(): void
    {
        $city = $this->makeCity('ALP', '001');
        $this->makeDraft($city);
        $gm = $this->makeUser($city, 'general-manager', ['employees.view']);

        $component = Livewire::actingAs($gm)->test(EmployeeTable::class)->call('setView', 'incomplete');

        $this->assertSame('active', $component->get('preset'));
    }

    // ── fixtures ─────────────────────────────────────────────────────

    private function hr(City $city, string ...$permissions): User
    {
        return $this->makeUser($city, 'HR-'.$city->slug, $permissions ?: ['employees.create', 'employees.update']);
    }

    private function draftMinimum($component, City $city)
    {
        return $component
            ->set('infoForm.first_name_en', 'Lina')->set('infoForm.first_name_ar', 'لينا')
            ->set('infoForm.last_name_en', 'Haddad')->set('infoForm.last_name_ar', 'حداد')
            ->set('infoForm.father_name_en', 'Sami')->set('infoForm.father_name_ar', 'سامي')
            ->set('infoForm.mother_name_en', 'Rima')->set('infoForm.mother_name_ar', 'ريما')
            ->set('infoForm.city_id', $city->id)
            ->set('infoForm.national_number', '12345678909')
            ->set('infoForm.username', 'b5.draft');
    }

    private function makeDraft(City $city): Employee
    {
        $draft = new Employee([
            'city_id' => $city->id,
            'first_name_en' => 'Draft', 'first_name_ar' => 'مسودة',
            'last_name_en' => 'Person', 'last_name_ar' => 'شخص',
            'father_name_en' => 'F', 'father_name_ar' => 'ف',
            'mother_name_en' => 'M', 'mother_name_ar' => 'م',
            'national_number' => (string) random_int(10000000000, 99999999999),
            'username' => 'b5.d.'.uniqid(),
        ]);
        $draft->forceFill(['record_status' => Employee::RECORD_DRAFT, 'is_active' => false, 'is_board_member' => false])->save();

        return $draft;
    }

    private function reserve(Employee $employee, array $fixtures): Contract
    {
        return Contract::query()->forceCreate([
            'employee_id' => $employee->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::Draft,
            'is_active' => true,
        ]);
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
            'name_en' => 'Hope Center', 'name_ar' => 'مركز الرجاء', 'city_id' => $city->id,
            'is_active' => true, 'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);
        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $program = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => 'CORE', 'is_active' => true]);
        $position = Position::query()->forceCreate([
            'center_id' => $center->id, 'city_id' => $city->id, 'department_id' => $department->id,
            'job_level_id' => $jobLevel->id, 'program_id' => $program->id,
            'title_en' => 'Program Officer', 'title_ar' => 'مسؤول برنامج',
            'max_emp' => 5, 'exist_emp' => 0, 'is_active' => true,
        ]);
        $employee = $this->makeEmployee($city, 'b5-contract-employee');
        $annualType = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.create', 'contracts.view', 'employees.update']);

        return compact('city', 'center', 'position', 'program', 'employee', 'annualType', 'hr');
    }
}
