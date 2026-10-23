<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Contract\ContractsTable;
use App\Livewire\Employee\EmployeeTable;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/** B3: employee and contract lists tell empty, filtered-empty and failed loads apart. */
class ListStatesTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    public function test_employee_list_empty_dataset_offers_add_only_with_create_permission(): void
    {
        $creator = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS', ['employees.view', 'employees.create']);
        $viewer = $this->makeUser($this->makeCity('LAT', '002'), 'HR-LAT', ['employees.view']);

        // The fixture users are themselves employees; remove them for a truly empty dataset.
        Employee::query()->delete();

        Livewire::actingAs($creator)->test(EmployeeTable::class)
            ->assertSeeHtml('data-state="empty"')
            ->assertSee(__('general.state-empty-title'))
            ->assertSee(__('general.state-add-employee'))
            ->assertDontSee(__('general.state-filtered-title'));

        Livewire::actingAs($viewer)->test(EmployeeTable::class)
            ->assertSeeHtml('data-state="empty"')
            ->assertDontSee(__('general.state-add-employee'));
    }

    public function test_employee_list_filtered_empty_clears_back_to_records(): void
    {
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-DMS', ['employees.view', 'employees.create']);
        $row = 'wire:key="row-'.$this->makeEmployee($city, 'visible-one', ['first_name_en' => 'Zebulon', 'last_name_en' => 'Quill'])->id.'"';

        Livewire::actingAs($user)->test(EmployeeTable::class)
            ->set('globalSearch', 'NoSuchPersonAnywhere')
            ->assertSeeHtml('data-state="filtered"')
            ->assertSee(__('general.clear-filters'))
            ->assertDontSee(__('general.state-add-employee'))
            ->assertDontSeeHtml($row)
            ->call('clearAllFilters')
            ->assertDispatched('list-filters-cleared')
            ->assertSet('globalSearch', '')
            ->assertSet('search', [])
            ->assertDontSeeHtml('data-state="filtered"')
            ->assertSeeHtml($row);
    }

    public function test_column_search_counts_as_filtered_and_is_cleared(): void
    {
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-DMS', ['employees.view']);
        $row = 'wire:key="row-'.$this->makeEmployee($city, 'visible-two', ['first_name_en' => 'Zebulon'])->id.'"';

        Livewire::actingAs($user)->test(EmployeeTable::class)
            ->set('search.employee', 'NoSuchPersonAnywhere')
            ->assertSeeHtml('data-state="filtered"')
            ->call('clearAllFilters')
            ->assertSet('search', [])
            ->assertSeeHtml($row);
    }

    public function test_employee_search_keeps_city_scoping(): void
    {
        $dms = $this->makeCity('DMS', '003');
        $lat = $this->makeCity('LAT', '002');
        $user = $this->makeUser($dms, 'HR-DMS', ['employees.view']);
        $in = 'wire:key="row-'.$this->makeEmployee($dms, 'in-city', ['first_name_en' => 'Zebulon'])->id.'"';
        $out = 'wire:key="row-'.$this->makeEmployee($lat, 'out-city', ['first_name_en' => 'Zebedee'])->id.'"';

        Livewire::actingAs($user)->test(EmployeeTable::class)
            ->assertSeeHtml($in)
            ->assertDontSeeHtml($out)
            ->set('globalSearch', 'Zebedee')
            ->assertSeeHtml('data-state="filtered"')
            ->assertDontSeeHtml($out);
    }

    public function test_contract_list_states_and_create_action_per_role(): void
    {
        $city = $this->makeCity('DMS', '003');
        $hr = $this->makeUser($city, 'HR-DMS', ['contracts.view', 'contracts.create']);
        $viewer = $this->makeUser($city, 'manager', ['contracts.view']);

        Livewire::actingAs($hr)->test(ContractsTable::class)
            ->assertSeeHtml('data-state="empty"')
            ->assertSee(__('general.state-add-contract'));

        Livewire::actingAs($viewer)->test(ContractsTable::class)
            ->assertSeeHtml('data-state="empty"')
            ->assertDontSee(__('general.state-add-contract'));

        $this->makeContractFor($hr->profile);

        Livewire::actingAs($hr)->test(ContractsTable::class)
            ->set('search.employee', 'NoSuchPersonAnywhere')
            ->assertSeeHtml('data-state="filtered"')
            ->assertDontSee(__('general.state-add-contract'))
            ->call('clearAllFilters')
            ->assertSet('search', [])
            ->assertDontSeeHtml('data-state="filtered"')
            ->assertDontSeeHtml('data-state="empty"');
    }

    public function test_contract_list_never_leaks_pay_figures(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS', ['contracts.view']);
        $this->makeContractFor($user->profile, ['salary' => 7391]);

        // The typed search is echoed in its own filter chip, so the proof that
        // pay is not searchable is that the contract does not match it.
        Livewire::actingAs($user)->test(ContractsTable::class)
            ->assertSee('Test Person')
            ->assertDontSee('7391')
            ->set('globalSearch', '7391')
            ->assertDontSee('Test Person');
    }

    public function test_load_failure_shows_retry_and_recovers(): void
    {
        Exceptions::fake();
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS', ['employees.view']);
        FailingEmployeeTable::$broken = true;

        $component = Livewire::actingAs($user)->test(FailingEmployeeTable::class)
            ->assertSet('loadFailed', true)
            ->assertSee(__('general.state-error-title'))
            ->assertSee(__('general.retry'))
            ->assertDontSee('SECRET-SQL-DETAIL')
            ->assertDontSeeHtml('data-state="empty"');

        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'SECRET-SQL-DETAIL'));

        FailingEmployeeTable::$broken = false;
        $component->call('retryLoad')
            ->assertSet('loadFailed', false)
            ->assertDontSee(__('general.state-error-title'));
    }

    public function test_state_changes_are_announced(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS', ['employees.view']);

        Livewire::actingAs($user)->test(EmployeeTable::class)
            ->assertSeeHtml('role="status"')
            ->set('globalSearch', 'NoSuchPersonAnywhere')
            ->assertSee(__('general.state-filtered-title'));
    }

    public function test_my_leaves_page_survives_a_failed_count_query(): void
    {
        Exceptions::fake();
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'staff-leave', ['leaves.view-own', 'leaves.create']);

        \Illuminate\Support\Facades\Schema::drop('leave_requests');

        Livewire::actingAs($user)->test(\App\Livewire\Employee\Leave\MyLeaveRequestModule::class)
            ->assertOk()
            ->assertSet('counts.all', 0);

        Exceptions::assertReported(QueryException::class);
    }
}

class FailingEmployeeTable extends EmployeeTable
{
    public static bool $broken = true;

    protected function query(): Builder
    {
        if (self::$broken) {
            throw new RuntimeException('SECRET-SQL-DETAIL');
        }

        return parent::query();
    }
}
