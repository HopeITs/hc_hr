<?php

namespace Tests\Feature\Assistant;

use App\Enums\LeaveRequestStatus;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\City;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\HolidayProject;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\News;
use App\Models\User;
use App\Services\Assistant\EmployeeTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The read tools and the prompt snapshot: each one reads the signed-in
 * employee's own record, is declared only behind the page's own permission,
 * and never carries pay.
 */
class EmployeeToolsTest extends TestCase
{
    use CreatesContractFixtures;
    use RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        config(['assistant.enabled' => true, 'assistant.gemini.key' => 'gemini-key', 'assistant.share_salary' => true]);
        app()->setLocale('en');
        // A Monday, well before December.
        $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));
        $this->city = $this->makeCity('ALP', '001');
    }

    public function test_the_snapshot_describes_the_employee_without_pay(): void
    {
        $user = $this->employeeUser(['leaves.view-own']);
        $contract = $this->makeContractFor($user->profile, ['salary' => 1500, 'work_days_en' => 'Sunday to Thursday', 'work_hours_from' => '09:00:00', 'work_hours_to' => '17:00:00']);
        $this->balanceOn($contract, 'Annual', 160, 16);

        $snapshot = app(EmployeeTools::class)->snapshot($user);

        $this->assertStringContainsString('Name: Test Person', $snapshot);
        $this->assertStringContainsString('Program Officer', $snapshot);
        $this->assertStringContainsString('department Programs', $snapshot);
        $this->assertStringContainsString('center Hope Center ALP', $snapshot);
        $this->assertStringContainsString('from 2026-01-01', $snapshot);
        $this->assertStringContainsString('to 2026-12-31', $snapshot);
        $this->assertStringContainsString('Sunday to Thursday', $snapshot);
        $this->assertStringContainsString('09:00–17:00', $snapshot);
        $this->assertStringContainsString('40 h/week', $snapshot);
        // The same figures and words as the balances tool, in one line per type.
        $balance = app(EmployeeTools::class)->call($user, 'get_my_leave_balances')['balances'][0];
        $this->assertStringContainsString("- Annual: remaining {$balance['remaining']} of {$balance['entitled']} (pending {$balance['pending_approval']})", $snapshot);
        $this->assertStringContainsString('Days', $balance['remaining']);
        $this->assertStringNotContainsString('1500', $snapshot);
        $this->assertStringNotContainsStringIgnoringCase('salary', $snapshot);
        $this->assertLessThanOrEqual(1200, mb_strlen($snapshot));
    }

    public function test_the_snapshot_omits_leave_without_the_permission_and_is_empty_without_a_profile(): void
    {
        $user = $this->employeeUser();
        $this->balanceOn($this->makeContractFor($user->profile), 'Annual', 160, 16);

        $snapshot = app(EmployeeTools::class)->snapshot($user);

        $this->assertStringContainsString('Name: Test Person', $snapshot);
        $this->assertStringNotContainsString('Annual', $snapshot);
        $this->assertStringNotContainsString('remaining', $snapshot);

        $this->assertSame('', app(EmployeeTools::class)->snapshot(User::factory()->create(['employee_id' => null])));
    }

    public function test_leave_requests_can_be_filtered_by_status_and_limited(): void
    {
        $user = $this->employeeUser(['leaves.view-own']);
        $balance = $this->balanceOn($this->makeContractFor($user->profile), 'Annual', 160, 0);
        $this->requestOn($balance, '2026-03-01', LeaveRequestStatus::Pending);
        $this->requestOn($balance, '2026-03-05', LeaveRequestStatus::Accepted);
        $this->requestOn($balance, '2026-03-09', LeaveRequestStatus::Rejected);

        $tools = app(EmployeeTools::class);

        $this->assertCount(3, $tools->call($user, 'get_my_leave_requests')['requests']);
        $this->assertCount(3, $tools->call($user, 'get_my_leave_requests', ['status' => 'all'])['requests']);

        $pending = $tools->call($user, 'get_my_leave_requests', ['status' => 'pending'])['requests'];
        $this->assertCount(1, $pending);
        $this->assertSame('2026-03-01', $pending[0]['start']);

        $refused = $tools->call($user, 'get_my_leave_requests', ['status' => 'refused'])['requests'];
        $this->assertCount(1, $refused);
        $this->assertSame('2026-03-09', $refused[0]['start']);

        $this->assertCount(1, $tools->call($user, 'get_my_leave_requests', ['limit' => 1])['requests']);
        // Out-of-range sizes are clamped, never refused.
        $this->assertCount(3, $tools->call($user, 'get_my_leave_requests', ['limit' => 500])['requests']);

        $declared = collect($tools->declarations($user))->keyBy('name');
        $this->assertSame(['pending', 'accepted', 'refused', 'all'], $declared['get_my_leave_requests']['parameters']['properties']['status']['enum']);
    }

    public function test_my_items_lists_the_employees_own_items_behind_the_page_permission(): void
    {
        $user = $this->employeeUser(['checklists.view-own']);
        $laptop = ChecklistItem::query()->firstOrCreate(['name' => 'Laptop'], ['order' => 1]);
        $phone = ChecklistItem::query()->firstOrCreate(['name' => 'Phone'], ['order' => 2]);
        $other = $this->makeEmployee($this->city, 'colleague');

        Checklist::query()->create(['item_id' => $laptop->id, 'employee_id' => $user->profile->id, 'brand' => 'Dell', 'serial' => 'SN-1', 'delivered_status' => 'delivered', 'delivered_at' => '2026-02-01']);
        Checklist::query()->create(['item_id' => $phone->id, 'employee_id' => $user->profile->id, 'delivered_status' => 'pending']);
        Checklist::query()->create(['item_id' => $phone->id, 'employee_id' => $other->id, 'serial' => 'THEIRS', 'delivered_status' => 'delivered']);

        $tools = app(EmployeeTools::class);
        $result = $tools->call($user, 'get_my_items');

        $this->assertCount(2, $result['items']);
        $byItem = collect($result['items'])->keyBy('item');
        $this->assertSame('delivered', $byItem['Laptop']['status']);
        $this->assertSame('Dell', $byItem['Laptop']['brand']);
        $this->assertSame('2026-02-01', $byItem['Laptop']['delivered_at']);
        $this->assertSame('on hold', $byItem['Phone']['status']);
        $this->assertStringNotContainsString('THEIRS', json_encode($result));

        $without = $this->employeeUser([], 'without-items');
        $this->assertNotContains('get_my_items', collect($tools->declarations($without))->pluck('name'));
        $this->assertArrayHasKey('error', $tools->call($without, 'get_my_items'));
    }

    public function test_holidays_are_those_of_the_employees_project_in_the_window(): void
    {
        $user = $this->employeeUser();
        $contract = $this->makeContractFor($user->profile);
        $otherCity = $this->makeCity('HMS', '002');

        Holiday::query()->create(['name_en' => 'Independence Day', 'name_ar' => 'عيد الاستقلال', 'start_date' => '2026-11-17', 'end_date' => '2026-11-18', 'scope' => Holiday::SCOPE_NATIONAL, 'kind' => Holiday::KIND_OFFICIAL]);
        Holiday::query()->create(['name_en' => 'Far Away', 'name_ar' => 'بعيد', 'start_date' => '2027-03-01', 'end_date' => '2027-03-01', 'scope' => Holiday::SCOPE_NATIONAL, 'kind' => Holiday::KIND_OFFICIAL]);
        $mine = Holiday::query()->create(['name_en' => 'Aleppo Project Day', 'name_ar' => 'يوم حلب', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'scope' => Holiday::SCOPE_PROJECTS, 'kind' => Holiday::KIND_GRANT]);
        HolidayProject::query()->create(['holiday_id' => $mine->id, 'program_id' => $contract->position->program_id, 'city_id' => $this->city->id]);
        $theirs = Holiday::query()->create(['name_en' => 'Homs Project Day', 'name_ar' => 'يوم حمص', 'start_date' => '2026-11-21', 'end_date' => '2026-11-21', 'scope' => Holiday::SCOPE_PROJECTS, 'kind' => Holiday::KIND_GRANT]);
        HolidayProject::query()->create(['holiday_id' => $theirs->id, 'program_id' => $contract->position->program_id, 'city_id' => $otherCity->id]);
        Holiday::query()->create(['name_en' => 'Birthday', 'name_ar' => 'عيد ميلاد', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'scope' => Holiday::SCOPE_NATIONAL, 'kind' => Holiday::KIND_BIRTHDAY]);

        $tools = app(EmployeeTools::class);
        $this->assertContains('get_holidays', collect($tools->declarations($user))->pluck('name'));

        $result = $tools->call($user, 'get_holidays');

        $this->assertSame('2026-11-02', $result['from']);
        $this->assertSame('2027-01-01', $result['to']);
        $this->assertSame(['Independence Day', 'Aleppo Project Day'], array_column($result['holidays'], 'name'));
        $this->assertSame(['from' => '2026-11-17', 'to' => '2026-11-18', 'days' => 2], collect($result['holidays'][0])->only('from', 'to', 'days')->all());

        $wide = $tools->call($user, 'get_holidays', ['days' => 366]);
        $this->assertContains('Far Away', array_column($wide['holidays'], 'name'));

        // Clamped: 10 000 days is a year, 0 days is the default.
        $this->assertSame('2027-11-03', $tools->call($user, 'get_holidays', ['days' => 10000])['to']);
        $this->assertSame('2027-01-01', $tools->call($user, 'get_holidays', ['days' => 0])['to']);
    }

    public function test_news_lists_the_latest_published_items_behind_news_view(): void
    {
        $user = $this->employeeUser(['news.view']);

        foreach (range(1, 7) as $i) {
            News::query()->create(['title_en' => "News {$i}", 'title_ar' => "خبر {$i}", 'description_en' => str_repeat('word ', 100), 'description_ar' => '', 'event_date' => sprintf('2026-10-%02d', $i), 'published_at' => now()]);
        }
        News::query()->where('title_en', 'News 7')->sole()->syncTags(['Aleppo', 'Training']);
        News::query()->create(['title_en' => 'Draft', 'title_ar' => 'مسودة', 'description_ar' => '', 'event_date' => '2026-10-20', 'published_at' => null]);
        News::query()->create(['title_en' => 'Expired', 'title_ar' => 'منتهٍ', 'description_ar' => '', 'event_date' => '2026-10-21', 'published_at' => now(), 'display_until' => now()->subDay()]);

        $tools = app(EmployeeTools::class);
        $result = $tools->call($user, 'get_news');

        $this->assertSame(['News 7', 'News 6', 'News 5', 'News 4', 'News 3'], array_column($result['news'], 'title'));
        $this->assertSame(now()->format('Y-m-d'), $result['news'][0]['published']);
        $this->assertSame('news', $result['news'][0]['type']);
        $this->assertArrayNotHasKey('event_date', $result['news'][0]);
        // Tags say what an item is about; an item without tags has no key.
        $this->assertSame(['Aleppo', 'Training'], $result['news'][0]['tags']);
        $this->assertArrayNotHasKey('tags', $result['news'][1]);
        $this->assertLessThanOrEqual(300, mb_strlen($result['news'][0]['excerpt']));

        $this->assertCount(2, $tools->call($user, 'get_news', ['limit' => 2])['news']);
        $this->assertCount(7, $tools->call($user, 'get_news', ['limit' => 50])['news']);

        $without = $this->employeeUser([], 'without-news');
        $this->assertNotContains('get_news', collect($tools->declarations($without))->pluck('name'));
        $this->assertArrayHasKey('error', $tools->call($without, 'get_news'));
    }

    public function test_app_pages_are_the_menu_the_role_may_open(): void
    {
        $tools = app(EmployeeTools::class);

        $user = $this->employeeUser(['leaves.view-own', 'leaves.create']);
        $result = $tools->call($user, 'get_app_pages');
        $urls = array_column($result['pages'], 'url');

        $this->assertContains(route('leaves.index'), $urls);
        $this->assertContains(route('dashboard'), $urls);
        $this->assertNotContains(route('employees.index'), $urls);
        $this->assertNotContains(route('news.index'), $urls);
        $this->assertSame('My Leaves', collect($result['pages'])->firstWhere('url', route('leaves.index'))['label']);
        $this->assertSame([route('leaves.index', ['new' => 1])], array_column($result['new_actions'], 'url'));

        $without = $this->employeeUser([], 'without-leaves');
        $this->assertContains('get_app_pages', collect($tools->declarations($without))->pluck('name'));
        $this->assertNotContains(route('leaves.index'), array_column($tools->call($without, 'get_app_pages')['pages'], 'url'));
        $this->assertSame([], $tools->call($without, 'get_app_pages')['new_actions']);
    }

    public function test_the_inbox_tool_shows_what_waits_on_the_user(): void
    {
        $user = $this->employeeUser(['checklists.deliver']);
        $holder = $this->makeEmployee($this->city, 'holder', ['first_name_en' => 'Hanna', 'last_name_en' => 'Holder']);
        $laptop = ChecklistItem::query()->firstOrCreate(['name' => 'Laptop'], ['order' => 1]);
        Checklist::query()->create(['item_id' => $laptop->id, 'employee_id' => $holder->id, 'delivered_id' => $user->profile->id, 'delivered_status' => 'pending', 'notes' => 'private note']);
        Checklist::query()->create(['item_id' => $laptop->id, 'employee_id' => $holder->id, 'delivered_id' => $this->makeEmployee($this->city, 'someone')->id, 'delivered_status' => 'pending']);

        $tools = app(EmployeeTools::class);
        $this->assertContains('get_my_inbox', collect($tools->declarations($user))->pluck('name'));

        $result = $tools->call($user, 'get_my_inbox');

        $this->assertSame(1, $result['counts']['all']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('Item to hand over', $result['rows'][0]['action']);
        $this->assertSame('Hanna Holder', $result['rows'][0]['who']);
        $this->assertSame('Laptop', $result['rows'][0]['what']);
        $this->assertSame(route('inbox'), $result['url']);
        $this->assertStringNotContainsString('private note', json_encode($result));

        $without = $this->employeeUser([], 'without-inbox');
        $this->assertNotContains('get_my_inbox', collect($tools->declarations($without))->pluck('name'));
        $this->assertArrayHasKey('error', $tools->call($without, 'get_my_inbox'));
    }

    public function test_an_unknown_tool_is_refused(): void
    {
        $user = $this->employeeUser();

        $this->assertSame(['error' => 'The tool drop_tables is not available to this employee.'], app(EmployeeTools::class)->call($user, 'drop_tables'));
    }

    private function employeeUser(array $permissions = [], string $role = 'employee-tools-staff'): User
    {
        $employee = $this->makeEmployee($this->city, 'me-'.$role);

        return $this->makeUser($this->city, $role, $permissions, $employee);
    }

    private function balanceOn($contract, string $type, float $deserved, float $used): LeaveBalance
    {
        $leaveType = LeaveType::query()->firstOrCreate(['name_en' => $type], ['name_ar' => $type, 'base_days' => 20]);

        return LeaveBalance::query()->create([
            'contract_id' => $contract->id,
            'type_id' => $leaveType->id,
            'deserved' => $deserved,
            'used' => $used,
        ]);
    }

    private function requestOn(LeaveBalance $balance, string $day, int $status): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'leave_balance_id' => $balance->id,
            'type' => 'day',
            'start_at' => $day,
            'end_at' => $day,
            'status' => $status,
        ]);
    }
}
