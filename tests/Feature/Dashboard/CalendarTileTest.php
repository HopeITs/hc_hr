<?php

namespace Tests\Feature\Dashboard;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Dashboard\CalendarCard;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Models\City;
use App\Models\Contract;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The calendar tile (prompt 6): real events only, the day dialog, month
 * navigation, and "Request leave for this day" for those who may.
 */
class CalendarTileTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $dms;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
        $this->dms = $this->makeCity('DMS', '003');
    }

    public function test_the_dialog_lists_the_right_day(): void
    {
        $user = $this->makeUser($this->dms, 'employee', ['leaves.view-own']);
        $contract = $this->makeContractFor($user->profile);
        $this->leave($contract, '2026-11-03', 'Annual');
        $this->leave($contract, '2026-11-04', 'Sick');

        $component = Livewire::actingAs($user)->test(CalendarCard::class);
        $html = $component->html();

        $this->assertSame(['my-leave'], array_column($component->viewData('days')['2026-11-03'], 'type'));
        $this->assertSame('Annual', $component->viewData('days')['2026-11-03'][0]['title']);
        $this->assertSame('Sick', $component->viewData('days')['2026-11-04'][0]['title']);

        // Each day's list in the dialog holds that day's events and no other's.
        $this->assertSame(1, preg_match('/data-day="2026-11-03".*?<\/ul>/s', $html, $day));
        $this->assertStringContainsString('Annual', $day[0]);
        $this->assertStringNotContainsString('Sick', $day[0]);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('x-trap.noscroll', $html);
    }

    public function test_sample_events_are_gone(): void
    {
        config(['samples.calendar.events' => ['2026-11-05' => 'Sample party'], 'samples.calendar.saints' => ['2026-11-06' => ['Saint Sample']]]);
        $user = $this->makeUser($this->dms, 'employee');

        Livewire::actingAs($user)->test(CalendarCard::class)
            ->assertDontSee('Sample party')
            ->assertDontSee('Saint Sample');
    }

    public function test_today_is_marked_and_the_grid_has_six_weeks(): void
    {
        $user = $this->makeUser($this->dms, 'employee');

        $component = Livewire::actingAs($user)->test(CalendarCard::class);

        $this->assertCount(42, $component->viewData('cells'));
        $component->assertSeeHtml('data-date="2026-11-02"')
            ->assertSeeHtml('aria-current="date"');
        $this->assertSame(['2026-11-02'], array_column(array_filter($component->viewData('cells'), fn ($c) => $c['isToday']), 'date'));
    }

    public function test_month_navigation_and_today(): void
    {
        $user = $this->makeUser($this->dms, 'employee');

        Livewire::actingAs($user)->test(CalendarCard::class)
            ->assertSet('month', '2026-11')
            ->assertViewHas('isCurrentMonth', true)
            ->assertDontSeeHtml('wire:click="goToday"')
            ->call('next')
            ->assertSet('month', '2026-12')
            ->assertViewHas('isCurrentMonth', false)
            ->assertSeeHtml('wire:click="goToday"')
            ->call('prev')->call('prev')
            ->assertSet('month', '2026-10')
            ->call('goToday')
            ->assertSet('month', '2026-11');
    }

    public function test_the_month_picker_jumps_to_any_month_and_year(): void
    {
        $user = $this->makeUser($this->dms, 'employee');

        Livewire::actingAs($user)->test(CalendarCard::class)
            ->assertSeeHtml('data-testid="month-picker"')
            ->call('jumpTo', 2023, 4)
            ->assertSet('month', '2023-04')
            ->assertViewHas('isCurrentMonth', false)
            // Out-of-range picks change nothing.
            ->call('jumpTo', 2023, 13)
            ->call('jumpTo', 3000, 1)
            ->assertSet('month', '2023-04');
    }

    public function test_a_forged_month_shows_this_month(): void
    {
        $user = $this->makeUser($this->dms, 'employee');

        Livewire::actingAs($user)->test(CalendarCard::class)
            ->set('month', '2026-13')
            ->assertViewHas('isCurrentMonth', true)
            ->set('month', "'; drop")
            ->assertViewHas('isCurrentMonth', true);
    }

    public function test_request_leave_is_offered_only_to_who_may_request(): void
    {
        $requester = $this->makeUser($this->dms, 'employee', ['leaves.view-own', 'leaves.create']);
        $hr = $this->makeUser($this->dms, 'HR-DMS');

        Livewire::actingAs($requester)->test(CalendarCard::class)
            ->assertSeeHtml('data-testid="calendar-request-leave"')
            ->assertViewHas('requestUrl', route('leaves.index', ['new' => 1, 'date' => '__DATE__']));

        Livewire::actingAs($hr)->test(CalendarCard::class)
            ->assertDontSeeHtml('data-testid="calendar-request-leave"');
    }

    public function test_the_next_seven_days_list_reads_from_today(): void
    {
        $user = $this->makeUser($this->dms, 'employee', ['leaves.view-own']);
        $contract = $this->makeContractFor($user->profile);
        $this->leave($contract, '2026-11-01', 'Yesterday');
        $this->leave($contract, '2026-11-05', 'Soon');
        $this->leave($contract, '2026-11-20', 'Later');

        $component = Livewire::actingAs($user)->test(CalendarCard::class);

        $this->assertSame(['2026-11-05'], array_keys($component->viewData('upcoming')));

        // Browsing another month keeps the list on the coming week.
        $component->call('next');
        $this->assertSame(['2026-11-05'], array_keys($component->viewData('upcoming')));
    }

    public function test_the_legend_lists_only_the_types_the_viewer_may_see(): void
    {
        $user = $this->makeUser($this->dms, 'employee', ['leaves.view-own']);

        $types = Livewire::actingAs($user)->test(CalendarCard::class)->viewData('types');

        $this->assertContains('my-leave', $types);
        $this->assertNotContains('contract-end', $types);
    }

    public function test_a_failed_load_shows_the_load_error_inside_the_tile(): void
    {
        $user = $this->makeUser($this->dms, 'employee', ['leaves.view-own']);
        Schema::drop('leave_requests');

        Livewire::actingAs($user)->test(CalendarCard::class)
            ->assertSet('loadFailed', true)
            ->assertSee(__('general.state-error-title'));
    }

    public function test_the_leave_form_opens_with_the_date_prefilled(): void
    {
        $user = $this->makeUser($this->dms, 'employee', ['leaves.view-own', 'leaves.create']);
        $this->makeContractFor($user->profile);

        Livewire::actingAs($user)->withQueryParams(['new' => 1, 'date' => '2026-11-09'])
            ->test(MyLeaveRequestModule::class)
            ->assertSet('showModal', true)
            ->assertSet('form.start_at', '2026-11-09')
            ->assertSet('form.end_at', '2026-11-09')
            ->assertSet('startDate', '');
    }

    public function test_a_past_or_invalid_date_is_not_prefilled(): void
    {
        $user = $this->makeUser($this->dms, 'employee', ['leaves.view-own', 'leaves.create']);
        $this->makeContractFor($user->profile);

        foreach (['2026-10-01', '2026-02-30', 'tomorrow'] as $date) {
            Livewire::actingAs($user)->withQueryParams(['new' => 1, 'date' => $date])
                ->test(MyLeaveRequestModule::class)
                ->assertSet('showModal', true)
                ->assertSet('form.start_at', '');
        }
    }

    private function leave(Contract $contract, string $day, string $typeName): LeaveRequest
    {
        $type = LeaveType::query()->firstOrCreate(['name_en' => $typeName], ['name_ar' => $typeName, 'base_days' => 20]);
        $balance = LeaveBalance::query()->firstOrCreate(['contract_id' => $contract->id, 'type_id' => $type->id], ['deserved' => 160, 'used' => 0]);

        return LeaveRequest::query()->create([
            'leave_balance_id' => $balance->id,
            'type' => 'day',
            'start_at' => $day,
            'end_at' => $day,
            'status' => LeaveRequestStatus::Accepted,
        ]);
    }
}
