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
