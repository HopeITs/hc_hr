<?php

namespace Tests\Feature;

use App\Console\Commands\CheckEmployeeIntegrity;
use App\Enums\ContractStatus;
use App\Livewire\Contract\ContractsTable;
use App\Models\City;
use App\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * R2-3b (decisions 2026-11-27): a contract terminated for a later date runs
 * until that date (R2-3), and every place reads it that way: its status says
 * it is running and when it ends, the "ends in" badge counts to that date, and
 * the nightly check does not list its holder as gone. "Today" is 27 Nov 2026.
 */
class ContractPendingTerminationTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-27 10:00:00');
        $this->city = $this->makeCity('ALP', '001');
    }

    public function test_a_pending_termination_reads_as_running_until_its_date(): void
    {
        $contract = $this->terminated('2026-12-15');

        $this->assertTrue($contract->hasPendingTermination());
        $this->assertFalse($contract->hasEndedByTermination());
        $this->assertSame(ContractStatus::Ending, ContractStatus::for($contract));
        $this->assertSame(__('contracts.status-ending-on', ['date' => '15-12-2026']), ContractStatus::labelFor($contract));
    }

    public function test_once_the_date_has_come_it_reads_as_terminated(): void
    {
        $today = $this->terminated('2026-11-27');

        $this->assertFalse($today->hasPendingTermination());
        $this->assertTrue($today->hasEndedByTermination());
        $this->assertSame(ContractStatus::Terminated, ContractStatus::for($today));
        $this->assertSame(__('contracts.status-terminated'), ContractStatus::labelFor($today));
        $this->assertNull(ContractsTable::endsInDays($today));
    }

    public function test_a_running_contract_keeps_its_plain_label(): void
    {
        $contract = $this->makeContractFor($this->makeEmployee($this->city, 'r23b.plain'), ['end_date' => '2027-06-30']);

        $this->assertSame(ContractStatus::Active, ContractStatus::for($contract->fresh()));
        $this->assertSame(__('general.active'), ContractStatus::labelFor($contract->fresh()));
    }

    public function test_the_ends_in_badge_counts_to_the_termination_date(): void
    {
        $this->assertSame(18, ContractsTable::endsInDays($this->terminated('2026-12-15')));
    }

    public function test_the_nightly_check_does_not_count_its_holder_as_gone(): void
    {
        $contract = $this->terminated('2026-12-15');

        $findings = app(CheckEmployeeIntegrity::class)->findings();

        $this->assertNotContains($contract->employee_id, $findings['last_contract_ended']);
        $this->assertNotContains($contract->id, $findings['ended']);
    }

    private function terminated(string $on): Contract
    {
        $contract = $this->makeContractFor($this->makeEmployee($this->city, 'r23b.'.uniqid()), [
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'terminated_at' => $on,
        ]);
        // Running, as R2-3 leaves a future termination (and as a missed
        // nightly run leaves one whose date has come).
        Contract::query()->whereKey($contract->id)->update(['is_active' => true]);

        return $contract->fresh();
    }
}
