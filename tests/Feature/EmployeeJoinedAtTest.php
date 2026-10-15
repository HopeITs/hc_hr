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
 * The joining date is optional on the employee form: it can be set, changed
 * and cleared, and a cleared input must be stored as null, not ''.
 */
class EmployeeJoinedAtTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_edit_form_loads_the_stored_joining_date(): void
    {
        [$user, $employee] = $this->hrAndEmployee(['joined_at' => '2019-03-15']);

        Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->assertSet('infoForm.joined_at', '2019-03-15');
    }

    public function test_a_joining_date_can_be_set(): void
    {
        [$user, $employee] = $this->hrAndEmployee();

        Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->set('infoForm.joined_at', '2020-06-01')
            ->call('saveInformation')
            ->assertHasNoErrors();

        $this->assertSame('2020-06-01', $employee->fresh()->joined_at->toDateString());
    }

    public function test_a_cleared_joining_date_is_stored_as_null(): void
    {
        [$user, $employee] = $this->hrAndEmployee(['joined_at' => '2019-03-15']);

        Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->set('infoForm.joined_at', '')
            ->call('saveInformation')
            ->assertHasNoErrors();

        $this->assertNull($employee->fresh()->joined_at);
    }

    public function test_the_joining_date_must_be_a_date(): void
    {
        [$user, $employee] = $this->hrAndEmployee();

        Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->set('infoForm.joined_at', 'not-a-date')
            ->call('saveInformation')
            ->assertHasErrors(['infoForm.joined_at']);
    }

    /** @return array{0: \App\Models\User, 1: Employee} */
    private function hrAndEmployee(array $attributes = []): array
    {
        $city = $this->makeCity('ALP', '001');

        return [
            $this->makeUser($city, 'HR-ALP', ['employees.update']),
            $this->makeEmployee($city, 'subject-'.uniqid(), array_merge([
                'father_name_en' => 'Father',
                'father_name_ar' => 'أب',
                'mother_name_en' => 'Mother',
                'mother_name_ar' => 'أم',
                'national_number' => 'P-'.uniqid(),
                'gender' => 1,
                'marital_status' => 1,
                'nationality' => 2,
                'is_board_member' => false,
            ], $attributes)),
        ];
    }
}
