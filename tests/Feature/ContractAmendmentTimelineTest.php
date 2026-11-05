<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\Currency;
use App\Livewire\Employee\EmployeeProfile;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Services\ContractAmendmentService;
use App\Services\EmployeeTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Phase 5 of contract amendments: a person's year read back on their profile
 * timeline ("part time until 1/5, full time since, stipend now 250").
 */
class ContractAmendmentTimelineTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $city;

    private Employee $maya;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->city = $this->makeCity('ALP', '001');
        $this->maya = $this->makeEmployee($this->city, 'maya');
        $this->contract = $this->makeContractFor($this->maya, [
            'type' => ContractTypeEnum::PartTime,
            'weekly_hours' => 20,
            'salary' => 150,
            'currency' => Currency::USD,
        ])->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_applied_amendment_is_an_event_on_its_effective_date(): void
    {
        $this->applyAmendment('2026-05-01', ['type' => ContractTypeEnum::FullTime, 'weekly_hours' => 30, 'salary' => 250]);

        $amended = $this->event('contract.amended', withPay: true);

        $this->assertSame('2026-05-01', $amended['date']);
        $this->assertStringContainsString('20 hours a week', $amended['detail']);
        $this->assertStringContainsString('30 hours a week', $amended['detail']);
        $this->assertSame(['amount' => '250.00', 'currency' => Currency::USD], $amended['pay']);
        // Said once, on the pay line, not again in the summary.
        $this->assertStringNotContainsString('250', $amended['detail']);
    }

    public function test_the_contract_start_keeps_the_pay_it_started_on(): void
    {
        $this->applyAmendment('2026-05-01', ['salary' => 250]);

        $this->assertSame('150.00', $this->event('contract.joined', withPay: true)['pay']['amount']);
    }

    public function test_without_the_financial_tier_no_figure_appears(): void
    {
        $this->applyAmendment('2026-05-01', ['weekly_hours' => 30, 'salary' => 250]);

        $amended = $this->event('contract.amended', withPay: false);

        $this->assertNull($amended['pay']);
        $this->assertStringNotContainsString('250', (string) $amended['detail']);
        $this->assertStringContainsString('30 hours a week', $amended['detail']);
    }

    public function test_drafts_and_waiting_amendments_are_not_history_yet(): void
    {
        $service = app(ContractAmendmentService::class);
        $service->approve($service->draft($this->contract, ['weekly_hours' => 30], '2026-12-01'));

        $this->assertNotContains('contract.amended', array_column($this->events(true), 'kind'));
    }

    public function test_events_read_newest_first(): void
    {
        $this->applyAmendment('2026-05-01', ['weekly_hours' => 30]);
        $this->applyAmendment('2026-09-01', ['weekly_hours' => 35]);

        $amended = array_values(array_filter($this->events(true), fn ($e) => $e['kind'] === 'contract.amended'));

        $this->assertSame(['2026-09-01', '2026-05-01'], array_column($amended, 'date'));
    }

    public function test_hr_sees_it_on_the_profile(): void
    {
        $this->applyAmendment('2026-05-01', ['weekly_hours' => 30]);
        $hr = $this->makeUser($this->city, 'HR-ALP', ['employees.view', 'employees.update']);

        Livewire::actingAs($hr)
            ->test(EmployeeProfile::class, ['employee' => $this->maya])
            ->assertSeeHtml('data-event="contract.amended"')
            ->assertSee(__('employees.profile.events.contract-amended'));
    }

    private function applyAmendment(string $date, array $changes): void
    {
        $service = app(ContractAmendmentService::class);
        $service->approve($service->draft($this->contract->refresh(), $changes, $date));
    }

    private function events(bool $withPay): array
    {
        $contracts = $this->maya->allContracts()->with(['position', 'contractType', 'appliedAmendments.payTiers'])->get();

        return app(EmployeeTimeline::class)->events($contracts, collect(), $withPay);
    }

    private function event(string $kind, bool $withPay): array
    {
        return collect($this->events($withPay))->firstWhere('kind', $kind)
            ?? $this->fail("No {$kind} event.");
    }
}
