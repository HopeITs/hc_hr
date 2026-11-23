<?php

namespace Tests\Feature;

use App\Livewire\Basics\CourseCategoryTable;
use App\Models\CourseCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * HR-020: a method reached through an event (a confirm dialog's callback, $dispatchTo)
 * arrives as "__dispatch", which the permission map never matched, so delete and export
 * ran for anyone who could open the page. The handlers now check their permission
 * themselves, and the middleware resolves the event to its method.
 */
class EventListenerPermissionTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_a_viewer_cannot_delete_or_export_through_an_event(): void
    {
        $city = $this->makeCity('ALP', '001');
        $viewer = $this->makeUser($city, 'general-manager', ['project-basics.view']);
        $category = CourseCategory::create(['name_en' => 'QA', 'name_ar' => 'QA']);

        Livewire::actingAs($viewer)
            ->test(CourseCategoryTable::class)
            ->dispatch('delete', ['id' => $category->id])
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(CourseCategoryTable::class)
            ->dispatch('exportExcel')
            ->assertForbidden();

        $this->assertModelExists($category);
    }

    public function test_a_holder_of_the_permission_still_deletes_through_the_confirm_event(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-ALP', ['project-basics.view', 'project-basics.delete']);
        $category = CourseCategory::create(['name_en' => 'QA', 'name_ar' => 'QA']);

        Livewire::actingAs($hr)
            ->test(CourseCategoryTable::class)
            ->dispatch('delete', ['id' => $category->id]);

        $this->assertModelMissing($category);
    }
}
