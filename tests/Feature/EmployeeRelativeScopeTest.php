<?php

namespace Tests\Feature;

use App\Livewire\Employee\EmployeeEdit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * HR-014: removing a relative took the id from the browser and deleted it with an
 * unscoped Relative::find(), so whoever could edit one employee could delete any
 * relative in the system, in any city. The id is now looked up among the edited
 * employee's own relatives.
 */
class EmployeeRelativeScopeTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_a_relative_of_another_employee_is_never_deleted(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $damascus = $this->makeCity('DMS', '003');
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['employees.update']);
        $edited = $this->makeEmployee($aleppo, 'edited-'.uniqid());
        $other = $this->makeEmployee($damascus, 'other-'.uniqid());
        $foreign = $other->relatives()->create(['name' => 'Not yours', 'phone' => '0100', 'relation' => 1]);

        Livewire::actingAs($hr)
            ->test(EmployeeEdit::class, ['employee' => $edited])
            ->call('addRelative')
            ->call('removeRelative', 0, $foreign->id);

        $this->assertModelExists($foreign);
    }

    public function test_the_edited_employees_own_relative_is_still_removed(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['employees.update']);
        $edited = $this->makeEmployee($aleppo, 'edited-'.uniqid());
        $own = $edited->relatives()->create(['name' => 'Sibling', 'phone' => '0100', 'relation' => 1]);

        Livewire::actingAs($hr)
            ->test(EmployeeEdit::class, ['employee' => $edited])
            ->call('removeRelative', 0, $own->id);

        $this->assertModelMissing($own);
    }
}
