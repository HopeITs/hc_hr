<?php

namespace Tests\Feature;

use App\Livewire\Inbox\Index as Inbox;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\City;
use App\Models\User;
use App\Services\WorkInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * C3: checklist confirmations in the work inbox (approval-source-contract.md
 * §3.3 S3/S4, §6: the only bulk action, C3D-Q4). Synthetic data only.
 */
class WorkInboxChecklistTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $city;

    private User $deliverer;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->city = $this->makeCity('DMS', '003');
        $this->deliverer = $this->makeUser($this->city, 'employee', ['checklists.deliver', 'checklists.receive']);
    }

    /** AC-C3-01: rows and count are exactly the items waiting on this user. */
    public function test_rows_and_count_are_the_items_waiting_on_the_user(): void
    {
        $mine = $this->item('Laptop');
        $returned = $this->item('Phone', ['delivered_status' => 'delivered', 'receiver_id' => $this->deliverer->employee_id, 'returned_status' => 'pending']);
        $someoneElses = $this->item('Badge', ['delivered_id' => $this->makeEmployee($this->city, 'other.'.uniqid())->id]);
        $done = $this->item('Charger', ['delivered_status' => 'delivered']);

        $keys = app(WorkInbox::class)->rows($this->deliverer)->pluck('key')->all();

        $this->assertEqualsCanonicalizing(["checklist:{$mine->id}:deliver", "checklist:{$returned->id}:receive"], $keys);
        $this->assertSame(2, app(WorkInbox::class)->count($this->deliverer));

        // Without the page permission the item is not listed (the inbox widens nothing).
        $noPermission = $this->makeUser($this->city, 'manager', []);
        $this->item('Desk', ['delivered_id' => $noPermission->employee_id]);
        $this->assertSame(0, app(WorkInbox::class)->count($noPermission));

        $this->assertNotNull($someoneElses);
        $this->assertNotNull($done);
    }

    /** AC-C3-02: a repeated confirmation is "already handled" and writes nothing twice. */
    public function test_a_repeated_confirmation_is_already_handled(): void
    {
        $item = $this->item('Laptop');
        $key = "checklist:{$item->id}:deliver";

        $inbox = Livewire::actingAs($this->deliverer)->test(Inbox::class)
            ->set("notes.checklist-{$item->id}-deliver", 'Handed over at the center.')
            ->call('confirmItem', $key);

        $this->assertSame('delivered', $item->fresh()->delivered_status);
        $this->assertSame('Handed over at the center.', $item->fresh()->notes);

        // The same click again, e.g. after a lost response.
        $inbox->call('confirmItem', $key)
            ->assertSet("outcomes.checklist-{$item->id}-deliver.outcome", 'already_handled');

        $this->assertSame(1, Activity::query()->where('log_name', 'checklist')->where('description', 'checklist_delivered')->count());
    }

    public function test_someone_elses_item_is_not_allowed(): void
    {
        $other = $this->makeEmployee($this->city, 'other.'.uniqid());
        $item = $this->item('Laptop', ['delivered_id' => $other->id]);

        Livewire::actingAs($this->deliverer)->test(Inbox::class)
            ->call('confirmItem', "checklist:{$item->id}:deliver")
            ->assertSet("outcomes.checklist-{$item->id}-deliver.outcome", 'not_allowed');

        $this->assertSame('pending', $item->fresh()->delivered_status);
    }

    /** AC-C3-03: a batch previews without writing, applies item by item, reports each, and is traceable. */
    public function test_a_batch_confirms_each_item_by_its_owner_and_reports_per_item(): void
    {
        $a = $this->item('Laptop');
        $b = $this->item('Phone');
        $c = $this->item('Badge');
        $keys = ["checklist:{$a->id}:deliver", "checklist:{$b->id}:deliver", "checklist:{$c->id}:deliver"];

        $inbox = Livewire::actingAs($this->deliverer)->test(Inbox::class)
            ->set('selected', $keys)
            ->call('previewBatch')
            ->assertDispatched('confirm-dialog');

        // Preview writes nothing.
        $this->assertSame('pending', $a->fresh()->delivered_status);

        // Someone confirms one item on the old page between preview and confirm.
        app(\App\Services\ChecklistWorkflow::class)->markDelivered($c->id, $this->deliverer->employee_id, null);

        $inbox->call('applyBatch', $keys)
            ->assertDispatched('toast', fn ($event, $params) => str_contains($params['message'], '2'));

        $this->assertSame('delivered', $a->fresh()->delivered_status);
        $this->assertSame('delivered', $b->fresh()->delivered_status);

        // One audit entry per item, carrying the same batch id; the item confirmed elsewhere has none.
        $batchIds = Activity::query()->where('description', 'checklist_delivered')
            ->whereIn('subject_id', [$a->id, $b->id])->get()->map(fn ($log) => $log->properties['batch_id'] ?? null);
        $this->assertCount(2, $batchIds);
        $this->assertCount(1, $batchIds->unique());
        $this->assertNotNull($batchIds->first());
        $this->assertNull(Activity::query()->where('description', 'checklist_delivered')->where('subject_id', $c->id)->first()->properties['batch_id'] ?? null);
    }

    public function test_leave_rows_are_never_bulk(): void
    {
        $item = $this->item('Laptop');
        $rows = app(WorkInbox::class)->rows($this->deliverer);

        $this->assertTrue($rows->firstWhere('key', "checklist:{$item->id}:deliver")['bulk']);
        $this->assertTrue($rows->where('source', 'leave')->every(fn ($row) => $row['bulk'] === false));
    }

    /** G-C-6 (F4): a leaver's item stays in the inbox, marked, and agrees with the Hand out counter. */
    public function test_gc6_a_former_employees_item_is_listed_marked_and_counted_like_the_counter(): void
    {
        $item = $this->item('Laptop');
        $item->employee->update(['is_active' => false]);
        $key = "checklist:{$item->id}:deliver";

        $row = app(WorkInbox::class)->rows($this->deliverer)->firstWhere('key', $key);
        $this->assertNotNull($row);
        $this->assertTrue($row['former']);

        $counter = Livewire::actingAs($this->deliverer)->test(\App\Livewire\ChecklistItems\PendingDeliversCounter::class);
        $this->assertSame(app(WorkInbox::class)->count($this->deliverer), $counter->viewData('pendingDeliversCount'));

        app()->setLocale('en');
        Livewire::actingAs($this->deliverer)->test(Inbox::class)
            ->assertSeeHtml('data-testid="inbox-former"')
            ->assertSee('Former employee');
    }

    /** G-C-7 (F5): past the per-source cap the count stays exact and the page says it shows fewer. */
    public function test_gc7_the_count_is_exact_past_the_cap_and_the_page_says_so(): void
    {
        $holder = $this->makeEmployee($this->city, 'holder.'.uniqid());
        $itemId = ChecklistItem::query()->firstOrCreate(['name' => 'Cable'], ['order' => 1])->id;
        $total = WorkInbox::PER_SOURCE + 3;

        for ($i = 0; $i < $total; $i++) {
            Checklist::query()->create(['item_id' => $itemId, 'employee_id' => $holder->id, 'delivered_id' => $this->deliverer->employee_id, 'delivered_status' => 'pending']);
        }

        $snapshot = app(WorkInbox::class)->snapshot($this->deliverer);
        $this->assertCount(WorkInbox::PER_SOURCE, $snapshot['rows']);
        $this->assertSame($total, $snapshot['counts']['all']);
        $this->assertSame($total, app(WorkInbox::class)->count($this->deliverer));

        Livewire::actingAs($this->deliverer)->test(Inbox::class)
            ->assertViewHas('counts', fn (array $counts) => $counts['checklist'] === $total)
            ->assertSeeHtml('data-testid="inbox-capped"');
    }

    /** G-C-11 (D-05): an outcome that leaves the row (already handled) also tells the menu badge to recount. */
    public function test_gc11_an_already_handled_outcome_refreshes_the_menu_badge(): void
    {
        $item = $this->item('Laptop');
        app(\App\Services\ChecklistWorkflow::class)->markDelivered($item->id, $this->deliverer->employee_id, null);

        Livewire::actingAs($this->deliverer)->test(Inbox::class)
            ->call('confirmItem', "checklist:{$item->id}:deliver")
            ->assertSet("outcomes.checklist-{$item->id}-deliver.outcome", 'already_handled')
            ->assertDispatched('inbox-changed');
    }

    /** G-C-11 (F9): an employee-role deliverer's home shows the items the badge counts. */
    public function test_gc11_the_home_shows_checklist_rows_for_an_employee_role(): void
    {
        $this->item('Laptop');
        $this->item('Phone');
        app()->setLocale('en');

        Livewire::actingAs($this->deliverer)->test(\App\Livewire\Dashboard\ForYouNow::class)
            ->assertViewHas('parts', fn (array $parts) => ($parts['inbox-items']['count'] ?? null) === 2)
            ->assertSee('2 items to hand over or take back')
            ->assertDontSee(__('dashboard.for-you.empty'));
    }

    private function item(string $name, array $attributes = []): Checklist
    {
        $item = ChecklistItem::query()->firstOrCreate(['name' => $name], ['order' => 1]);
        $employee = $this->makeEmployee($this->city, 'holder.'.uniqid());

        return Checklist::query()->create(array_merge([
            'item_id' => $item->id,
            'employee_id' => $employee->id,
            'delivered_id' => $this->deliverer->employee_id,
            'delivered_status' => 'pending',
        ], $attributes));
    }
}
