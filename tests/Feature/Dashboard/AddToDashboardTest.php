<?php

namespace Tests\Feature\Dashboard;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Dashboard\AddToDashboard;
use App\Livewire\Dashboard\EmployeeContactCard;
use App\Livewire\Dashboard\EmployeeContractsCard;
use App\Livewire\Dashboard\EmployeesCard;
use App\Livewire\Dashboard\Index;
use App\Livewire\Dashboard\MyRequestsCard;
use App\Models\City;
use App\Models\Contract;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\SavedDashboardLayout;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * "Add to dashboard" (2026-11-03): a page's live card, or a card about one
 * record from a section of its page (rec.<type>.<employee id>), put on the
 * viewer's own dashboard and taken off again; and Reset to default beside
 * the groups (the layout only, or everything).
 */
class AddToDashboardTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $alp;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
        $this->alp = $this->makeCity('ALP', '001');
    }

    public function test_each_page_card_follows_its_page_rule(): void
    {
        $layout = new DashboardLayout;
        $staff = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $hr = $this->makeUser($this->alp, 'HR-ALP', ['employees.view', 'contracts.view']);

        $this->assertTrue($layout->allowed($staff, 'my-requests'));
        $this->assertFalse($layout->allowed($staff, 'employees'));
        $this->assertFalse($layout->allowed($staff, 'soon-ending-contracts'));

        $this->assertTrue($layout->allowed($hr, 'employees'));
        // The contracts page's card: contracts.view is enough now, not only statistics.view.
        $this->assertTrue($layout->allowed($hr, 'soon-ending-contracts'));
    }

    public function test_a_record_card_is_allowed_while_its_section_would_be(): void
    {
        $layout = new DashboardLayout;
        $hms = $this->makeCity('HMS', '002');
        $staff = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $colleague = $this->makeEmployee($this->alp, 'colleague');
        $hr = $this->makeUser($this->alp, 'HR-ALP', ['employees.view']);
        $far = $this->makeEmployee($hms, 'far-away');

        $own = DashboardLayout::recordKey('contracts', $staff->employee_id);

        // Contracts: the profile's viewRecords (self yes, a colleague no; regional HR its own city).
        $this->assertTrue($layout->allowed($staff, $own));
        $this->assertFalse($layout->allowed($staff, DashboardLayout::recordKey('contracts', $colleague->id)));
        $this->assertTrue($layout->allowed($hr, DashboardLayout::recordKey('contracts', $colleague->id)));
        $this->assertFalse($layout->allowed($hr, DashboardLayout::recordKey('contracts', $far->id)));

        // Unknown types, ids or shapes are never allowed.
        foreach (['rec.salary.'.$staff->employee_id, 'rec.contracts.999999', 'rec.contracts.x', 'rec.contracts'] as $key) {
            $this->assertFalse($layout->allowed($staff, $key), $key);
        }

        $this->assertSame(['min' => [1, 1], 'max' => [12, 8], 'card' => [3, 2], 'full' => [4, 3]], $layout->bounds($own));
        $this->assertSame(EmployeeContractsCard::class, $layout->component($own));
        $this->assertSame(['employeeId' => $staff->employee_id], $layout->componentParams($own));
        $this->assertStringContainsString($staff->profile->name, $layout->label($own));
    }

    public function test_the_button_puts_the_card_first_and_takes_it_off_again(): void
    {
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);

        $button = Livewire::actingAs($user)->test(AddToDashboard::class, ['tile' => 'my-requests'])
            ->assertSee(__('dashboard.add.add'))
            ->call('toggle')
            ->assertSee(__('dashboard.add.on'));

        $saved = $this->saved($user);
        $this->assertSame('my-requests', $saved[0]['key']);
        $this->assertFalse($saved[0]['hidden']);

        $button->call('toggle')->assertSee(__('dashboard.add.add'));
        $this->assertTrue(collect($this->saved($user))->firstWhere('key', 'my-requests')['hidden']);
    }

    public function test_a_record_card_is_stored_with_its_record_and_leaves_when_taken_off(): void
    {
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $key = DashboardLayout::recordKey('contact', $user->employee_id);

        $button = Livewire::actingAs($user)->test(AddToDashboard::class, ['tile' => $key, 'compact' => true])->call('toggle');
        $this->assertSame($key, $this->saved($user)[0]['key']);

        // The dashboard draws it, mounted with its employee.
        $this->assertStringContainsString('data-key="'.$key.'"', Livewire::actingAs($user)->test(Index::class)->html());

        $button->call('toggle');
        $this->assertNotContains($key, array_column($this->saved($user), 'key'));
    }

    public function test_a_card_the_person_may_not_have_is_neither_offered_nor_stored(): void
    {
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $colleague = $this->makeEmployee($this->alp, 'colleague');
        $key = DashboardLayout::recordKey('contracts', $colleague->id);

        Livewire::actingAs($user)->test(AddToDashboard::class, ['tile' => $key])
            ->assertDontSeeHtml('data-testid="add-to-dashboard"')
            ->call('toggle')
            ->assertForbidden();

        // An edited layout cannot smuggle it in either.
        Livewire::actingAs($user)->test(Index::class)->call('saveLayout', [
            ['key' => $key, 'size' => '4x3', 'pinned' => false, 'hidden' => false],
        ]);
        $this->assertNotContains($key, array_column($this->saved($user), 'key'));
    }

    public function test_the_live_cards_read_only_their_pages_rows(): void
    {
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own', 'leaves.create']);
        $other = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $this->leave($this->makeContractFor($user->profile), '2026-11-20', LeaveRequestStatus::Pending);
        $this->leave($this->makeContractFor($other->profile), '2026-11-21', LeaveRequestStatus::Accepted);

        Livewire::actingAs($user)->test(MyRequestsCard::class)
            ->assertSee('20 Nov')
            ->assertDontSee('21 Nov')
            ->assertSeeHtml('href="'.route('leaves.index', ['new' => 1]).'"');

        $hms = $this->makeCity('HMS', '002');
        $hr = $this->makeUser($this->alp, 'HR-ALP', ['employees.view']);
        $this->makeEmployee($this->alp, 'rania.alp', ['first_name_en' => 'Rania', 'is_active' => true]);
        $this->makeEmployee($hms, 'rania.hms', ['first_name_en' => 'Raniaa', 'is_active' => true]);

        Livewire::actingAs($hr)->test(EmployeesCard::class)
            ->set('q', 'Rania')
            ->assertSee(route('employees.show', 'rania.alp'))
            ->assertDontSee(route('employees.show', 'rania.hms'));

        // A record card whose record is no longer the viewer's to see says so and shows nothing.
        $colleague = $this->makeEmployee($this->alp, 'colleague', ['work_phone' => '0911111111']);
        Livewire::actingAs($user)->test(EmployeeContractsCard::class, ['employeeId' => $colleague->id])
            ->assertSee(__('dashboard.record.gone'));
        Livewire::actingAs($user)->test(EmployeeContactCard::class, ['employeeId' => $user->employee_id])
            ->assertSee($user->profile->name)
            ->assertDontSee(__('dashboard.record.gone'));
    }

    public function test_reset_offers_the_layout_only_or_everything(): void
    {
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $html = Livewire::actingAs($user)->test(Index::class)->html();

        $this->assertStringContainsString('data-testid="dashboard-reset-menu"', $html);
        $this->assertStringContainsString('x-on:click="resetArrangement()"', $html);
        $this->assertStringContainsString('x-on:click="resetAll()"', $html);

        // Each default card's size and place reach the page, for "the layout only".
        preg_match('/data-home="([^"]*)"/', $html, $m);
        $config = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
        $defaults = (new DashboardLayout)->defaults($user);
        $this->assertSame([$defaults[0]['size'], 0], $config['defaults'][$defaults[0]['key']]);

        foreach (['home.reset-menu.label', 'home.reset-menu.layout', 'home.reset-menu.all', 'home.reset-menu.layout-done',
            'add.add', 'add.on', 'add.added', 'tiles.my-requests', 'tiles.employees', 'tiles.rec-contracts', 'tiles.rec-contact', 'record.gone'] as $key) {
            foreach (['en', 'ar'] as $locale) {
                $this->assertNotSame('dashboard.'.$key, __('dashboard.'.$key, [], $locale), "{$key} {$locale}");
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function saved(User $user): array
    {
        $layout = SavedDashboardLayout::query()->where('user_id', $user->id)->sole()->layout;

        return is_string($layout) ? json_decode($layout, true) : $layout;
    }

    private function balance(Contract $contract): LeaveBalance
    {
        $type = LeaveType::query()->firstOrCreate(['name_en' => 'Annual'], ['name_ar' => 'Annual', 'base_days' => 20]);

        return LeaveBalance::query()->firstOrCreate(['contract_id' => $contract->id, 'type_id' => $type->id], ['deserved' => 96, 'used' => 0]);
    }

    private function leave(Contract $contract, string $day, int $status): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'leave_balance_id' => $this->balance($contract)->id,
            'type' => 'day',
            'start_at' => $day,
            'end_at' => $day,
            'reason' => 'Family',
            'status' => $status,
        ]);
    }
}
