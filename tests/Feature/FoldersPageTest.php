<?php

namespace Tests\Feature;

use App\Livewire\Archives\ArchivedFilesModule;
use App\Models\City;
use App\Models\Folder;
use App\Notifications\FolderShared;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * My folders and Shared with me are one page (folders.index); the mode is
 * ?scope=shared. The two old pages redirect, because the FolderShared
 * notifications already stored carry their URLs.
 */
class FoldersPageTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $aleppo;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->aleppo = $this->makeCity('ALP', '001');
    }

    public function test_the_old_pages_redirect_to_the_folders_page_keeping_the_mode_and_folder(): void
    {
        $user = $this->makeUser($this->aleppo, 'employee', ['archives.view']);

        $this->actingAs($user)->get(route('shared-folders.index', ['folderId' => 7]))
            ->assertRedirect(ArchivedFilesModule::folderUrl(7, true));
        $this->actingAs($user)->get(route('shared-folders.index'))
            ->assertRedirect(ArchivedFilesModule::folderUrl(null, true));
        $this->actingAs($user)->get(route('my-folders.index', ['folderId' => 7]))
            ->assertRedirect(ArchivedFilesModule::folderUrl(7));

        $this->assertStringEndsWith('/folders/7?scope=shared', ArchivedFilesModule::folderUrl(7, true));
        $this->assertStringEndsWith('/folders', ArchivedFilesModule::folderUrl());
    }

    public function test_the_mode_comes_from_the_scope_parameter_and_lists_by_ownership(): void
    {
        $user = $this->makeUser($this->aleppo, 'employee', ['archives.view']);
        $other = $this->makeUser($this->aleppo, 'employee', ['archives.view']);

        $mine = Folder::query()->create(['name' => 'Mine', 'user_id' => $user->id, 'is_shared' => false]);
        $mine->users()->attach($user->id);
        // Someone else's private folder, shared with this user: "Shared with me".
        $theirs = Folder::query()->create(['name' => 'Theirs', 'user_id' => $other->id, 'is_shared' => false]);
        $theirs->users()->attach([$other->id, $user->id]);

        Livewire::actingAs($user)->test(ArchivedFilesModule::class)
            ->assertSet('isShared', false)
            ->assertSee('Mine')->assertDontSee('Theirs');

        Livewire::withQueryParams(['scope' => 'shared'])->actingAs($user)->test(ArchivedFilesModule::class)
            ->assertSet('isShared', true)
            ->assertSee('Theirs')->assertDontSee('>Mine<', false)
            ->assertSee(ArchivedFilesModule::folderUrl($theirs->id, true), false);
    }

    public function test_new_folder_is_shown_disabled_with_the_reason_in_shared_with_me(): void
    {
        $user = $this->makeUser($this->aleppo, 'employee', ['archives.view']);

        Livewire::withQueryParams(['scope' => 'shared'])->actingAs($user)->test(ArchivedFilesModule::class)
            ->assertSeeHtml('aria-disabled="true" aria-describedby="new-folder-locked"')
            ->assertSee(__('general.folders-new-locked'))
            ->assertDontSeeHtml("\$set('showCreateFolderModal', true)");

        // withQueryParams() outlives the test() call; reset it to the plain page.
        Livewire::withQueryParams([])->actingAs($user)->test(ArchivedFilesModule::class)
            ->assertSeeHtml("\$set('showCreateFolderModal', true)")
            ->assertDontSee(__('general.folders-new-locked'));
    }

    public function test_the_mode_cannot_be_changed_from_the_browser(): void
    {
        $user = $this->makeUser($this->aleppo, 'employee', ['archives.view']);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($user)->test(ArchivedFilesModule::class)->set('isShared', true);
    }

    public function test_a_new_share_notification_links_to_shared_with_me(): void
    {
        $owner = $this->makeUser($this->aleppo, 'employee', ['archives.view']);
        $folder = Folder::query()->create(['name' => 'Policies', 'user_id' => $owner->id, 'is_shared' => false]);

        $data = (new FolderShared($folder, 'Owner'))->toArray($owner);

        $this->assertSame(ArchivedFilesModule::folderUrl($folder->id, true), $data['url']);
    }
}
