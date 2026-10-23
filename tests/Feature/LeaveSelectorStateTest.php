<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Livewire\Employee\Leave\EmpLeaveBalanceModule;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A3-R: the leave type selector keeps its value across edit, reopen and
 * clear, and the server decides what the form shows from that value alone.
 */
class LeaveSelectorStateTest extends TestCase
{
    use RefreshDatabase;

    private const RADIO_DAY = 'radio-formtype-day';

    private User $user;

    private LeaveBalance $annual;

    private LeaveBalance $sick;

    private LeaveRequest $pending;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createStaffWithBalances();
    }

    public function test_edit_prefills_the_selected_balance_and_shows_the_annual_mode_choice(): void
    {
        Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('edit', $this->pending->id)
            ->assertHasNoErrors()
            ->assertSet('form.id', $this->pending->id)
            ->assertSet('form.leave_balance_id', $this->annual->id)
            ->assertSet('showModal', true)
            ->assertSeeHtml(self::RADIO_DAY);
    }

    public function test_opening_a_new_request_after_an_edit_leaves_no_stale_edit_state(): void
    {
        Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('edit', $this->pending->id)
            ->assertSet('form.leave_balance_id', $this->annual->id)
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('form.leave_balance_id', null)
            ->assertSet('form.id', null)
            ->assertSet('form.type', 'day')
            ->assertSet('showModal', true)
            ->assertDontSeeHtml(self::RADIO_DAY);
    }

    public function test_a_string_id_from_the_browser_selects_and_clearing_deselects(): void
    {
        $this->assertSelectThenClear('Annual Leave');
    }

    public function test_selecting_and_clearing_behaves_the_same_in_arabic(): void
    {
        app()->setLocale('ar');

        // The Arabic option label proves the render really ran in Arabic.
        $this->assertSelectThenClear('إجازة سنوية');

        $this->assertSame('ar', app()->getLocale());
    }

    public function test_saving_after_the_selector_is_cleared_is_rejected(): void
    {
        $before = LeaveRequest::query()->count();

        Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.leave_balance_id', (string) $this->annual->id)
            ->set('form.type', 'day')
            ->set('form.start_at', self::workingMonday()->toDateString())
            ->set('form.end_at', self::workingMonday()->addDay()->toDateString())
            ->set('form.reason', 'Cleared selector must not save.')
            ->set('form.leave_balance_id', null)
            ->call('processSave')
            ->assertHasErrors(['form.leave_balance_id' => 'required']);

        $this->assertSame($before, LeaveRequest::query()->count());
    }

    /**
     * A5-M: the request dialog closes only through requestClose(), which
     * compares the entries with what the form opened with.
     */
    public function test_a_pristine_new_request_closes_at_once_without_asking(): void
    {
        $before = LeaveRequest::query()->count();

        Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->assertSet('showModal', true)
            ->call('requestClose')
            ->assertSet('showModal', false)
            ->assertNotDispatched('confirm-dialog');

        $this->assertSame($before, LeaveRequest::query()->count());
    }

    public function test_a_dirty_new_request_asks_and_keeps_every_entry_and_the_dialog(): void
    {
        Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.leave_balance_id', (string) $this->annual->id)
            ->set('form.reason', 'Family visit')
            ->call('requestClose')
            ->assertSet('showModal', true)
            ->assertSet('form.reason', 'Family visit')
            ->assertSet('form.leave_balance_id', (string) $this->annual->id)
            ->assertDispatched('confirm-dialog',
                method: 'discardChanges',
                payload: 'showModal',
                title: __('general.unsaved.title'),
                message: __('general.unsaved.message'),
                confirmLabel: __('general.unsaved.discard'),
                cancelLabel: __('general.unsaved.keep-editing'),
                danger: false,
            );
    }

    public function test_a_value_changed_and_restored_is_not_dirty(): void
    {
        Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.reason', 'Temporary')
            ->set('form.reason', '')
            ->call('requestClose')
            ->assertSet('showModal', false)
            ->assertNotDispatched('confirm-dialog');
    }

    public function test_discarding_clears_only_this_form_and_writes_nothing(): void
    {
        $before = LeaveRequest::query()->count();

        Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.reason', 'Family visit')
            ->call('requestClose')
            ->call('discardChanges')
            ->assertSet('showModal', false)
            ->assertSet('form.reason', '')
            ->call('create')
            ->assertSet('form.reason', '')
            ->call('requestClose')
            ->assertSet('showModal', false)
            ->assertNotDispatched('confirm-dialog');

        $this->assertSame($before, LeaveRequest::query()->count());
    }

    public function test_an_edited_request_is_guarded_and_reopens_with_its_saved_values(): void
    {
        $saved = $this->pending->reason;

        Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('edit', $this->pending->id)
            ->call('requestClose')
            ->assertSet('showModal', false)
            ->assertNotDispatched('confirm-dialog')
            ->call('edit', $this->pending->id)
            ->set('form.reason', 'Something else entirely')
            ->call('requestClose')
            ->assertSet('showModal', true)
            ->assertDispatched('confirm-dialog', method: 'discardChanges')
            ->call('discardChanges')
            ->assertSet('showModal', false)
            ->call('edit', $this->pending->id)
            ->assertSet('form.reason', $saved);

        $this->assertSame($saved, $this->pending->fresh()->reason);
    }

    public function test_the_dialog_is_wired_to_the_guard_and_the_backdrop_is_inert(): void
    {
        Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->assertSeeHtml("guard: 'requestClose'")
            ->assertSeeHtml("\$wire.call(this.guard, 'showModal')")
            ->assertSeeHtml('guard || close()')
            ->assertDontSeeHtml('x-on:click="show = false"');
    }

    public function test_new_and_edit_labels_match_their_action_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            Livewire::actingAs($this->user)
                ->test(MyLeaveRequestModule::class)
                ->call('create')
                ->assertSet('modalTitle', __('leaves.modal.new-title'))
                ->assertSee(__('leaves.modal.submit'))
                ->assertDontSee(__('leaves.modal.save-changes'))
                ->call('discardChanges')
                ->call('edit', $this->pending->id)
                ->assertSet('modalTitle', __('leaves.modal.edit-title'))
                ->assertSee(__('leaves.modal.save-changes'));
        }

        $this->assertSame('Request leave', trans('leaves.modal.new-title', [], 'en'));
        $this->assertSame('طلب إجازة', trans('leaves.modal.new-title', [], 'ar'));
        $this->assertSame('تجاهل التغييرات؟', trans('general.unsaved.title', [], 'ar'));
    }

    /** A5-M: the HR balance adjustment uses the same guard, and reopens clean. */
    public function test_the_balance_adjustment_is_guarded_and_reopens_clean(): void
    {
        $deserved = $this->annual->deserved;

        Livewire::actingAs($this->user)
            ->test(EmpLeaveBalanceModule::class)
            ->call('edit', $this->annual->id)
            ->call('requestClose', 'showModal')
            ->assertSet('showModal', false)
            ->assertNotDispatched('confirm-dialog')
            ->call('edit', $this->annual->id)
            ->set('form.amount', '3')
            ->set('form.operation', 'sub')
            ->call('requestClose', 'showModal')
            ->assertSet('showModal', true)
            ->assertSet('form.amount', '3')
            ->assertDispatched('confirm-dialog', method: 'discardChanges', payload: 'showModal')
            ->call('discardChanges', 'showModal')
            ->assertSet('showModal', false)
            ->call('edit', $this->annual->id)
            ->assertSet('form.amount', 0)
            ->assertSet('form.operation', 'sum')
            ->assertDontSeeHtml(route('leaves.balance').'"');

        $this->assertEquals($deserved, $this->annual->fresh()->deserved);
    }

    private function assertSelectThenClear(string $annualLabel): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->assertSee($annualLabel)
            ->set('form.leave_balance_id', (string) $this->annual->id)
            ->assertHasNoErrors()
            ->assertSeeHtml(self::RADIO_DAY);

        // A non-annual balance never offers the day/hour choice.
        $component
            ->set('form.leave_balance_id', (string) $this->sick->id)
            ->assertHasNoErrors()
            ->assertDontSeeHtml(self::RADIO_DAY);

        $component
            ->set('form.leave_balance_id', (string) $this->annual->id)
            ->assertSeeHtml(self::RADIO_DAY)
            ->set('form.leave_balance_id', null)
            ->assertSet('form.leave_balance_id', null)
            ->assertHasNoErrors()
            ->assertDontSeeHtml(self::RADIO_DAY);
    }

    /**
     * Same schema-level fixture approach as LeaveRequestWorkflowTest: the
     * EmployeeFactory still targets removed columns, so rows are inserted
     * against the current migrations. The annual type must carry the id
     * LeaveTypeCases::Annual, because the view keys the mode choice on it.
     */
    private function createStaffWithBalances(): void
    {
        $cityId = DB::table('cities')->insertGetId([
            'name_en' => 'Aleppo',
            'name_ar' => 'حلب',
            'slug' => 'ALP',
            'code' => '001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $programId = DB::table('programs')->insertGetId([
            'name' => 'Program',
            'code' => 'PRG',
            'slug' => 'program',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $centerId = DB::table('centers')->insertGetId([
            'name_en' => 'Center',
            'name_ar' => 'مركز',
            'weekends' => '0,6',
            'city_id' => $cityId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $departmentId = DB::table('departments')->insertGetId([
            'name' => 'Department',
            'code' => 'DEP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $jobLevelId = DB::table('job_levels')->insertGetId([
            'name_en' => 'Officer',
            'name_ar' => 'موظف',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = DB::table('positions')->insertGetId([
            'program_id' => $programId,
            'center_id' => $centerId,
            'city_id' => $cityId,
            'job_level_id' => $jobLevelId,
            'department_id' => $departmentId,
            'title_en' => 'Officer',
            'title_ar' => 'موظف',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = DB::table('employees')->insertGetId([
            'city_id' => $cityId,
            'first_name_en' => 'Test',
            'first_name_ar' => 'اختبار',
            'last_name_en' => 'Employee',
            'last_name_ar' => 'موظف',
            'username' => 'selector-employee',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contractTypeId = DB::table('contract_types')->insertGetId([
            'name_en' => 'Annual',
            'name_ar' => 'سنوي',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contractId = DB::table('contracts')->insertGetId([
            'employee_id' => $employeeId,
            'position_id' => $positionId,
            'contract_type_id' => $contractTypeId,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addYear(),
            'currency' => 2,
            'weekly_hours' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('leave_types')->insert([
            [
                'id' => LeaveTypeCases::Annual,
                'name_en' => 'Annual Leave',
                'name_ar' => 'إجازة سنوية',
                'base_days' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => LeaveTypeCases::Annual + 1,
                'name_en' => 'Sick Leave',
                'name_ar' => 'إجازة مرضية',
                'base_days' => 15,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $annualId = DB::table('leave_balances')->insertGetId([
            'contract_id' => $contractId,
            'type_id' => LeaveTypeCases::Annual,
            'deserved' => 160,
            'used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sickId = DB::table('leave_balances')->insertGetId([
            'contract_id' => $contractId,
            'type_id' => LeaveTypeCases::Annual + 1,
            'deserved' => 120,
            'used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pendingId = DB::table('leave_requests')->insertGetId([
            'leave_balance_id' => $annualId,
            'type' => 'day',
            'start_at' => now()->addWeek()->startOfDay(),
            'end_at' => now()->addWeek()->addDay()->startOfDay(),
            'reason' => 'Pending annual leave.',
            'status' => LeaveRequestStatus::Pending,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::query()->create([
            'employee_id' => $employeeId,
            'email' => 'selector.employee@example.test',
            'password' => 'password',
        ]);
        $this->user->givePermissionTo(['leaves.create', 'leaves.update']);

        $this->annual = LeaveBalance::query()->findOrFail($annualId);
        $this->sick = LeaveBalance::query()->findOrFail($sickId);
        $this->pending = LeaveRequest::query()->findOrFail($pendingId);
    }

    /**
     * A Monday at least a month ahead whose Monday and Tuesday are working
     * days outside December, so the weekend (0,6) and the December rule
     * (A3-D D-7) never depend on the day the suite runs.
     */
    private static function workingMonday(): Carbon
    {
        $monday = now()->addMonth()->next(Carbon::MONDAY)->startOfDay();

        while ($monday->month === 12 || $monday->copy()->addDay()->month === 12) {
            $monday->addWeek();
        }

        return $monday;
    }
}
