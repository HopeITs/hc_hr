<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\Currency;
use App\Livewire\Contract\ContractAmend;
use App\Livewire\Contract\ContractAmendments;
use App\Livewire\Contract\ContractEdit;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\User;
use App\Services\ContractAmendmentService;
use App\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Phase 3 of contract amendments: the amendment page, the amendments card on
 * a contract, who may use them, and the edit screen's lock on schedule and pay.
 */
class ContractAmendmentScreensTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const PERMISSIONS = ['contracts.view', 'contracts.update', 'contracts.export', 'contracts.amend'];

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->city = $this->makeCity('ALP', '001');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── who may amend ───────────────────────────────────────────────

    public function test_city_hr_may_amend_its_own_city_only(): void
    {
        $hr = $this->hr();
        $other = $this->makeCity('HMS', '002');

        $this->assertTrue($hr->can('amend', $this->contract()));
        $this->assertFalse($hr->can('amend', $this->contract($other)));
    }

    public function test_hr_syr_may_amend_every_city(): void
    {
        $syr = $this->makeUser($this->makeCity('SYR', '000'), 'HR-SYR', self::PERMISSIONS);
        $other = $this->makeCity('HMS', '002');

        $this->assertTrue($syr->can('amend', $this->contract($other)));
    }

    public function test_only_an_hr_role_with_the_permission_may_amend(): void
    {
        $contract = $this->contract();

        $this->assertFalse($this->makeUser($this->city, 'HR-ALP', ['contracts.view', 'contracts.update'])->can('amend', $contract));
        $this->assertFalse($this->makeUser($this->city, 'manager', self::PERMISSIONS)->can('amend', $contract));
    }

    public function test_no_amendment_while_one_is_waiting_or_once_the_contract_ended(): void
    {
        $contract = $this->contract();
        app(ContractAmendmentService::class)->draft($contract, ['weekly_hours' => 30], '2026-11-01');

        $this->assertFalse($this->hr()->can('amend', $contract));
        $this->assertFalse($this->hr()->can('amend', $this->contract(overrides: ['terminated_at' => '2026-10-01'])));
    }

    // ── the amendment page ──────────────────────────────────────────

    public function test_the_page_opens_for_hr_and_is_refused_elsewhere(): void
    {
        $contract = $this->contract();

        $this->actingAs($this->hr())->get(route('contracts.amend', $contract))
            ->assertOk()
            ->assertSee(__('contracts.amendment.save-draft'));

        $hms = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', self::PERMISSIONS);
        $this->actingAs($hms)->get(route('contracts.amend', $contract))->assertForbidden();
    }

    public function test_submitting_drafts_the_amendment_and_leaves_the_contract_alone(): void
    {
        $contract = $this->contract();

        Livewire::actingAs($this->hr())
            ->test(ContractAmend::class, ['contract' => $contract])
            ->call('submit', $this->payload())
            ->assertHasNoErrors()
            ->assertRedirect(route('contracts.show', $contract));

        $amendment = ContractAmendment::query()->sole();
        $this->assertTrue($amendment->isDraft());
        $this->assertSame('2026-05-01', $amendment->effective_from->toDateString());
        $this->assertSame(['type', 'weekly_hours', 'work_hours_to', 'salary'], $amendment->changedFields());
        $this->assertSame('250.00', $amendment->salary_after);

        $this->assertSame(20, $contract->refresh()->weekly_hours);
    }

    public function test_the_page_cannot_move_the_assignment_or_the_term(): void
    {
        $contract = $this->contract();

        Livewire::actingAs($this->hr())
            ->test(ContractAmend::class, ['contract' => $contract])
            ->call('submit', $this->payload([
                'positionId' => 999,
                'startDate' => '2026-03-01',
                'endDate' => '2027-02-28',
                'contractTypeId' => 5,
                'place' => 2,
            ]))
            ->assertHasNoErrors();

        $amendment = ContractAmendment::query()->sole();
        foreach (['position_id', 'start_date', 'end_date', 'contract_type_id', 'place'] as $field) {
            $this->assertArrayNotHasKey($field, $amendment->terms_after);
        }
    }

    public function test_an_effective_date_is_required(): void
    {
        Livewire::actingAs($this->hr())
            ->test(ContractAmend::class, ['contract' => $this->contract()])
            ->call('submit', $this->payload(['effectiveFrom' => '']))
            ->assertHasErrors('effective_from')
            ->assertDispatched('composer-invalid', sections: ['amendment']);

        $this->assertSame(0, ContractAmendment::query()->count());
    }

    public function test_a_change_that_changes_nothing_is_refused(): void
    {
        Livewire::actingAs($this->hr())
            ->test(ContractAmend::class, ['contract' => $this->contract()])
            ->call('submit', $this->payload([
                'type' => ContractTypeEnum::PartTime,
                'workHoursTo' => '13:00',
                'weeklyHours' => 20,
                'salary' => 150,
            ]))
            ->assertHasErrors('terms');
    }

    public function test_the_schedule_rules_of_the_composer_still_apply(): void
    {
        Livewire::actingAs($this->hr())
            ->test(ContractAmend::class, ['contract' => $this->contract()])
            ->call('submit', $this->payload(['weeklyHours' => 0]))
            ->assertHasErrors('form.weekly_hours');
    }

    // ── the amendments card ─────────────────────────────────────────

    public function test_approving_a_retroactive_amendment_applies_it(): void
    {
        $contract = $this->contract();
        $amendment = app(ContractAmendmentService::class)->draft($contract, ['weekly_hours' => 30, 'salary' => 250], '2026-05-01');

        Livewire::actingAs($this->hr())
            ->test(ContractAmendments::class, ['contract' => $contract])
            ->assertSee(__('contracts.amendment.number', ['number' => 1]))
            ->call('approve', $amendment->id)
            ->assertRedirect(route('contracts.show', $contract));

        $this->assertTrue($amendment->refresh()->isApplied());
        $this->assertSame(30, $contract->refresh()->weekly_hours);
    }

    public function test_discarding_a_draft_deletes_it(): void
    {
        $contract = $this->contract();
        $amendment = app(ContractAmendmentService::class)->draft($contract, ['weekly_hours' => 30], '2026-11-01');

        Livewire::actingAs($this->hr())
            ->test(ContractAmendments::class, ['contract' => $contract])
            ->call('discard', $amendment->id);

        $this->assertSame(0, ContractAmendment::query()->count());
    }

    public function test_hr_of_another_city_cannot_approve(): void
    {
        $other = $this->makeCity('HMS', '002');
        $contract = $this->contract($other);
        $amendment = app(ContractAmendmentService::class)->draft($contract, ['weekly_hours' => 30], '2026-11-01');
        $syr = $this->makeUser($this->makeCity('SYR', '000'), 'HR-SYR', self::PERMISSIONS);

        Livewire::actingAs($syr)
            ->test(ContractAmendments::class, ['contract' => $contract])
            ->call('approve', $amendment->id);
        $this->assertFalse($amendment->refresh()->isDraft());

        // A contract of HMS is not even visible to HR-ALP.
        Livewire::actingAs($this->hr())
            ->test(ContractAmendments::class, ['contract' => $this->contract($other)])
            ->assertForbidden();
    }

    public function test_the_card_hides_figures_from_a_role_barred_from_pay(): void
    {
        config(['salary.blind_roles' => ['super-admin', 'HR-ALP']]);
        $contract = $this->contract();
        app(ContractAmendmentService::class)->draft($contract, ['weekly_hours' => 30, 'salary' => 98765], '2026-11-01');

        Livewire::actingAs($this->hr())
            ->test(ContractAmendments::class, ['contract' => $contract])
            ->assertSee(__('general.weekly-hours'))
            ->assertDontSee('98,765');
    }

    public function test_the_signed_copy_is_attached_and_served_to_hr_only(): void
    {
        Storage::fake('local');
        $contract = $this->contract();
        $amendment = app(ContractAmendmentService::class)->draft($contract, ['weekly_hours' => 30], '2026-11-01');

        Livewire::actingAs($this->hr())
            ->test(ContractAmendments::class, ['contract' => $contract])
            ->set("signedFiles.{$amendment->id}", UploadedFile::fake()->createWithContent('signed.pdf', '%PDF-1.4
1 0 obj<<>>endobj
trailer<<>>
%%EOF'))
            ->assertHasNoErrors();

        $this->assertNotNull($amendment->refresh()->getFirstMedia('signed'));

        $this->actingAs($this->hr())
            ->get(route('contracts.amendments.signed', [$contract, $amendment]))
            ->assertOk();

        $hms = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', self::PERMISSIONS);
        $this->actingAs($hms)
            ->get(route('contracts.amendments.signed', [$contract, $amendment]))
            ->assertNotFound();
    }

    public function test_a_signed_copy_must_be_a_document(): void
    {
        $contract = $this->contract();
        $amendment = app(ContractAmendmentService::class)->draft($contract, ['weekly_hours' => 30], '2026-11-01');

        Livewire::actingAs($this->hr())
            ->test(ContractAmendments::class, ['contract' => $contract])
            ->set("signedFiles.{$amendment->id}", UploadedFile::fake()->create('script.exe', 10))
            ->assertHasErrors("signedFiles.{$amendment->id}");
    }

    public function test_the_contract_page_shows_the_card(): void
    {
        $contract = $this->contract();

        $this->actingAs($this->hr())->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSee(__('contracts.amendment.add'));
    }

    // ── the edit screen once amended ────────────────────────────────

    public function test_edit_keeps_amended_schedule_and_pay_but_saves_the_rest(): void
    {
        $contract = $this->contract();
        $service = app(ContractAmendmentService::class);
        $service->approve($service->draft($contract, ['weekly_hours' => 30, 'salary' => 250], '2026-05-01'));
        $this->ignoreLeaveBalances();

        $component = Livewire::actingAs($this->hr())
            ->test(ContractEdit::class, ['contract' => $contract->refresh()])
            ->assertSee(__('contracts.amendment.edit-locked'));

        $component->call('submit', array_merge($this->editPayload($contract), [
            'weeklyHours' => 40,
            'salary' => 999,
            'reasonOfTermination' => 'Kept',
            'terminatedAt' => '2026-12-15',
        ]))->assertHasNoErrors();

        $contract->refresh();
        $this->assertSame(30, $contract->weekly_hours);
        $this->assertSame('250.00', $contract->salary);
        $this->assertSame('2026-12-15', $contract->terminated_at->toDateString());
    }

    public function test_edit_is_unchanged_for_a_contract_never_amended(): void
    {
        $contract = $this->contract();
        $this->ignoreLeaveBalances();

        Livewire::actingAs($this->hr())
            ->test(ContractEdit::class, ['contract' => $contract])
            ->assertDontSee(__('contracts.amendment.edit-locked'))
            ->call('submit', array_merge($this->editPayload($contract), ['weeklyHours' => 25, 'workDayTo' => 4]))
            ->assertHasNoErrors();

        $this->assertSame(25, $contract->refresh()->weekly_hours);
    }

    // ── helpers ─────────────────────────────────────────────────────

    /** The edit screen reconciles leave balances on save; not what these tests are about. */
    private function ignoreLeaveBalances(): void
    {
        $this->mock(LeaveBalanceService::class)->shouldIgnoreMissing();
    }

    private function hr(): User
    {
        return $this->makeUser($this->city, 'HR-ALP', self::PERMISSIONS);
    }

    private function contract(?City $city = null, array $overrides = []): Contract
    {
        $employee = $this->makeEmployee($city ?? $this->city, 'amend-'.uniqid());

        return $this->makeContractFor($employee, array_merge([
            'type' => ContractTypeEnum::PartTime,
            'schedule_mode' => 1,
            'work_day_from' => 0,
            'work_day_to' => 4,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '13:00:00',
            'weekly_hours' => 20,
            'salary' => 150,
            'currency' => Currency::USD,
            'salary_per' => 1,
            'pay_mode' => 1,
        ], $overrides))->refresh();
    }

    /** What the composer posts in amend mode: the sheet's 6 hours a day and new stipend. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => ContractTypeEnum::FullTime,
            'scheduleMode' => 1,
            'workHoursFrom' => '09:00',
            'workHoursTo' => '15:00',
            'workDayFrom' => 0,
            'workDayTo' => 4,
            'weeklyHours' => 30,
            'payMode' => 1,
            'salary' => 250,
            'currency' => Currency::USD,
            'salaryPer' => 1,
            'payTiers' => [],
            'effectiveFrom' => '2026-05-01',
            'reason' => 'Moved to full days',
        ], $overrides);
    }

    /** What the edit screen posts for $contract unchanged. */
    private function editPayload(Contract $contract): array
    {
        return array_merge($this->payload(['type' => ContractTypeEnum::PartTime, 'workHoursTo' => '13:00', 'weeklyHours' => 20, 'salary' => 150]), [
            'cityId' => $this->city->id,
            'employeeId' => $contract->employee_id,
            'programId' => $contract->position->program_id,
            'centerId' => $contract->position->center_id,
            'positionId' => $contract->position_id,
            'contractTypeId' => $contract->contract_type_id,
            'place' => 1,
            'startDate' => $contract->start_date->toDateString(),
            'endDate' => $contract->end_date->toDateString(),
        ]);
    }
}
