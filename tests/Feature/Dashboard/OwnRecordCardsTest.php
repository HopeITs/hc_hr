<?php

namespace Tests\Feature\Dashboard;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Dashboard\AttentionCard;
use App\Livewire\Dashboard\MeCard;
use App\Livewire\Dashboard\RequestLeaveCard;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Models\City;
use App\Models\Contract;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The three cards about the viewer's own record (from the workspace mockup,
 * 2026-11-03): what runs out or waits on it, the leave form as a card, and
 * "Me". Each reads the viewer's own rows only.
 */
class OwnRecordCardsTest extends TestCase
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

    public function test_attention_names_a_contract_running_out_and_leave_left_waiting(): void
    {
        $user = $this->makeUser($this->dms, 'employee', ['leaves.view-own']);
        $contract = $this->makeContractFor($user->profile, ['end_date' => '2026-11-06']);
        $late = $this->leave($contract, '2026-10-17', '2026-10-19', LeaveRequestStatus::Pending);
        $this->leave($contract, '2026-10-20', '2026-10-20', LeaveRequestStatus::Accepted); // decided: not late
        $this->leave($contract, '2026-11-20', '2026-11-20', LeaveRequestStatus::Pending);  // not started yet

        Livewire::actingAs($user)->test(AttentionCard::class)
            ->assertSee(trans_choice('dashboard.attention.contract', 4, ['count' => 4, 'date' => 'Fri 6 Nov']))
            ->assertSee(__('dashboard.attention.leave', ['period' => '17 Oct – 19 Oct']))
            ->assertSeeHtml('href="'.route('leaves.index', ['leave' => $late->id]).'"')
            ->assertDontSee('20 Oct')
            ->assertDontSee('20 Nov')
            ->assertDontSeeHtml('data-tile-empty');
    }

    public function test_a_renewed_or_far_off_contract_needs_nothing(): void
    {
        $user = $this->makeUser($this->dms, 'employee', ['leaves.view-own']);
        $this->makeContractFor($user->profile, ['end_date' => '2026-11-06']);
        $this->makeContractFor($user->profile, ['start_date' => '2026-11-07', 'end_date' => '2027-11-06', 'is_active' => false]);

        Livewire::actingAs($user)->test(AttentionCard::class)->assertSeeHtml('data-tile-empty');

        $other = $this->makeUser($this->dms, 'employee', ['leaves.view-own']);
        $this->makeContractFor($other->profile); // ends 31 Dec: beyond the window

        Livewire::actingAs($other)->test(AttentionCard::class)->assertSeeHtml('data-tile-empty');
    }

    public function test_request_leave_offers_the_open_types_and_hands_off_to_the_form(): void
    {
        $user = $this->makeUser($this->dms, 'employee', ['leaves.view-own', 'leaves.create']);
        $contract = $this->makeContractFor($user->profile);
        $annual = $this->balance($contract, 'Annual', 96, 0);
        $this->balance($contract, 'Spent', 8, 8); // nothing left: not offered

        Livewire::actingAs($user)->test(RequestLeaveCard::class)
            ->assertSeeHtml('action="'.route('leaves.index').'"')
            ->assertSeeHtml('name="balance" value="'.$annual->id.'"')
            ->assertSee('Annual')
            ->assertDontSee('Spent');

        Livewire::actingAs($user)->withQueryParams(['new' => 1, 'date' => '2026-11-09', 'to' => '2026-11-11', 'balance' => (string) $annual->id])
            ->test(MyLeaveRequestModule::class)
            ->assertSet('showModal', true)
            ->assertSet('form.start_at', '2026-11-09')
            ->assertSet('form.end_at', '2026-11-11')
            ->assertSet('form.leave_balance_id', $annual->id)
            ->assertSet('endDate', '')
            ->assertSet('startBalance', '');
    }

    public function test_the_form_ignores_a_last_day_before_the_first_or_someone_elses_balance(): void
    {
        $user = $this->makeUser($this->dms, 'employee', ['leaves.view-own', 'leaves.create']);
        $this->makeContractFor($user->profile);
        $stranger = $this->makeUser($this->dms, 'employee', ['leaves.view-own']);
        $theirs = $this->balance($this->makeContractFor($stranger->profile), 'Annual', 96, 0);

        Livewire::actingAs($user)->withQueryParams(['new' => 1, 'date' => '2026-11-09', 'to' => '2026-11-05', 'balance' => (string) $theirs->id])
            ->test(MyLeaveRequestModule::class)
            ->assertSet('form.start_at', '2026-11-09')
            ->assertSet('form.end_at', '2026-11-09')
            ->assertSet('form.leave_balance_id', null);
    }

    public function test_me_shows_the_own_record_and_never_pay(): void
    {
        $user = $this->makeUser($this->dms, 'employee', ['leaves.view-own']);
        $user->profile->forceFill(['work_phone' => '0900000002'])->save();
        $this->makeContractFor($user->profile, ['end_date' => '2026-11-06']);

        Livewire::actingAs($user)->test(MeCard::class)
            ->assertSee($user->profile->fresh()->name)
            ->assertSee('Volunteer Contract')
            ->assertSee('6 Nov 2026')
            ->assertSee('0900000002')
            ->assertSee(__('dashboard.me.not-set')) // no department, no manager
            ->assertDontSee('1500')
            // No "Profile" button on the card (2026-11-09): the profile opens from the dashboard itself.
            ->assertDontSeeHtml('href="'.route('my-profile').'"');
    }

    private function balance(Contract $contract, string $typeName, float $deserved, float $used): LeaveBalance
    {
        $type = LeaveType::query()->firstOrCreate(['name_en' => $typeName], ['name_ar' => $typeName, 'base_days' => 20]);

        return LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => $type->id, 'deserved' => $deserved, 'used' => $used]);
    }

    private function leave(Contract $contract, string $from, string $to, int $status): LeaveRequest
    {
        $type = LeaveType::query()->firstOrCreate(['name_en' => 'Annual'], ['name_ar' => 'Annual', 'base_days' => 20]);
        $balance = LeaveBalance::query()->firstOrCreate(['contract_id' => $contract->id, 'type_id' => $type->id], ['deserved' => 160, 'used' => 0]);

        return LeaveRequest::query()->create([
            'leave_balance_id' => $balance->id,
            'type' => 'day',
            'start_at' => $from,
            'end_at' => $to,
            'reason' => 'Family',
            'status' => $status,
        ]);
    }
}
