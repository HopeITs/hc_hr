<?php

namespace Tests\Feature;

use App\Livewire\Employee\EmployeeEdit;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * A save on a tab nobody edited used to claim "Operation completed
 * successfully". EmployeeEdit::persist() now watches Eloquent's write events
 * and reports what actually happened.
 */
class EmployeeEditSaveFeedbackTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_saving_an_untouched_tab_reports_no_changes_and_writes_nothing(): void
    {
        [$user, $employee] = $this->hrAndEmployee();
        $relative = $employee->relatives()->create([
            'name' => 'Sibling',
            'phone' => '0100',
            'relation' => 1,
        ]);
        $untouchedAt = $relative->updated_at;

        $component = Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->call('saveRelatives');

        $this->assertSame('info', $this->toastIcon($component));
        $this->assertEquals($untouchedAt, $relative->fresh()->updated_at);
    }

    public function test_saving_a_real_change_reports_success(): void
    {
        [$user, $employee] = $this->hrAndEmployee();
        $employee->relatives()->create([
            'name' => 'Sibling',
            'phone' => '0100',
            'relation' => 1,
        ]);

        $component = Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->set('relativeForm.relatives.0.name', 'Renamed Sibling')
            ->call('saveRelatives');

        $this->assertSame('success', $this->toastIcon($component));
        $this->assertSame('Renamed Sibling', $employee->relatives()->first()->name);
    }

    public function test_a_save_re_arms_the_button_gate_for_its_own_form(): void
    {
        [$user, $employee] = $this->hrAndEmployee();

        Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->call('saveRelatives')
            ->assertDispatched('form-saved', form: 'relativeForm');
    }

    /** @return array{0: \App\Models\User, 1: Employee} */
    private function hrAndEmployee(): array
    {
        $city = $this->makeCity('ALP', '001');

        return [
            $this->makeUser($city, 'HR-ALP', ['employees.update']),
            $this->makeEmployee($city, 'subject-'.uniqid()),
        ];
    }

    /**
     * The toast's `type` is the part that separates "saved" from "nothing to save".
     */
    private function toastIcon($component): ?string
    {
        return collect($component->effects['dispatches'] ?? [])
            ->firstWhere('name', 'toast')['params']['type'] ?? null;
    }
}
