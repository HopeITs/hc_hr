<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\PayMode;
use App\Enums\SalaryPer;
use App\Enums\SessionDuration;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * <x-contract.pay-clause> — the one place the printed "Stipend and Benefits"
 * wording lives now (see docs/superpowers/specs/2026-09-15-contract-
 * composer-restructure.md, Phase 5). The pre-Phase-5 templates always said
 * "monthly stipend" regardless of salary_per, and two of the three
 * categories hardcoded the currency to USD regardless of what the contract
 * actually stored — both fixed here.
 */
class ContractPayClauseTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_fixed_mode_paid_monthly(): void
    {
        $contract = $this->makeContract([
            'pay_mode' => PayMode::Fixed,
            'salary' => 150,
            'currency' => Currency::USD,
            'salary_per' => SalaryPer::Month,
        ]);

        $html = $this->renderClause($contract);

        $this->assertStringContainsString('monthly stipend', $html);
        $this->assertStringContainsString('150', $html);
        $this->assertStringContainsString('USD', $html);
        // Foreign currency: the SYP-equivalent clause is present.
        $this->assertStringContainsString('equivalent in Syrian pounds', $html);
    }

    public function test_fixed_mode_paid_hourly(): void
    {
        $contract = $this->makeContract([
            'pay_mode' => PayMode::Fixed,
            'salary' => 5,
            'currency' => Currency::SYP,
            'salary_per' => SalaryPer::Hour,
        ]);

        $html = $this->renderClause($contract);

        $this->assertStringContainsString('hourly stipend', $html);
        $this->assertStringContainsString('per hour', $html);
        $this->assertStringContainsString('SYP', $html);
        // Already SYP: no equivalence clause needed.
        $this->assertStringNotContainsString('equivalent in Syrian pounds', $html);
    }

    public function test_fixed_mode_paid_per_session_states_the_session_length(): void
    {
        $contract = $this->makeContract([
            'pay_mode' => PayMode::Fixed,
            'salary' => 30,
            'currency' => Currency::EUR,
            'salary_per' => SalaryPer::Session,
            'session_duration' => SessionDuration::HourAndHalf,
        ]);

        $html = $this->renderClause($contract);

        $this->assertStringContainsString('per session', $html);
        $this->assertStringContainsString('an hour and a half', $html);
        $this->assertStringContainsString('EUR', $html);
        $this->assertStringContainsString('equivalent in Syrian pounds', $html);
    }

    public function test_flexible_mode_lists_every_tier(): void
    {
        $contract = $this->makeContract([
            'pay_mode' => PayMode::Flexible,
            'currency' => Currency::USD,
        ]);

        $contract->payTiers()->createMany([
            ['condition_en' => 'If teaching 60h/month', 'condition_ar' => 'إذا كان التدريس 60 ساعة شهريًا', 'amount' => 200, 'sort_order' => 0],
            ['condition_en' => 'If teaching 70h/month', 'condition_ar' => 'إذا كان التدريس 70 ساعة شهريًا', 'amount' => 300, 'sort_order' => 1],
        ]);

        $html = $this->renderClause($contract->fresh());

        $this->assertStringContainsString('If teaching 60h/month', $html);
        $this->assertStringContainsString('200', $html);
        $this->assertStringContainsString('If teaching 70h/month', $html);
        $this->assertStringContainsString('300', $html);
    }

    public function test_flexible_mode_with_no_tiers_falls_back_to_a_placeholder(): void
    {
        $contract = $this->makeContract(['pay_mode' => PayMode::Flexible]);

        $html = $this->renderClause($contract);

        $this->assertStringContainsString('—', $html);
    }

    /**
     * The printed export renders this clause exactly as the screen does: since
     * the switch to Browsershot (3a0e5c49) Chrome shapes Arabic natively and
     * pdf/contract.blade.php never sets forPdf. The old forPdf=true branch
     * still names App\Support\ArabicText, deleted in that same commit, so it
     * is unreachable dead code rather than the export path — see
     * docs/ux/contract-failure-triage.md. This checks the path that ships.
     */
    public function test_the_clause_the_export_prints_carries_the_amount_in_both_languages(): void
    {
        $contract = $this->makeContract([
            'pay_mode' => PayMode::Fixed,
            'salary' => 150,
            'currency' => Currency::USD,
            'salary_per' => SalaryPer::Month,
        ]);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $this->assertStringContainsString('150', $this->renderClause($contract));
        }
    }

    private function renderClause(Contract $contract, bool $forPdf = false): string
    {
        return view('components.contract.pay-clause', [
            'contract' => $contract,
            'forPdf' => $forPdf,
        ])->render();
    }

    private function makeContract(array $pay): Contract
    {
        $city = $this->makeCity('ALP', '001');

        $center = Center::query()->create([
            'name_en' => 'Hope Center',
            'name_ar' => 'مركز الرجاء',
            'city_id' => $city->id,
            'is_active' => true,
            'weekends' => 'friday',
            'open_at' => '08:00:00',
            'close_at' => '16:00:00',
        ]);

        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $program = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => 'CORE', 'is_active' => true]);

        $position = Position::query()->forceCreate([
            'center_id' => $center->id,
            'city_id' => $city->id,
            'department_id' => $department->id,
            'job_level_id' => $jobLevel->id,
            'program_id' => $program->id,
            'title_en' => 'Program Officer',
            'title_ar' => 'مسؤول برنامج',
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);

        $employee = $this->makeEmployee($city, 'pay-clause-test-employee-'.uniqid());

        $annualType = ContractType::query()->firstOrCreate(
            ['id' => 1],
            ['name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع'],
        );

        return Contract::query()->forceCreate(array_merge([
            'employee_id' => $employee->id,
            'position_id' => $position->id,
            'contract_type_id' => $annualType->id,
            'type' => 1,
            'place' => 1,
            'is_active' => true,
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'schedule_mode' => 1,
        ], $pay));
    }
}
