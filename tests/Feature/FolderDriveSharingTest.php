<?php

namespace Tests\Feature;

use App\Livewire\Archives\ArchivedFilesModule;
use App\Models\Department;
use App\Models\Folder;
use App\Models\FolderGroupShare;
use App\Models\User;
use App\Notifications\FolderShared;
use App\Services\Folders\FolderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Folders shared the Google Drive way (decided 2026-11-24): viewer / editor roles,
 * groups kept as groups, and subfolders taking their parent's access.
 */
class FolderDriveSharingTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const ARCHIVES = ['archives.view', 'archives.create', 'archives.update', 'archives.delete', 'archives.share', 'archives.download'];

    private Department $hr;

    private \App\Models\City $aleppo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-15 10:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->aleppo = $this->makeCity('ALP', '001');
        $this->hr = Department::query()->create(['code' => 'HR', 'name' => 'Human Resources']);
    }

    public function test_a_group_share_follows_who_is_in_the_group_now(): void
    {
        Notification::fake();
        $owner = $this->person();
        $member = $this->person($this->hr);
        $later = $this->person();
        $folder = $this->folder($owner, 'HR handbook');

        Livewire::actingAs($owner)->test(ArchivedFilesModule::class)
            ->call('editPermissions', $folder->id)
            ->set('groupType', 'department')
            ->set('groupValue', (string) $this->hr->id)
            ->call('addGroupShare')
            ->call('savePermissions')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('folder_group_shares', ['folder_id' => $folder->id, 'group_type' => 'department', 'group_id' => $this->hr->id, 'role' => 'viewer']);
        Notification::assertSentTo($member, FolderShared::class);

        $this->sees($member, 'HR handbook');
        $this->doesNotSee($later, 'HR handbook');

        // Joins HR next month: has access, with no new share.
        $this->makeContractFor($later->profile, ['position_id' => $this->makePosition($this->aleppo, ['department_id' => $this->hr->id])->id]);
        $this->sees($later, 'HR handbook');

        // Leaves HR (the contract ends): no access any more.
        $member->profile->contracts()->getQuery()->update(['end_date' => '2026-06-01']);
        $this->assertFalse($this->access()->canView($member->fresh(), $folder));
    }

    public function test_viewer_opens_editor_changes_and_shares_only_the_owner_deletes(): void
    {
        $owner = $this->person();
        $viewer = $this->person();
        $editor = $this->person();
        $folder = $this->folder($owner, 'Projects');
        $folder->users()->attach([$viewer->id => ['role' => 'viewer'], $editor->id => ['role' => 'editor']]);

        // The viewer: no menu, no sharing, no renaming.
        Livewire::withQueryParams(['scope' => 'shared'])->actingAs($viewer)->test(ArchivedFilesModule::class)
            ->assertSee('Projects')->assertSee(__('folders.roles.viewer'))
            ->assertDontSeeHtml('editPermissions('.$folder->id.')');
        Livewire::withQueryParams([])->actingAs($viewer)->test(ArchivedFilesModule::class)
            ->call('editPermissions', $folder->id)->assertForbidden();
        Livewire::actingAs($viewer)->test(ArchivedFilesModule::class)
            ->call('startEditingFolder', $folder->id)->assertForbidden();

        // The editor: renames, makes a subfolder inside, shares; may not delete.
        Livewire::actingAs($editor)->test(ArchivedFilesModule::class)
            ->call('startEditingFolder', $folder->id)->set('editingFolderName', 'Projects 2026')->call('saveEditedFolder')
            ->assertHasNoErrors();
        $this->assertSame('Projects 2026', $folder->fresh()->name);

        Livewire::actingAs($editor)->test(ArchivedFilesModule::class, ['folderId' => $folder->id])
            ->set('newFolderName', 'Q3')->call('createFolder')->assertHasNoErrors();
        $sub = Folder::query()->where('name', 'Q3')->firstOrFail();
        $this->assertSame($folder->id, $sub->parent_id);

        Livewire::actingAs($editor)->test(ArchivedFilesModule::class)
            ->call('editPermissions', $folder->id)->assertSet('showPermissionsModal', true);
        Livewire::actingAs($editor)->test(ArchivedFilesModule::class)
            ->call('confirmFolderDeletion', $folder->id)->assertForbidden();

        // The owner of the parent owns what is inside it too, and deletes.
        $this->assertSame(FolderAccess::OWNER, $this->access()->role($owner, $sub));
        Livewire::actingAs($owner)->test(ArchivedFilesModule::class)
            ->call('confirmFolderDeletion', $folder->id)->assertDispatched('confirm-dialog');
    }

    public function test_a_subfolder_takes_its_parents_access_unless_it_was_narrowed(): void
    {
        $owner = $this->person();
        $guest = $this->person();
        $folder = $this->folder($owner, 'Root');
        $folder->users()->attach($guest->id, ['role' => 'viewer']);

        $open = Folder::query()->create(['name' => 'Open', 'parent_id' => $folder->id, 'user_id' => $owner->id]);
        $narrowed = Folder::query()->create(['name' => 'Private', 'parent_id' => $folder->id, 'user_id' => $owner->id, 'inherits_access' => false]);

        $this->assertTrue($this->access()->canView($guest, $open));
        $this->assertFalse($this->access()->canView($guest, $narrowed));

        Livewire::actingAs($guest)->test(ArchivedFilesModule::class, ['folderId' => $folder->id])
            ->assertSee('Open')->assertDontSee('Private');
    }

    public function test_a_folder_shared_on_its_own_does_not_show_the_names_above_it(): void
    {
        $owner = $this->person();
        $guest = $this->person();
        $top = $this->folder($owner, 'Board minutes');
        $shared = Folder::query()->create(['name' => 'Public summary', 'parent_id' => $top->id, 'user_id' => $owner->id]);
        $shared->users()->attach($guest->id, ['role' => 'viewer']);

        $page = Livewire::withQueryParams(['scope' => 'shared'])->actingAs($guest)->test(ArchivedFilesModule::class, ['folderId' => $shared->id]);

        $labels = collect($page->get('finalCrumbs'))->pluck('label')->all();
        $this->assertContains('Public summary', $labels);
        $this->assertNotContains('Board minutes', $labels);
    }

    public function test_shared_with_me_explains_where_to_make_a_folder(): void
    {
        $guest = $this->person();

        Livewire::withQueryParams(['scope' => 'shared'])->actingAs($guest)->test(ArchivedFilesModule::class)
            ->assertSeeHtml('data-testid="shared-root-hint"')
            ->assertSee(__('folders.shared-root-hint'));

        Livewire::withQueryParams([])->actingAs($guest)->test(ArchivedFilesModule::class)
            ->assertDontSeeHtml('data-testid="shared-root-hint"');
    }

    public function test_roles_and_groups_cannot_be_given_outside_the_list(): void
    {
        $owner = $this->person();
        $guest = $this->person();
        $folder = $this->folder($owner, 'Docs');

        Livewire::actingAs($owner)->test(ArchivedFilesModule::class)
            ->call('editPermissions', $folder->id)
            ->call('toggleUser', $guest->id)
            ->call('setUserRole', $guest->id, 'owner')      // not a share role: ignored
            ->set('groupType', 'department')
            ->set('groupValue', '999999')                     // no such group
            ->call('addGroupShare')
            ->assertHasErrors('groupValue')
            ->call('savePermissions');

        $this->assertSame('viewer', $folder->fresh()->users()->whereKey($guest->id)->first()->pivot->role);
        $this->assertSame(0, FolderGroupShare::query()->count());
    }

    public function test_the_upgrade_keeps_a_subfolder_narrowed_before_it_closed(): void
    {
        $owner = $this->person();
        $guest = $this->person();
        $root = $this->folder($owner, 'Root');
        $root->users()->attach($guest->id);
        // Made under the old rule: a copy of the parent list, then the guest taken off.
        $narrowed = Folder::query()->create(['name' => 'Narrowed', 'parent_id' => $root->id, 'user_id' => $owner->id]);
        $copied = Folder::query()->create(['name' => 'Copied', 'parent_id' => $root->id, 'user_id' => $owner->id]);
        $copied->users()->attach($guest->id);

        $migration = require database_path('migrations/2026_11_30_000001_add_roles_and_group_shares_to_folders.php');
        $migration->down();
        $migration->up();

        $this->assertFalse((bool) $narrowed->fresh()->inherits_access);
        $this->assertTrue((bool) $copied->fresh()->inherits_access);
        $this->assertFalse($this->access()->canView($guest, $narrowed->fresh()));
        $this->assertTrue($this->access()->canView($guest, $copied->fresh()));
        $this->assertSame('viewer', $root->fresh()->users()->whereKey($guest->id)->first()->pivot->role);
    }

    public function test_a_folder_can_be_shared_with_chosen_people_and_no_group(): void
    {
        Notification::fake();
        $owner = $this->person();
        // Different departments, none of them in a group the folder is shared with.
        $a = $this->person($this->hr);
        $b = $this->person();
        $stranger = $this->person();
        $folder = $this->folder($owner, 'Hand picked');

        Livewire::actingAs($owner)->test(ArchivedFilesModule::class)
            ->call('editPermissions', $folder->id)
            ->call('toggleUser', $a->id)
            ->call('toggleUser', $b->id)
            ->call('setUserRole', $b->id, 'editor')
            ->call('savePermissions')
            ->assertHasNoErrors();

        $this->assertSame(0, FolderGroupShare::query()->count());
        $this->assertSame('viewer', $folder->fresh()->users()->whereKey($a->id)->first()->pivot->role);
        $this->assertSame('editor', $folder->fresh()->users()->whereKey($b->id)->first()->pivot->role);
        Notification::assertSentTo([$a, $b], FolderShared::class);
        Notification::assertNotSentTo($stranger, FolderShared::class);
        $this->sees($a, 'Hand picked');
        $this->doesNotSee($stranger, 'Hand picked');
    }

    public function test_the_dialog_names_the_people_in_a_group_and_tags_those_it_reaches(): void
    {
        $owner = $this->person();
        $member = $this->person($this->hr);
        $other = $this->person();
        $folder = $this->folder($owner, 'Team');

        $page = Livewire::actingAs($owner)->test(ArchivedFilesModule::class)
            ->call('editPermissions', $folder->id)
            ->set('groupType', 'department')
            ->set('groupValue', (string) $this->hr->id)
            ->call('addGroupShare');

        $names = collect($page->viewData('groupMembers')[0])->pluck('name')->all();
        $this->assertSame([$member->profile->name], $names);
        $this->assertSame([$this->hr->name], $page->viewData('viaGroups')[$member->id]);
        $this->assertArrayNotHasKey($other->id, $page->viewData('viaGroups'));

        $page->assertSee(__('folders.share.through-group', ['group' => $this->hr->name]))
            ->assertSee(__('folders.share.people-any'));
    }

    private function person(?Department $department = null): User
    {
        $user = $this->makeUser($this->aleppo, 'employee', self::ARCHIVES);

        if ($department) {
            $this->makeContractFor($user->profile, ['position_id' => $this->makePosition($this->aleppo, ['department_id' => $department->id])->id]);
        }

        return $user->fresh();
    }

    private function folder(User $owner, string $name): Folder
    {
        return Folder::query()->create(['name' => $name, 'user_id' => $owner->id]);
    }

    private function sees(User $user, string $name): void
    {
        app()->forgetScopedInstances(); // each look is a new request: groups are read again
        Livewire::withQueryParams(['scope' => 'shared'])->actingAs($user->fresh())->test(ArchivedFilesModule::class)->assertSee($name);
        Livewire::withQueryParams([]);
    }

    private function doesNotSee(User $user, string $name): void
    {
        app()->forgetScopedInstances();
        Livewire::withQueryParams(['scope' => 'shared'])->actingAs($user->fresh())->test(ArchivedFilesModule::class)->assertDontSee($name);
        Livewire::withQueryParams([]);
    }

    private function access(): FolderAccess
    {
        return new FolderAccess;
    }
}
