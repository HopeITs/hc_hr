<?php

namespace Tests\Feature;

use App\Livewire\Archives\ArchivedFilesModule;
use App\Models\Department;
use App\Models\Folder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Sharing a folder "with a group": a filter (department, city, ...) narrows the
 * people list to who works there now, and "Select all visible" picks them.
 */
class FolderShareGroupTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    public function test_a_group_is_who_works_there_now_on_one_running_contract(): void
    {
        $this->travelTo('2026-06-15 10:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $aleppo = $this->makeCity('ALP', '001');
        $homs = $this->makeCity('HMS', '002');
        $hr = Department::query()->create(['code' => 'HR', 'name' => 'Human Resources']);
        $other = Department::query()->create(['code' => 'FIN', 'name' => 'Finance']);

        $owner = $this->makeUser($aleppo, 'employee', ['archives.view']);

        $inHrAleppo = $this->makeUser($aleppo, 'employee');
        $this->makeContractFor($inHrAleppo->profile, ['position_id' => $this->makePosition($aleppo, ['department_id' => $hr->id])->id]);

        // Left HR in Aleppo last year; works in Finance now.
        $leftHr = $this->makeUser($aleppo, 'employee');
        $this->makeContractFor($leftHr->profile, ['position_id' => $this->makePosition($aleppo, ['department_id' => $hr->id])->id, 'start_date' => '2024-01-01', 'end_date' => '2025-12-31']);
        $this->makeContractFor($leftHr->profile, ['position_id' => $this->makePosition($aleppo, ['department_id' => $other->id])->id]);

        // HR, but in Homs; and an old Aleppo contract in another department.
        $hrHoms = $this->makeUser($homs, 'employee');
        $this->makeContractFor($hrHoms->profile, ['position_id' => $this->makePosition($homs, ['department_id' => $hr->id])->id]);
        $this->makeContractFor($hrHoms->profile, ['position_id' => $this->makePosition($aleppo, ['department_id' => $other->id])->id, 'start_date' => '2024-01-01', 'end_date' => '2025-12-31']);

        // In HR Aleppo, but no longer with the organisation.
        $gone = $this->makeUser($aleppo, 'employee');
        $this->makeContractFor($gone->profile, ['position_id' => $this->makePosition($aleppo, ['department_id' => $hr->id])->id]);
        $gone->profile->forceFill(['is_active' => false])->save();

        $folder = Folder::query()->create(['name' => 'HR docs', 'user_id' => $owner->id, 'is_shared' => false]);
        $folder->users()->sync([$owner->id]);

        $page = Livewire::actingAs($owner)->test(ArchivedFilesModule::class)
            ->call('editPermissions', $folder->id)
            ->set('permissionFilterType', 'department')
            ->set('permissionFilterValue', (string) $hr->id)
            ->set('permissionFilterType', 'city')
            ->set('permissionFilterValue', (string) $aleppo->id);

        $visible = $page->get('allUsers')->pluck('id')->all();

        $this->assertContains($inHrAleppo->id, $visible);
        $this->assertNotContains($leftHr->id, $visible, 'an ended contract does not make someone part of the group');
        $this->assertNotContains($hrHoms->id, $visible, 'both filters must hold on the same contract');
        $this->assertNotContains($gone->id, $visible, 'someone who left is not offered');

        $page->call('toggleSelectAllVisible')->call('savePermissions');

        // The owner is folders.user_id, not a share.
        $this->assertEqualsCanonicalizing([$inHrAleppo->id], $folder->fresh()->users->pluck('id')->all());
    }
}
