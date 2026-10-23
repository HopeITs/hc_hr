<?php

namespace Tests\Feature\Livewire;

use App\Enums\LeaveRequestStatus;
use App\Livewire\ChecklistItems\AllItems;
use App\Livewire\ChecklistItems\PendingItems;
use App\Livewire\Employee\Leave\AllLeaveRequestModule;
use App\Livewire\Employee\Leave\AllLeaveRequestTable;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Livewire\Employee\Leave\MyLeaveRequestTable;
use App\Livewire\Notifications\Counter;
use App\Livewire\Notifications\Dropdown;
use App\Livewire\Notifications\Index;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\User;
use App\Services\LeaveAlternativeEmployees;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/** B3: leave, checklist and notification screens tell loading, empty, filtered-empty and failed apart. */
class LeaveChecklistNotificationStatesTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    // --- Leave ---------------------------------------------------------------

    public function test_my_leave_empty_state_offers_request_only_with_create_permission(): void
    {
        $city = $this->makeCity('DMS', '003');
        $may = $this->makeUser($city, 'employee', ['leaves.create']);
        // Roles are shared by name, so the read-only user needs a role of its own.
        $mayNot = $this->makeUser($city, 'staff-readonly', []);

        Livewire::actingAs($may)->test(MyLeaveRequestTable::class)
            ->assertSeeHtml('data-state="empty"')
            ->assertSee(__('states.leave-empty-title'))
            ->assertSee(__('leaves.modal.new-title'))
            ->assertSeeHtml('openRequestLeaveCreate')
            ->assertDontSeeHtml('data-state="filtered"');

        Livewire::actingAs($mayNot)->test(MyLeaveRequestTable::class)
            ->assertSeeHtml('data-state="empty"')
            ->assertDontSee(__('leaves.modal.new-title'))
            ->assertDontSeeHtml('openRequestLeaveCreate');
    }

    public function test_my_leave_page_header_button_follows_the_same_permission(): void
    {
        $city = $this->makeCity('DMS', '003');

        Livewire::actingAs($this->makeUser($city, 'employee', ['leaves.create']))
            ->test(MyLeaveRequestModule::class)->assertSeeHtml('wire:click="create"');
        Livewire::actingAs($this->makeUser($city, 'staff-readonly', []))
            ->test(MyLeaveRequestModule::class)->assertDontSeeHtml('wire:click="create"');
    }

    public function test_my_leave_create_is_refused_on_the_server_without_permission(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'staff-readonly', []);

        Livewire::actingAs($user)->test(MyLeaveRequestModule::class)
            ->call('create')
            ->assertForbidden();
    }

    public function test_my_leave_filtered_empty_is_not_the_empty_state_and_clear_restores_rows(): void
    {
        [$user, $request] = $this->leaveFixture();
        $row = 'wire:key="row-'.$request.'"';

        $table = Livewire::actingAs($user)->test(MyLeaveRequestTable::class)
            ->assertSeeHtml($row)
            ->assertDontSeeHtml('data-state="empty"');

        // Search that matches nothing.
        $table->set('globalSearch', 'NoSuchLeaveTypeAnywhere')
            ->assertSeeHtml('data-state="filtered"')
            ->assertDontSeeHtml('data-state="empty"')
            ->assertDontSee(__('leaves.modal.new-title'))
            ->assertSee(__('general.clear-filters'))
            ->assertDontSeeHtml($row)
            ->call('clearAllFilters')
            ->assertSet('globalSearch', '')
            ->assertSeeHtml($row)
            ->assertDontSeeHtml('data-state="filtered"');

        // A status tab that hides every row is a filter too, cleared through the parent.
        Livewire::actingAs($user)->test(MyLeaveRequestTable::class, ['statusFilter' => (string) LeaveRequestStatus::Rejected])
            ->assertSeeHtml('data-state="filtered"')
            ->assertSee(__('states.leave-tab-empty-title'))
            ->call('clearAllFilters')
            ->assertDispatched('leaveStatusFilterCleared');
    }

    public function test_status_tab_clear_event_resets_the_module_tab(): void
    {
        [$user] = $this->leaveFixture();

        Livewire::actingAs($user)->test(MyLeaveRequestModule::class)
            ->call('setActiveTab', (string) LeaveRequestStatus::Rejected)
            ->assertSet('statusFilter', (string) LeaveRequestStatus::Rejected)
            ->dispatch('leaveStatusFilterCleared')
            ->assertSet('statusFilter', 'all');
    }

    public function test_hr_all_leaves_empty_state_and_add_action_by_role(): void
    {
        $city = $this->makeCity('DMS', '003');
        $hr = $this->makeUser($city, 'HR-DMS', ['leaves.view-all', 'leaves.create']);
        $manager = $this->makeUser($city, 'manager', ['leaves.view-all']);

        Livewire::actingAs($hr)->test(AllLeaveRequestTable::class)
            ->assertSeeHtml('data-state="empty"')
            ->assertSee(__('states.leave-all-empty-title'))
            ->assertSee(__('general.add-new'))
            ->assertSeeHtml('openAllLeaveCreate');

        Livewire::actingAs($manager)->test(AllLeaveRequestTable::class)
            ->assertSeeHtml('data-state="empty"')
            ->assertDontSee(__('general.add-new'));
    }

    public function test_all_leaves_create_needs_permission_and_hr_role_on_the_server(): void
    {
        $city = $this->makeCity('DMS', '003');

        Livewire::actingAs($this->makeUser($city, 'manager', ['leaves.create']))
            ->test(AllLeaveRequestModule::class)->call('create')->assertForbidden();
        Livewire::actingAs($this->makeUser($city, 'HR-DMS', []))
            ->test(AllLeaveRequestModule::class)->call('create')->assertForbidden();
        Livewire::actingAs($this->makeUser($city, 'HR-DMS', ['leaves.create']))
            ->test(AllLeaveRequestModule::class)->call('create')->assertSet('formModal', true);
    }

    public function test_leave_list_failure_shows_retry_hides_details_and_recovers(): void
    {
        Exceptions::fake();
        [$user] = $this->leaveFixture();
        FailingMyLeaveTable::$broken = true;

        $table = Livewire::actingAs($user)->test(FailingMyLeaveTable::class)
            ->assertSet('loadFailed', true)
            ->assertSee(__('general.state-error-title'))
            ->assertSee(__('general.retry'))
            ->assertDontSee('SECRET-SQL-DETAIL')
            ->assertDontSeeHtml('data-state="empty"');

        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'SECRET-SQL-DETAIL'));

        FailingMyLeaveTable::$broken = false;
        $table->call('retryLoad')
            ->assertSet('loadFailed', false)
            ->assertDontSee(__('general.state-error-title'))
            ->assertDontSeeHtml('data-state="empty"');
    }

    public function test_balance_lookup_failure_shows_retry_instead_of_a_500(): void
    {
        Exceptions::fake();
        [$user] = $this->leaveFixture();
        $this->app->bind(LeaveAlternativeEmployees::class, fn () => throw new RuntimeException('SECRET-SQL-DETAIL'));

        $page = Livewire::actingAs($user)->test(MyLeaveRequestModule::class)
            ->assertSet('loadFailed', true)
            ->assertSee(__('states.balances-unavailable'))
            ->assertDontSee('SECRET-SQL-DETAIL');

        $this->app->bind(LeaveAlternativeEmployees::class);
        $page->call('retryLoad')
            ->assertSet('loadFailed', false)
            ->assertDontSee(__('states.balances-unavailable'));
    }

    public function test_leave_lists_never_show_the_reason(): void
    {
        [$user] = $this->leaveFixture();

        Livewire::actingAs($user)->test(MyLeaveRequestTable::class)
            ->assertDontSee('PRIVATE-LEAVE-REASON');
    }

    // --- Checklist -----------------------------------------------------------

    public function test_checklist_screens_show_empty_then_rows_and_fail_with_retry(): void
    {
        Exceptions::fake();
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'employee', ['checklists.view-own', 'checklists.deliver']);

        Livewire::actingAs($user)->test(AllItems::class)
            ->assertSeeHtml('data-state="empty"')
            ->assertSee(__('states.checklist-empty-title'));

        Livewire::actingAs($user)->test(PendingItems::class)
            ->assertSeeHtml('data-state="empty"')
            ->assertSee(__('states.pending-delivers-empty-title'));

        $item = ChecklistItem::query()->create(['name' => 'Laptop', 'order' => 1]);
        Checklist::query()->create([
            'item_id' => $item->id, 'employee_id' => $user->employee_id, 'delivered_id' => $user->employee_id,
            'delivered_status' => 'pending', 'returned_status' => 'pending',
        ]);

        Livewire::actingAs($user)->test(PendingItems::class)
            ->assertDontSeeHtml('data-state="empty"')
            ->assertSee('Laptop');

        FailingAllItems::$broken = true;
        $failing = Livewire::actingAs($user)->test(FailingAllItems::class)
            ->assertSet('loadFailed', true)
            ->assertSee(__('general.state-error-title'))
            ->assertDontSee('SECRET-SQL-DETAIL')
            ->assertDontSeeHtml('data-state="empty"');

        FailingAllItems::$broken = false;
        $failing->call('retryLoad')->assertSet('loadFailed', false)->assertSee('Laptop');
    }

    public function test_repeated_retry_on_the_checklist_never_writes(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'employee', ['checklists.deliver']);
        $item = ChecklistItem::query()->create(['name' => 'Phone', 'order' => 1]);
        $row = Checklist::query()->create([
            'item_id' => $item->id, 'employee_id' => $user->employee_id, 'delivered_id' => $user->employee_id,
            'delivered_status' => 'pending', 'returned_status' => 'pending',
        ]);

        Livewire::actingAs($user)->test(PendingItems::class)
            ->call('retryLoad')->call('retryLoad')->call('retryLoad');

        $this->assertSame('pending', $row->fresh()->delivered_status);
        $this->assertNull($row->fresh()->delivered_at);
    }

    // --- Notifications -------------------------------------------------------

    public function test_notifications_empty_state_in_page_and_dropdown(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'employee');

        Livewire::actingAs($user)->test(Index::class)
            ->assertSeeHtml('data-state="empty"')
            ->assertSee(__('general.no-notifications'))
            ->assertSee(trans_choice('states.notifications-summary', 0));

        Livewire::actingAs($user)->test(Dropdown::class)
            ->assertSeeHtml('data-state="empty"')
            ->assertSee(__('general.no-notifications'));
    }

    public function test_notifications_announce_the_unread_total(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'employee');
        $this->notify($user);
        $this->notify($user);

        Livewire::actingAs($user)->test(Index::class)
            ->assertSeeHtml('role="status"')
            ->assertSee(trans_choice('states.notifications-summary', 2))
            ->call('markAllAsRead')
            ->assertSee(trans_choice('states.notifications-summary', 0));
    }

    public function test_notification_load_failure_shows_retry_and_retry_never_marks_read(): void
    {
        Exceptions::fake();
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'employee');
        $id = $this->notify($user);
        FailingNotificationsIndex::$broken = true;
        FailingNotificationsDropdown::$broken = true;

        $page = Livewire::actingAs($user)->test(FailingNotificationsIndex::class)
            ->assertSet('loadFailed', true)
            ->assertSee(__('general.state-error-title'))
            ->assertDontSee('SECRET-SQL-DETAIL')
            ->assertDontSeeHtml('data-state="empty"');
        $panel = Livewire::actingAs($user)->test(FailingNotificationsDropdown::class)
            ->assertSet('loadFailed', true)
            ->assertSee(__('general.retry'))
            ->assertDontSeeHtml('data-state="empty"');

        // Retrying while still broken, several times: nothing is written.
        foreach (range(1, 3) as $attempt) {
            $page->call('retryLoad')->assertSet('loadFailed', true);
            $panel->call('retryLoad')->assertSet('loadFailed', true);
        }
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));

        FailingNotificationsIndex::$broken = false;
        FailingNotificationsDropdown::$broken = false;
        $page->call('retryLoad')->assertSet('loadFailed', false)->assertSee('Test notification');
        $panel->call('retryLoad')->assertSet('loadFailed', false)->assertSee('Test notification');
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_mark_read_twice_is_a_single_write(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'employee');
        $id = $this->notify($user);

        $page = Livewire::actingAs($user)->test(Index::class)->call('markAsRead', $id);
        $first = DB::table('notifications')->where('id', $id)->value('read_at');
        $this->travel(5)->minutes();
        $page->call('markAsRead', $id)->call('retryLoad');

        $this->assertSame($first, DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    public function test_only_the_header_counter_polls(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'employee');
        $this->notify($user);

        Livewire::actingAs($user)->test(Counter::class)
            ->assertSeeHtml('wire:poll.10s="updateCount"')
            ->assertSet('count', 1);

        // The sidebar copy follows events instead of polling a second time.
        $follower = Livewire::actingAs($user)->test(Counter::class, ['poll' => false])
            ->assertDontSeeHtml('wire:poll')
            ->assertSet('count', 1);

        $this->notify($user);
        $follower->dispatch('notif-arrived')->assertSet('count', 2)->assertNotDispatched('notif-arrived');
    }

    public function test_layout_does_not_add_a_second_poll_to_the_notification_components(): void
    {
        $views = resource_path('views/components/layouts/app');

        $this->assertStringNotContainsString('wire:poll', file_get_contents($views.'/notification.blade.php'));
        $this->assertStringNotContainsString('wire:poll', file_get_contents($views.'/sidebar/profile-card.blade.php'));
    }

    // --- helpers -------------------------------------------------------------

    private function notify(User $user): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => ['en' => 'Test notification', 'ar' => 'إشعار'], 'body' => ['en' => 'Body', 'ar' => 'نص']]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array{0: User, 1: int} the employee user and the id of their one Accepted request */
    private function leaveFixture(): array
    {
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'employee', ['leaves.create']);
        $contract = $this->makeContractFor($user->profile);

        $typeId = DB::table('leave_types')->insertGetId([
            'name_en' => 'Annual Leave', 'name_ar' => 'إجازة سنوية', 'base_days' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $balanceId = DB::table('leave_balances')->insertGetId([
            'contract_id' => $contract->id, 'type_id' => $typeId, 'deserved' => 160, 'used' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $requestId = DB::table('leave_requests')->insertGetId([
            'leave_balance_id' => $balanceId, 'type' => 'day',
            'start_at' => now()->addWeek()->startOfDay(), 'end_at' => now()->addWeek()->addDay()->startOfDay(),
            'reason' => 'PRIVATE-LEAVE-REASON', 'status' => LeaveRequestStatus::Accepted,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $requestId];
    }
}

class FailingMyLeaveTable extends MyLeaveRequestTable
{
    public static bool $broken = true;

    protected function query(): Builder
    {
        if (self::$broken) {
            throw new RuntimeException('SECRET-SQL-DETAIL');
        }

        return parent::query();
    }
}

class FailingAllItems extends AllItems
{
    public static bool $broken = true;

    public function getItemsProperty()
    {
        if (self::$broken) {
            throw new RuntimeException('SECRET-SQL-DETAIL');
        }

        return parent::getItemsProperty();
    }
}

class FailingNotificationsIndex extends Index
{
    public static bool $broken = true;

    protected function requestStates(iterable $notifications): array
    {
        if (self::$broken) {
            throw new RuntimeException('SECRET-SQL-DETAIL');
        }

        return parent::requestStates($notifications);
    }
}

class FailingNotificationsDropdown extends Dropdown
{
    public static bool $broken = true;

    protected function requestStates(iterable $notifications): array
    {
        if (self::$broken) {
            throw new RuntimeException('SECRET-SQL-DETAIL');
        }

        return parent::requestStates($notifications);
    }
}
