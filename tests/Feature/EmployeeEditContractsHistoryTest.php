<?php

namespace Tests\Feature;

use App\Livewire\Employee\EmployeeCreate;
use App\Livewire\Employee\EmployeeEdit;
use App\Models\Contract;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The employee form's read-only Contracts History tab shows the same
 * contracts, to the same people, as the profile's Career History card.
 */
class EmployeeEditContractsHistoryTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_hr_sees_every_contract_newest_first_with_pay(): void
    {
        $city = $this->makeCity('ALP', '001');
        $subject = $this->makeSubjectWithContracts($city);

        Livewire::actingAs($this->makeUser($city, 'HR-ALP', ['employees.update']))
            ->test(EmployeeEdit::class, ['employee' => $subject])
            ->assertSee(__('general.contracts-history'))
            ->assertDontSee('OLD-CONTRACT-POSITION')
            ->call('setActiveTab', 'contracts_history')
            ->assertViewHas('contractHistory', fn ($contracts) => $contracts->pluck('position.title_en')->all() === [
                'NEWER-OLD-POSITION', 'CURRENT-POSITION', 'OLD-CONTRACT-POSITION',
            ])
            ->assertSee('OLD-CONTRACT-POSITION')
            ->assertSee(number_format(1500));
    }

    public function test_pay_is_neither_loaded_nor_shown_without_salary_access(): void
    {
        $city = $this->makeCity('ALP', '001');
        $subject = $this->makeSubjectWithContracts($city);

        Livewire::actingAs($this->makeUser($city, 'super-admin', ['employees.update']))
            ->test(EmployeeEdit::class, ['employee' => $subject])
            ->call('setActiveTab', 'contracts_history')
            ->assertSee('CURRENT-POSITION')
            ->assertViewHas('contractHistory', fn ($contracts) => $contracts->every(
                fn (Contract $contract) => ! array_key_exists('salary', $contract->getAttributes()),
            ))
            ->assertDontSee(__('general.salary').':');
    }

    public function test_the_employee_role_does_not_get_the_tab(): void
    {
        $city = $this->makeCity('ALP', '001');
        $subject = $this->makeSubjectWithContracts($city);

        Livewire::actingAs($this->makeUser($city, 'employee', ['employees.update']))
            ->test(EmployeeEdit::class, ['employee' => $subject])
            ->assertDontSee(__('general.contracts-history'))
            ->call('setActiveTab', 'contracts_history')
            ->assertViewHas('contractHistory', fn ($contracts) => $contracts->isEmpty())
            ->assertDontSee('CURRENT-POSITION');
    }

    /** Disabled until the employee is saved, like the other record tabs. */
    public function test_create_mode_disables_the_tab(): void
    {
        $city = $this->makeCity('ALP', '001');

        $html = Livewire::actingAs($this->makeUser($city, 'HR-ALP', ['employees.create']))
            ->test(EmployeeCreate::class)
            ->html();

        $this->assertMatchesRegularExpression(
            "/<button[^>]*\\bdisabled\\b[^>]*setActiveTab\\('contracts_history'\\)/s",
            $html,
        );
    }

    private function makeSubjectWithContracts($city): Employee
    {
        $subject = $this->makeEmployee($city, 'history.subject.'.uniqid());

        $this->makeContractFor($subject, [
            'start_date' => today()->subYears(4),
            'end_date' => today()->subYears(3),
            'is_active' => false,
        ])->position->update(['title_en' => 'OLD-CONTRACT-POSITION', 'title_ar' => 'OLD-CONTRACT-POSITION']);

        $this->makeContractFor($subject, [
            'start_date' => today()->subMonth(),
            'end_date' => today()->addMonths(6),
        ])->position->update(['title_en' => 'CURRENT-POSITION', 'title_ar' => 'CURRENT-POSITION']);

        // Starts after the current one, so it comes first, as on the profile.
        $this->makeContractFor($subject, [
            'start_date' => today()->addMonths(7),
            'end_date' => today()->addYear(),
            'is_active' => false,
        ])->position->update(['title_en' => 'NEWER-OLD-POSITION', 'title_ar' => 'NEWER-OLD-POSITION']);

        return $subject->fresh();
    }
}
