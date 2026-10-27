<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Livewire\Employee\Leave\AllLeaveRequestModule;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Models\City;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Leave\LeaveQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A3: the request form, its validation and the final accounting all use
 * LeaveRequestPricing (docs/ux/leave-calculation-contract.md §2.3, §11).
 *
 * AC-A3-01: for the A3-D fixtures the figure the live preview shows, the
 * computed_hours stored with the request and the amount added to `used` when
 * HR accepts it are the same number. AC-A3-02: an insufficient annual
 * balance is a field error that keeps the input; other types only warn.
 * Plus the stale-balance, decision-time, edit-of-accepted and December (D-7)
 * paths.
 *
 * Center A: weekend Fri+Sat (5,6). Center B: Sat+Sun (0,6). Contracts run
 * the whole of 2026, so the annual balance is fully eligible. "Today" is
 * Wednesday 21 October 2026; 1 November 2026 is a Sunday.
 */
class LeaveRequestAccountingTest extends TestCase
{
    use RefreshDatabase;

    private int $nationalCityId;

    private int $cityId;

    private int $programId;

    private int $departmentId;

    private int $jobLevelId;

    /** @var array<string, int> */
    private array $centers = [];

    private User $hr;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
        Notification::fake();

        $this->nationalCityId = $this->insert('cities', ['name_en' => 'Syria', 'name_ar' => 'سوريا', 'slug' => City::NATIONAL_SLUG, 'code' => '000']);
        $this->cityId = $this->insert('cities', ['name_en' => 'Homs', 'name_ar' => 'حمص', 'slug' => 'HMS', 'code' => '002']);
        $this->programId = $this->insert('programs', ['name' => 'Program', 'code' => 'PRG', 'slug' => 'program']);
        $this->departmentId = $this->insert('departments', ['name' => 'Department', 'code' => 'DEP']);
        $this->jobLevelId = $this->insert('job_levels', ['name_en' => 'Officer', 'name_ar' => 'موظف']);

        foreach (['A' => '5,6', 'B' => '0,6', 'F' => '5'] as $key => $weekends) {
            $this->centers[$key] = $this->insert('centers', [
                'name_en' => 'Center '.$key, 'name_ar' => 'مركز '.$key, 'weekends' => $weekends,
                'open_at' => '08:00:00', 'close_at' => '17:00:00', 'city_id' => $this->cityId,
            ]);
        }

        DB::table('contract_types')->insert(['id' => 1, 'name_en' => 'Annual', 'name_ar' => 'سنوي', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([
            LeaveTypeCases::Annual => 0,
            LeaveTypeCases::FullSick => 10,
            LeaveTypeCases::MaternityFirst => 120,
        ] as $id => $baseDays) {
            DB::table('leave_types')->insert([
                'id' => $id, 'name_en' => 'Type '.$id, 'name_ar' => 'نوع '.$id,
                'base_days' => $baseDays, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $hrEmployeeId = $this->insert('employees', [
            'city_id' => $this->cityId, 'first_name_en' => 'Hana', 'first_name_ar' => 'هناء',
            'last_name_en' => 'HR', 'last_name_ar' => 'موارد', 'username' => 'accounting-hr', 'is_active' => true,
        ]);
        $this->hr = User::query()->create(['employee_id' => $hrEmployeeId, 'email' => 'accounting.hr@example.test', 'password' => 'password']);
        $this->hr->assignRole(Role::findOrCreate('HR-HMS', 'web'));
        // C3: deciding needs leaves.decide; the requesters here have no direct
        // manager, so HR in scope decides (C3-Q1).
        $this->hr->givePermissionTo(['leaves.create', 'leaves.update', 'leaves.decide']);
        $this->hr = $this->hr->fresh();
    }

    // --- AC-A3-01 -------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: float}>
     */
    public static function fixtures(): array
    {
        return [
            'F1 center A, Sun-Thu' => ['F1', ['start_at' => '2026-11-01', 'end_at' => '2026-11-05'], 40.0],
            'F2 center A, Fri+Sat skipped' => ['F2', ['start_at' => '2026-11-01', 'end_at' => '2026-11-08'], 48.0],
            'F4 national holiday skipped' => ['F4', ['start_at' => '2026-11-01', 'end_at' => '2026-11-05'], 32.0],
            'F6 hourly, exact' => ['F6', ['type' => 'hour', 'leave_date' => '2026-11-03', 'start_hour' => '09:00', 'end_hour' => '12:20'], 3.33],
            'F8 hourly, over 4 h is a day' => ['F8', ['type' => 'hour', 'leave_date' => '2026-11-03', 'start_hour' => '09:00', 'end_hour' => '13:15'], 8.0],
            'F10 six-day week' => ['F10', ['start_at' => '2026-11-01', 'end_at' => '2026-11-03'], 24.0],
            'F11 maternity, calendar days' => ['F11', ['start_at' => '2026-11-02', 'end_at' => '2026-12-01', 'is_exceptional' => true], 240.0],
            'F12 pending reserves' => ['F12', ['start_at' => '2026-11-01', 'end_at' => '2026-11-04'], 32.0],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('fixtures')]
    public function test_preview_snapshot_and_deduction_are_the_same_figure(string $fixture, array $input, float $expected): void
    {
        [$user, $balance] = match ($fixture) {
            'F10' => $this->requester('F', weeklyHours: 48),
            'F11' => $this->requester('B', typeId: LeaveTypeCases::MaternityFirst, deserved: 960),
            'F12' => $this->requester('A', deserved: 96, used: 16),
            default => $this->requester('A'),
        };

        if ($fixture === 'F4') {
            $this->insert('holidays', [
                'name' => 'Holiday', 'start_date' => '2026-11-04', 'end_date' => '2026-11-04',
                'holidayable_type' => (new City)->getMorphClass(), 'holidayable_id' => $this->nationalCityId, 'is_recurring' => false,
            ]);
        }

        if ($fixture === 'F12') {
            // Legacy pending request (no snapshot): Sun 15 – Tue 17 Nov = 24 h.
            $this->leaveRequest($balance, '2026-11-15', '2026-11-17');
        }

        $usedBefore = (float) $balance->fresh()->used;
        $component = $this->fill($user, $balance, $input);

        // 1. What the requester sees before sending.
        $impact = $component->instance()->leaveImpact;
        $this->assertTrue($impact['ready']);
        $this->assertSame([], $impact['errors']);
        $this->assertSame($expected, $impact['requested_value']);
        $component->assertSeeHtml('data-testid="leave-impact"')
            ->assertSee($impact['requested'])
            ->assertSee(__('leave_quote.preview.estimate'));

        if ($fixture === 'F4') {
            $component->assertSee('04-11-2026')->assertSee(__('leave_quote.reasons.holiday'))
                ->assertSee(__('leave_quote.codes.holiday_inside'));
        }

        if ($fixture === 'F12') {
            $component->assertSee(__('leave_quote.codes.pending_reserved'));
            $this->assertStringContainsString('3', $impact['pending']);
        }

        // 2. What is stored.
        $component->call('confirmSave')->assertHasNoErrors()->assertDispatched('confirm-dialog')
            ->call('processSave')->assertHasNoErrors()->assertSet('showModal', false);

        $created = LeaveRequest::query()->where('leave_balance_id', $balance->id)->latest('id')->firstOrFail();
        $this->assertSame($expected, $created->computed_hours);
        $this->assertSame(LeaveQuote::RULE_VERSION, $created->rule_version);
        $this->assertSame(LeaveRequestStatus::Pending, $created->status);
        $this->assertSame($fixture === 'F11', $created->is_exceptional);

        // 3. What accepting deducts.
        $this->decide($created, LeaveRequestStatus::Accepted)->assertHasNoErrors();

        $this->assertSame(LeaveRequestStatus::Accepted, $created->fresh()->status);
        $this->assertEqualsWithDelta($expected, (float) $balance->fresh()->used - $usedBefore, 0.001);
    }

    public function test_the_saved_request_is_listed_with_its_stored_amount(): void
    {
        [$user, $balance] = $this->requester('A');
        $this->fill($user, $balance, ['type' => 'hour', 'leave_date' => '2026-11-03', 'start_hour' => '09:00', 'end_hour' => '13:15'])
            ->call('processSave')->assertHasNoErrors();

        $created = LeaveRequest::query()->latest('id')->firstOrFail();

        // 4 h 15 min over a full-time 8 h day is deducted as 8 h; the legacy
        // wording would have said 4 h 15 min.
        $this->assertSame(trans_choice('general.count.hours', 8), $created->duration);

        $day = $this->leaveRequest($balance, '2026-11-15', '2026-11-17', computedHours: 24.0);
        $this->assertSame(trans_choice('general.count.days', 3), $day->duration);
    }

    public function test_the_balance_card_pending_figure_comes_from_the_pricing_service(): void
    {
        [$user, $balance] = $this->requester('A');
        // Snapshot 24 h (3 days) and a legacy hourly request of 2 h.
        $this->leaveRequest($balance, '2026-11-15', '2026-11-17', computedHours: 24.0);
        $this->leaveRequest($balance, '2026-11-18 09:00', '2026-11-18 11:00', type: 'hour');

        $card = $user->profile->availableLeaves()->firstWhere('id', $balance->id);

        $this->assertSame(26.0, $card->pending_total_hours);
        $this->assertSame(3, $card->pending_days);
        $this->assertSame(2.0, $card->pending_hours);
    }

    public function test_the_balance_card_and_the_preview_count_the_same_days(): void
    {
        // 40 h over center F's six-day week is 6.67 h a day: 80 h is 12 days on
        // both, not 10 on the card (the old ÷ 5) and 12 in the preview.
        [$user, $balance] = $this->requester('F', deserved: 80);

        $component = $this->fill($user, $balance, ['start_at' => '2026-11-02', 'end_at' => '2026-11-02']);
        $card = $user->profile->availableLeaves()->firstWhere('id', $balance->id);

        $this->assertSame('12 days', $component->instance()->leaveImpact['available']);
        $this->assertSame(12, $card->int_remaining);
        $this->assertSame(12, $card->int_total);
        $this->assertSame('12 Days', trim(strip_tags($card->remaining)));
        $this->assertSame('12 Days', trim(strip_tags($card->deserved_formatted)));
    }

    // --- AC-A3-02 -------------------------------------------------------------

    public function test_an_insufficient_annual_balance_is_a_field_error_that_keeps_the_input(): void
    {
        [$user, $balance] = $this->requester('A', deserved: 16);
        $before = LeaveRequest::query()->count();

        $component = $this->fill($user, $balance, ['start_at' => '2026-11-01', 'end_at' => '2026-11-05'])
            ->call('confirmSave')
            ->assertHasErrors(['form.leave_balance_id'])
            ->assertNotDispatched('confirm-dialog')
            ->assertSet('showModal', true)
            ->assertSet('form.start_at', '2026-11-01')
            ->assertSet('form.end_at', '2026-11-05')
            ->assertSet('form.reason', 'Synthetic leave.')
            ->assertSet('form.leave_balance_id', (string) $balance->id);

        $this->assertSame(__('leave_quote.codes.exceeds_balance'), $component->errors()->first('form.leave_balance_id'));
        $this->assertTrue($component->instance()->leaveImpact['short']);

        // The write path refuses it as well, still keeping the input.
        $component->call('processSave')
            ->assertHasErrors(['form.leave_balance_id'])
            ->assertSet('form.start_at', '2026-11-01');

        $this->assertSame($before, LeaveRequest::query()->count());
    }

    public function test_a_non_annual_request_over_its_balance_only_warns(): void
    {
        [$user, $balance] = $this->requester('A', typeId: LeaveTypeCases::FullSick, deserved: 8);

        $component = $this->fill($user, $balance, ['start_at' => '2026-11-01', 'end_at' => '2026-11-02']);
        $impact = $component->instance()->leaveImpact;

        $this->assertSame([], $impact['errors']);
        $this->assertContains(__('leave_quote.codes.exceeds_balance'), $impact['warnings']);

        $component->call('confirmSave')->assertHasNoErrors()
            ->call('processSave')->assertHasNoErrors();

        $this->assertSame(16.0, LeaveRequest::query()->latest('id')->firstOrFail()->computed_hours);
    }

    public function test_hourly_errors_are_linked_to_the_end_time_and_the_half_day_cap_is_gone(): void
    {
        [$user, $balance] = $this->requester('A');

        $this->fill($user, $balance, ['type' => 'hour', 'leave_date' => '2026-11-03', 'start_hour' => '09:00', 'end_hour' => '09:10'])
            ->call('confirmSave')
            ->assertHasErrors(['form.end_hour'])
            ->assertSet('form.end_hour', '09:10')
            ->assertSet('form.type', 'hour');

        $this->fill($user, $balance, ['type' => 'hour', 'leave_date' => '2026-11-03', 'start_hour' => '09:00', 'end_hour' => '17:30'])
            ->call('confirmSave')
            ->assertHasErrors(['form.end_hour']);

        // Six hours used to break the half-day cap; now it is one full day.
        $this->fill($user, $balance, ['type' => 'hour', 'leave_date' => '2026-11-03', 'start_hour' => '09:00', 'end_hour' => '15:00'])
            ->call('confirmSave')
            ->assertHasNoErrors();
    }

    public function test_a_range_of_weekend_days_only_is_reported_on_the_start_date(): void
    {
        [$user, $balance] = $this->requester('A');

        $this->fill($user, $balance, ['start_at' => '2026-11-06', 'end_at' => '2026-11-07'])
            ->call('confirmSave')
            ->assertHasErrors(['form.start_at'])
            ->assertSee(__('leave_quote.codes.no_countable_days'));
    }

    // --- Stale balance and decision-time recheck -------------------------------

    public function test_a_request_accepted_in_another_session_between_preview_and_send_is_refused(): void
    {
        [$user, $balance] = $this->requester('A', deserved: 48);

        $component = $this->fill($user, $balance, ['start_at' => '2026-11-01', 'end_at' => '2026-11-05']);
        $this->assertSame([], $component->instance()->leaveImpact['errors']);
        $component->call('confirmSave')->assertHasNoErrors();

        // Meanwhile another request on the same balance is sent and accepted.
        $other = $this->leaveRequest($balance, '2026-11-15', '2026-11-16', computedHours: 16.0);
        $this->decide($other, LeaveRequestStatus::Accepted)->assertHasNoErrors();
        $this->assertSame(16.0, (float) $balance->fresh()->used);

        // Back in the requester's session, the open form is sent.
        $this->actingAs($user);
        $component->call('processSave')
            ->assertHasErrors(['form.leave_balance_id'])
            ->assertSet('showModal', true)
            ->assertSet('form.start_at', '2026-11-01');

        $this->assertSame(1, LeaveRequest::query()->where('leave_balance_id', $balance->id)->count());
    }

    public function test_accepting_is_refused_when_the_annual_balance_no_longer_covers_the_request(): void
    {
        [, $balance] = $this->requester('A', deserved: 48);
        $pending = $this->leaveRequest($balance, '2026-11-01', '2026-11-05', computedHours: 40.0);

        // Something else used the balance after the request was sent.
        $balance->update(['used' => 16]);

        $component = $this->decide($pending, LeaveRequestStatus::Accepted)
            ->assertHasErrors(['form.status']);

        $this->assertStringContainsString(
            trans_choice('leaves.duration.hours', 40, ['count' => 40]),
            $component->errors()->first('form.status'),
        );
        $this->assertSame(LeaveRequestStatus::Pending, $pending->fresh()->status);
        $this->assertSame(16.0, (float) $balance->fresh()->used);
    }

    public function test_a_legacy_pending_request_is_deducted_with_the_legacy_rule(): void
    {
        [, $balance] = $this->requester('A');
        // Sun 1 – Mon 2 Nov under the old rule: (1 weekday + 1) × 40/5 = 16 h.
        $legacy = $this->leaveRequest($balance, '2026-11-01', '2026-11-02');

        $this->decide($legacy, LeaveRequestStatus::Accepted)->assertHasNoErrors();

        $this->assertSame(16.0, (float) $balance->fresh()->used);
        $this->assertNull($legacy->fresh()->computed_hours);
    }

    // --- Edit of an accepted request (F15) -----------------------------------

    public function test_editing_an_accepted_request_moves_used_by_the_difference(): void
    {
        [$user, $balance] = $this->requester('A');
        $this->fill($user, $balance, ['start_at' => '2026-11-01', 'end_at' => '2026-11-02'])
            ->call('processSave')->assertHasNoErrors();
        $request = LeaveRequest::query()->latest('id')->firstOrFail();
        $this->decide($request, LeaveRequestStatus::Accepted)->assertHasNoErrors();
        $this->assertSame(16.0, (float) $balance->fresh()->used);

        $this->editAsHr($request)
            ->set('form.end_at', '2026-11-03')
            ->call('confirmSave')->assertHasNoErrors()
            ->call('processSave')->assertHasNoErrors();

        $this->assertSame(24.0, (float) $balance->fresh()->used);
        $this->assertSame(24.0, $request->fresh()->computed_hours);
        $this->assertSame(LeaveRequestStatus::Accepted, $request->fresh()->status);

        // Shorter again: used goes down by the difference.
        $this->editAsHr($request->fresh())
            ->set('form.end_at', '2026-11-01')
            ->call('processSave')->assertHasNoErrors();

        $this->assertSame(8.0, (float) $balance->fresh()->used);
    }

    public function test_editing_a_legacy_accepted_request_uses_its_legacy_amount_as_the_old_figure(): void
    {
        [, $balance] = $this->requester('A', used: 16);
        $legacy = $this->leaveRequest($balance, '2026-11-01', '2026-11-02', status: LeaveRequestStatus::Accepted);

        // A content-only edit prices nothing: no snapshot, used unchanged.
        $this->editAsHr($legacy)
            ->set('form.reason', 'Only the reason changes.')
            ->call('processSave')->assertHasNoErrors();

        $this->assertSame(16.0, (float) $balance->fresh()->used);
        $this->assertNull($legacy->fresh()->computed_hours);

        $this->editAsHr($legacy->fresh())
            ->set('form.end_at', '2026-11-03')
            ->call('processSave')->assertHasNoErrors();

        // old 16 h (legacy) → new 24 h (quote): used += 8 h.
        $this->assertSame(24.0, (float) $balance->fresh()->used);
        $this->assertSame(24.0, $legacy->fresh()->computed_hours);
    }

    public function test_editing_an_accepted_request_beyond_the_annual_balance_is_refused(): void
    {
        [, $balance] = $this->requester('A', deserved: 24, used: 16);
        $accepted = $this->leaveRequest($balance, '2026-11-01', '2026-11-02', status: LeaveRequestStatus::Accepted, computedHours: 16.0);

        $this->editAsHr($accepted)
            ->set('form.end_at', '2026-11-04')
            ->call('processSave')
            ->assertHasErrors(['form.leave_balance_id'])
            ->assertSet('form.end_at', '2026-11-04');

        $this->assertSame(16.0, (float) $balance->fresh()->used);
        $this->assertSame(16.0, $accepted->fresh()->computed_hours);
    }

    // --- December (D-7) --------------------------------------------------------

    public function test_december_is_blocked_without_the_exceptional_flag_and_allowed_with_it(): void
    {
        [$user, $balance] = $this->requester('A');

        $november = $this->fill($user, $balance, ['start_at' => '2026-11-24', 'end_at' => '2026-11-25']);
        $november->assertDontSee(__('leave_quote.exceptional.label'));

        $component = $this->fill($user, $balance, ['start_at' => '2026-12-01', 'end_at' => '2026-12-03'])
            ->assertSee(__('leave_quote.exceptional.label'))
            ->assertSee(__('leave_quote.exceptional.hint'))
            ->call('confirmSave')
            ->assertHasErrors(['form.start_at'])
            ->assertSet('form.start_at', '2026-12-01');

        $this->assertSame(__('leave_quote.codes.december_restricted'), $component->errors()->first('form.start_at'));

        $component->set('form.is_exceptional', true)
            ->call('confirmSave')->assertHasNoErrors()
            ->call('processSave')->assertHasNoErrors();

        $created = LeaveRequest::query()->latest('id')->firstOrFail();
        $this->assertTrue($created->is_exceptional);
        $this->assertSame(24.0, $created->computed_hours);
    }

    public function test_a_stale_exceptional_tick_on_a_november_period_is_not_stored(): void
    {
        [$user, $balance] = $this->requester('A');

        $this->fill($user, $balance, ['start_at' => '2026-11-24', 'end_at' => '2026-11-25', 'is_exceptional' => true])
            ->call('processSave')->assertHasNoErrors();

        $this->assertFalse(LeaveRequest::query()->latest('id')->firstOrFail()->is_exceptional);
    }

    public function test_maternity_across_december_without_the_flag_is_blocked(): void
    {
        [$user, $balance] = $this->requester('B', typeId: LeaveTypeCases::MaternityFirst, deserved: 960);

        $this->fill($user, $balance, ['start_at' => '2026-11-02', 'end_at' => '2026-12-01'])
            ->call('confirmSave')
            ->assertHasErrors(['form.start_at']);
    }

    // --- Preview edge cases ------------------------------------------------------

    public function test_a_partial_or_reversed_form_shows_a_hint_or_an_error_and_never_throws(): void
    {
        [$user, $balance] = $this->requester('A');

        $component = Livewire::actingAs($user)->test(MyLeaveRequestModule::class)->call('create');
        $this->assertSame(['ready' => false], $component->instance()->leaveImpact);
        $component->assertSee(__('leave_quote.preview.hint'));

        $component->set('form.leave_balance_id', (string) $balance->id)
            ->set('form.start_at', '2026-11-05')
            ->set('form.end_at', 'not-a-date');
        $this->assertSame(['ready' => false], $component->instance()->leaveImpact);

        $component->set('form.end_at', '2026-11-01');
        $this->assertSame([__('leave_quote.codes.invalid_window')], $component->instance()->leaveImpact['errors']);

        $component->set('form.end_at', '2030-11-01');
        $this->assertSame(['ready' => false], $component->instance()->leaveImpact);
    }

    public function test_the_preview_never_prices_another_employees_balance(): void
    {
        [$user] = $this->requester('A');
        [$other, $otherBalance] = $this->requester('A');

        $component = Livewire::actingAs($user)->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.employee_id', $other->employee_id)
            ->set('form.leave_balance_id', (string) $otherBalance->id)
            ->set('form.start_at', '2026-11-01')
            ->set('form.end_at', '2026-11-02');

        $this->assertSame(['ready' => false], $component->instance()->leaveImpact);
        $component->assertDontSeeHtml('data-testid="leave-impact"');
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     */
    private function fill(User $user, LeaveBalance $balance, array $input): Testable
    {
        $component = Livewire::actingAs($user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.leave_balance_id', (string) $balance->id);

        foreach (['type', 'start_at', 'end_at', 'leave_date', 'start_hour', 'end_hour', 'is_exceptional'] as $field) {
            if (array_key_exists($field, $input)) {
                $component->set('form.'.$field, $input[$field]);
            }
        }

        return $component->set('form.reason', 'Synthetic leave.');
    }

    private function decide(LeaveRequest $request, int $status): Testable
    {
        return Livewire::actingAs($this->hr)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $request->id)
            ->set('form.status', $status)
            ->call('submitDecision');
    }

    private function editAsHr(LeaveRequest $request): Testable
    {
        return Livewire::actingAs($this->hr)
            ->test(AllLeaveRequestModule::class)
            ->call('edit', $request->id)
            ->assertSet('formModal', true);
    }

    /**
     * A requester with an account, a 2026 contract at $center and one balance.
     *
     * @return array{0: User, 1: LeaveBalance}
     */
    private function requester(string $center, int $weeklyHours = 40, int $typeId = LeaveTypeCases::Annual, int $deserved = 160, float $used = 0): array
    {
        $this->sequence++;

        $employeeId = $this->insert('employees', [
            'city_id' => $this->cityId, 'first_name_en' => 'Test', 'first_name_ar' => 'اختبار',
            'last_name_en' => 'Requester '.$this->sequence, 'last_name_ar' => 'طالب '.$this->sequence,
            'username' => 'accounting-requester-'.$this->sequence, 'is_active' => true,
        ]);
        $positionId = $this->insert('positions', [
            'program_id' => $this->programId, 'center_id' => $this->centers[$center], 'city_id' => $this->cityId,
            'job_level_id' => $this->jobLevelId, 'department_id' => $this->departmentId,
            'title_en' => 'Officer', 'title_ar' => 'موظف',
        ]);
        $contractId = $this->insert('contracts', [
            'employee_id' => $employeeId, 'position_id' => $positionId, 'contract_type_id' => 1,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency' => 2, 'weekly_hours' => $weeklyHours,
        ]);
        $balanceId = $this->insert('leave_balances', [
            'contract_id' => $contractId, 'type_id' => $typeId, 'deserved' => $deserved, 'used' => $used,
        ]);

        $user = User::query()->create([
            'employee_id' => $employeeId,
            'email' => 'requester'.$this->sequence.'@example.test',
            'password' => 'password',
        ]);
        $user->givePermissionTo(['leaves.create', 'leaves.update']);

        return [$user->fresh(), LeaveBalance::query()->findOrFail($balanceId)];
    }

    private function leaveRequest(
        LeaveBalance $balance,
        string $start,
        string $end,
        int $status = LeaveRequestStatus::Pending,
        ?float $computedHours = null,
        string $type = 'day',
    ): LeaveRequest {
        $id = $this->insert('leave_requests', [
            'leave_balance_id' => $balance->id, 'type' => $type,
            'start_at' => Carbon::parse($start), 'end_at' => Carbon::parse($end),
            'reason' => 'Synthetic', 'status' => $status, 'computed_hours' => $computedHours,
            'approved_at' => $status === LeaveRequestStatus::Pending ? null : now(),
        ]);

        return LeaveRequest::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function insert(string $table, array $values): int
    {
        return DB::table($table)->insertGetId($values + ['created_at' => now(), 'updated_at' => now()]);
    }
}
