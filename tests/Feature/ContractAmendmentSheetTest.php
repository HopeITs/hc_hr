<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\Currency;
use App\Enums\Gender;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\User;
use App\Services\ContractAmendmentService;
use App\Services\ContractPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Phase 4 of contract amendments: the printed sheet. Its wording is checked on
 * the HTML (no Chrome here); the PDF endpoint is checked with the renderer
 * mocked, the way ContractExportTest checks the contract's own.
 */
class ContractAmendmentSheetTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const FAKE_PDF = "%PDF-1.7\n% synthetic\n%%EOF";

    private const PERMISSIONS = ['contracts.view', 'contracts.export', 'contracts.amend'];

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->city = $this->makeCity('ALP', '001');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── wording ─────────────────────────────────────────────────────

    public function test_the_sheet_states_the_new_schedule_pay_and_date(): void
    {
        $html = $this->sheet($this->amend([
            'type' => ContractTypeEnum::FullTime,
            'weekly_hours' => 30,
            'work_hours_to' => '15:00',
            'salary' => 250,
        ]));

        $this->assertStringContainsString('تعديل عقد', $html);
        $this->assertStringContainsString('تم إجراء التعديلات التالية في عقد المتطوعة', $html);
        $this->assertStringContainsString('Maya Habib Doumat', $html);
        $this->assertStringContainsString('دواماً كاملاً', $html);
        // The new hours, worded by the contract's own schedule clause.
        $this->assertStringContainsString('3:00 PM', $html);
        $this->assertStringContainsString('3:00 م', $html);
        // The new stipend, worded by the contract's own pay clause.
        $this->assertStringContainsString('250 USD', $html);
        $this->assertStringContainsString('يحافظ على كافة بنود العقد الأساسي عدا التعديلات السابقة', $html);
        $this->assertStringContainsString('01/05/2026', $html);
        $this->assertStringContainsString('Fredy Georges Youssef', $html);

        // Clauses numbered 1, 2, 3 with no gaps.
        foreach (['1. Type of work:', '2. Volunteer Work Hours,', '3. Stipend,'] as $clause) {
            $this->assertStringContainsString($clause, $html);
        }
    }

    public function test_the_sheet_leaves_out_what_did_not_change(): void
    {
        $html = $this->sheet($this->amend(['work_hours_to' => '15:00']));

        $this->assertStringContainsString('3:00 PM', $html);
        $this->assertStringNotContainsString('Stipend,', $html);
        $this->assertStringNotContainsString('150 USD', $html);
        $this->assertStringNotContainsString('Type of work', $html);
    }

    public function test_a_pay_only_change_prints_no_schedule_clause(): void
    {
        $html = $this->sheet($this->amend(['salary' => 300]));

        $this->assertStringContainsString('300 USD', $html);
        $this->assertStringNotContainsString('Volunteer Work Hours,', $html);
        $this->assertStringContainsString('<strong>1</strong>', $html); // Amendment No. 1
    }

    public function test_a_male_volunteer_is_addressed_as_such(): void
    {
        $html = $this->sheet($this->amend(['salary' => 300], gender: Gender::Male));

        $this->assertStringContainsString('في عقد المتطوع ', $html);
    }

    public function test_the_contract_itself_is_not_changed_by_printing(): void
    {
        $amendment = $this->amend(['weekly_hours' => 30, 'salary' => 250]);
        $this->sheet($amendment);

        $contract = $amendment->contract->refresh();
        $this->assertSame(20, $contract->weekly_hours);
        $this->assertSame('150.00', $contract->salary);
    }

    public function test_file_name_names_the_person_the_number_and_the_date(): void
    {
        $this->assertSame(
            'maya-doumat-amendment-1-20260501.pdf',
            app(ContractPdfRenderer::class)->amendmentFileName($this->amend(['salary' => 300])),
        );
    }

    // ── the endpoint ────────────────────────────────────────────────

    public function test_hr_gets_the_pdf(): void
    {
        $amendment = $this->amend(['salary' => 300]);
        $this->partialMock(ContractPdfRenderer::class, fn (MockInterface $mock) => $mock->shouldReceive('renderAmendment')->andReturn(self::FAKE_PDF));

        $this->actingAs($this->hr())
            ->get(route('contracts.amendments.export', [$amendment->contract_id, $amendment->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="maya-doumat-amendment-1-20260501.pdf"');
    }

    public function test_hr_of_another_city_and_salary_blind_roles_are_refused(): void
    {
        $amendment = $this->amend(['salary' => 300]);
        $this->partialMock(ContractPdfRenderer::class, fn (MockInterface $mock) => $mock->shouldNotReceive('renderAmendment'));
        $url = route('contracts.amendments.export', [$amendment->contract_id, $amendment->id]);

        $this->actingAs($this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', self::PERMISSIONS))
            ->get($url)->assertForbidden();

        config(['salary.blind_roles' => ['super-admin', 'HR-ALP']]);
        $this->actingAs($this->hr())->get($url)->assertForbidden();
    }

    public function test_an_amendment_of_another_contract_is_not_found(): void
    {
        $amendment = $this->amend(['salary' => 300]);
        $other = $this->contract();

        $this->actingAs($this->hr())
            ->get(route('contracts.amendments.export', [$other->id, $amendment->id]))
            ->assertNotFound();
    }

    public function test_a_failed_render_answers_503(): void
    {
        $amendment = $this->amend(['salary' => 300]);
        $this->partialMock(ContractPdfRenderer::class, fn (MockInterface $mock) => $mock->shouldReceive('renderAmendment')->andThrow(new RuntimeException('Chrome did not write a PDF.')));

        $this->actingAs($this->hr())
            ->get(route('contracts.amendments.export', [$amendment->contract_id, $amendment->id]))
            ->assertStatus(503);
    }

    public function test_the_card_offers_the_sheet(): void
    {
        $amendment = $this->amend(['salary' => 300]);

        $this->actingAs($this->hr())->get(route('contracts.show', $amendment->contract_id))
            ->assertOk()
            ->assertSee(__('contracts.amendment.print'))
            ->assertSee(route('contracts.amendments.export', [$amendment->contract_id, $amendment->id]), false);
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function sheet(ContractAmendment $amendment): string
    {
        return app(ContractPdfRenderer::class)->amendmentView($amendment)->render();
    }

    private function amend(array $changes, int $gender = Gender::Female): ContractAmendment
    {
        return app(ContractAmendmentService::class)->draft($this->contract($gender), $changes, '2026-05-01');
    }

    private function hr(): User
    {
        return $this->makeUser($this->city, 'HR-ALP', self::PERMISSIONS);
    }

    private function contract(int $gender = Gender::Female): Contract
    {
        $employee = $this->makeEmployee($this->city, 'maya-'.uniqid(), [
            'first_name_en' => 'Maya', 'father_name_en' => 'Habib', 'last_name_en' => 'Doumat',
            'first_name_ar' => 'مايا', 'father_name_ar' => 'حبيب', 'last_name_ar' => 'ضوماط',
            'gender' => $gender,
        ]);

        return $this->makeContractFor($employee, [
            'type' => ContractTypeEnum::PartTime,
            'schedule_mode' => 1,
            'work_day_from' => 0,
            'work_day_to' => 4,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '13:00:00',
            'weekly_hours' => 20,
            'salary' => 150,
            'currency' => Currency::USD,
            'salary_per' => 1,
            'pay_mode' => 1,
        ])->refresh();
    }
}
