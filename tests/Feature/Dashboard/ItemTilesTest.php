<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Archives\ArchivedFilesModule;
use App\Livewire\Dashboard\AddToDashboard;
use App\Livewire\Dashboard\Index;
use App\Livewire\Dashboard\ItemCard;
use App\Livewire\ProgramManagement\Program\Index as ProgramIndex;
use App\Models\City;
use App\Models\Folder;
use App\Models\SavedDashboardLayout;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * "Add to dashboard" on the remaining card grids (2026-11-04): a folder or a
 * file (My folders, Shared folders), a program, a centre ("item.<type>.<id>",
 * DashboardLayout::ITEMS), each behind its page's own rule.
 */
class ItemTilesTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $alp;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->alp = $this->makeCity('ALP', '001');
    }

    public function test_keys_are_read_strictly(): void
    {
        $layout = new DashboardLayout;

        $this->assertSame('item.folder.7', DashboardLayout::itemKey('folder', 7));
        $this->assertSame(['folder', 7], $layout->item('item.folder.7'));

        foreach (['item.salary.7', 'item.folder.0', 'item.folder.x', 'item.folder', 'item.folder.7.1', 'item.Folder.7'] as $key) {
            $this->assertNull($layout->item($key), $key);
        }
    }

    public function test_a_folder_card_is_for_the_people_the_folder_is_shared_with(): void
    {
        $layout = new DashboardLayout;
        $owner = $this->makeUser($this->alp, 'employee', ['archives.view']);
        $stranger = $this->makeUser($this->alp, 'manager', ['archives.view']);
        $noPermission = $this->makeUser($this->alp, 'HR-ALP', []);
        $folder = $this->folder($owner, 'Reports');
        $folder->users()->attach($noPermission->id);
        $key = DashboardLayout::itemKey('folder', $folder->id);

        $this->assertTrue($layout->allowed($owner, $key));
        $this->assertFalse($layout->allowed($stranger, $key));
        $this->assertFalse($layout->allowed($noPermission, $key), 'needs archives.view too');
        $this->assertSame(route('my-folders.index', ['folderId' => $folder->id]), $layout->page($owner, $key));

        Livewire::actingAs($stranger)->test(AddToDashboard::class, ['tile' => $key])->call('toggle')->assertForbidden();
    }

    public function test_a_file_card_follows_its_folder_and_the_pages_file_types(): void
    {
        $owner = $this->makeUser($this->alp, 'employee', ['archives.view']);
        $folder = $this->folder($owner, 'Reports');
        $pdf = $folder->addMedia(UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'))->toMediaCollection('archived_files');
        $script = $folder->addMedia(UploadedFile::fake()->create('evil.html', 1, 'text/html'))->toMediaCollection('archived_files');
        $layout = new DashboardLayout;

        $this->assertTrue($layout->allowed($owner, DashboardLayout::itemKey('file', $pdf->id)));
        $this->assertFalse($layout->allowed($owner, DashboardLayout::itemKey('file', $script->id)));

        // The folder card lists the folder's allowed files only.
        Livewire::actingAs($owner)->test(ItemCard::class, ['type' => 'folder', 'itemId' => $folder->id])
            // Named as the folder page names it (the media name, without its extension).
            ->assertSeeHtml('<b class="block truncate">plan</b>')
            ->assertDontSee('evil')
            ->assertSeeHtml(route('folder-files.preview', ['media' => $pdf->id]));
    }

    public function test_the_folder_page_offers_every_folder_and_file(): void
    {
        $owner = $this->makeUser($this->alp, 'employee', ['archives.view']);
        $a = $this->folder($owner, 'Alpha');
        $b = $this->folder($owner, 'Beta');

        Livewire::actingAs($owner)->test(ArchivedFilesModule::class)
            ->assertSeeHtml('atd-folder-'.$a->id)
            ->assertSeeHtml('atd-folder-'.$b->id);
    }

    public function test_a_program_and_a_centre_card_follow_their_reference_pages(): void
    {
        $contract = $this->makeContractFor($this->makeEmployee($this->alp, 'worker', ['is_active' => true]));
        $program = $contract->position->program;
        $center = $contract->position->center;
        $reader = $this->makeUser($this->alp, 'HR-ALP', ['programs.view', 'centers.view']);
        $staff = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $layout = new DashboardLayout;

        $this->assertTrue($layout->allowed($reader, DashboardLayout::itemKey('program', $program->id)));
        $this->assertTrue($layout->allowed($reader, DashboardLayout::itemKey('center', $center->id)));
        $this->assertFalse($layout->allowed($staff, DashboardLayout::itemKey('program', $program->id)));
        $this->assertFalse($layout->allowed($staff, DashboardLayout::itemKey('center', $center->id)));

        Livewire::actingAs($reader)->test(ItemCard::class, ['type' => 'program', 'itemId' => $program->id])
            ->assertSee($program->name)
            // No "Open" button on the card (2026-11-09): its page is the tile's own, opened from the dashboard.
            ->assertDontSeeHtml('href="'.route('programs.show', $program->slug).'"');
        $this->assertSame(route('programs.show', $program->slug), $layout->page($reader, DashboardLayout::itemKey('program', $program->id)));
        Livewire::actingAs($reader)->test(ItemCard::class, ['type' => 'center', 'itemId' => $center->id])
            ->assertSee($center->name)
            ->assertSee('ALP');
        Livewire::actingAs($staff)->test(ItemCard::class, ['type' => 'program', 'itemId' => $program->id])
            ->assertSee(__('dashboard.record.gone'))
            ->assertDontSee($program->name);

        Livewire::actingAs($reader)->test(ProgramIndex::class)->assertSeeHtml('atd-program-'.$program->id);
    }

    public function test_an_item_card_goes_on_the_dashboard_and_comes_off_again(): void
    {
        $owner = $this->makeUser($this->alp, 'employee', ['archives.view']);
        $key = DashboardLayout::itemKey('folder', $this->folder($owner, 'Reports')->id);

        $button = Livewire::actingAs($owner)->test(AddToDashboard::class, ['tile' => $key, 'compact' => true])->call('toggle');
        $this->assertSame($key, $this->saved($owner)[0]['key']);
        $this->assertStringContainsString('data-key="'.$key.'"', Livewire::actingAs($owner)->test(Index::class)->html());

        $button->call('toggle');
        $this->assertNotContains($key, array_column($this->saved($owner), 'key'));

        foreach (['tiles.item-folder', 'tiles.item-file', 'tiles.item-program', 'tiles.item-center', 'item.open', 'item.preview', 'item.download'] as $text) {
            foreach (['en', 'ar'] as $locale) {
                $this->assertNotSame('dashboard.'.$text, __('dashboard.'.$text, [], $locale), "{$text} {$locale}");
            }
        }
    }

    private function folder(User $owner, string $name): Folder
    {
        $folder = Folder::query()->create(['name' => $name, 'user_id' => $owner->id, 'is_shared' => false]);
        $folder->users()->attach($owner->id);

        return $folder;
    }

    /** @return array<int, array<string, mixed>> */
    private function saved(User $user): array
    {
        $layout = SavedDashboardLayout::query()->where('user_id', $user->id)->sole()->layout;

        return is_string($layout) ? json_decode($layout, true) : $layout;
    }
}
