<?php

namespace Tests\Feature;

use App\Enums\ScheduleMode;
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
 * <x-contract.schedule-clause> — the one place the printed "Volunteer Work
 * Hours" wording lives now, shared by the on-screen contract view and the
 * PDF export (see docs/superpowers/specs/2026-09-15-contract-composer-
 * restructure.md, Phase 5). Renders the component directly rather than
 * going through the composer form, since these are print-output tests, not
 * composer-submission ones — a Contract row is all the component needs.
 */
class ContractScheduleClauseTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_fixed_mode_states_only_the_checked_pieces(): void
    {
        $contract = $this->makeContract([
            'schedule_mode' => ScheduleMode::Fixed,
            'work_hours_from' => '09:00',
            'work_hours_to' => '17:00',
            'work_day_from' => 0,
            'work_day_to' => 4,
            'show_hours_per_week' => true,
            'show_hours_per_day' => false,
            'show_days_per_week' => false,
            'show_day_range' => false,
            'weekly_hours' => 40,
        ]);

        $html = $this->renderClause($contract);

        $this->assertStringContainsString('40 working hours</strong> per week', $html);
        $this->assertStringNotContainsString('hours</strong> per day', $html);
        $this->assertStringNotContainsString('days</strong> per week', $html);
        // Day range unchecked: the office-hours line has no day names in it.
        $this->assertStringNotContainsString('Sunday', $html);
        $this->assertStringContainsString('Standard office hours are from', $html);
        $this->assertStringContainsString('9:00 AM', $html);
        $this->assertStringContainsString('5:00 PM', $html);
    }

    public function test_fixed_mode_with_every_toggle_on_states_all_four_pieces_in_order(): void
    {
        $contract = $this->makeContract([
            'schedule_mode' => ScheduleMode::Fixed,
            'work_hours_from' => '09:00',
            'work_hours_to' => '17:00',
            'work_hours_2_from' => '18:00',
            'work_hours_2_to' => '20:00',
            'work_day_from' => 0,
            'work_day_to' => 4,
            'show_hours_per_week' => true,
            'show_hours_per_day' => true,
            'show_days_per_week' => true,
            'show_day_range' => true,
            'weekly_hours' => 50,
        ]);

        $html = $this->renderClause($contract);

        $this->assertStringContainsString(
            '50 working hours</strong> per week, <strong>10 hours</strong> per day, <strong>5 days</strong> per week.',
            $html,
        );
        $this->assertStringContainsString('Sunday to Thursday', $html);
        $this->assertStringContainsString('9:00 AM', $html);
        $this->assertStringContainsString('5:00 PM', $html);
        // Second shift appended to the same sentence.
        $this->assertStringContainsString('6:00 PM', $html);
        $this->assertStringContainsString('8:00 PM', $html);
        // Arabic column: ص / م, not Latin AM/PM.
        $this->assertStringContainsString('9:00 ص', $html);
        $this->assertStringContainsString('5:00 م', $html);
        $this->assertStringContainsString('6:00 م', $html);
        $this->assertStringContainsString('8:00 م', $html);
    }

    public function test_flexible_mode_lists_only_the_checked_fragments(): void
    {
        $contract = $this->makeContract([
            'schedule_mode' => ScheduleMode::Flexible,
            'flex_hours_per_week' => 25,
            'flex_varies_by_requirements' => true,
            'flex_coordinated_with_manager' => false,
            'flex_days_per_week' => null,
            'flex_hours_per_month' => null,
        ]);

        $html = $this->renderClause($contract);

        $this->assertStringContainsString('25 hours per week', $html);
        $this->assertStringContainsString('hours vary according to the requirements of the job', $html);
        $this->assertStringNotContainsString('coordinated with the direct manager', $html);
        $this->assertStringNotContainsString('days per week', $html);
    }

    public function test_flexible_mode_custom_text_is_included(): void
    {
        $contract = $this->makeContract([
            'schedule_mode' => ScheduleMode::Flexible,
            'flex_custom_en' => 'Hours depend on the season.',
            'flex_custom_ar' => 'تعتمد الساعات على الموسم.',
        ]);

        $html = $this->renderClause($contract);

        $this->assertStringContainsString('Hours depend on the season', $html);
    }

    public function test_fixed_mode_hours_per_day_reflects_the_actual_office_hours_span(): void
    {
        // 4h + 4h second shift = 8h/day — not weekly_hours / 5, which the
        // stale pre-Phase-5 templates always used regardless of the real
        // shift span.
        $contract = $this->makeContract([
            'schedule_mode' => ScheduleMode::Fixed,
            'work_hours_from' => '09:00',
            'work_hours_to' => '13:00',
            'work_hours_2_from' => '17:00',
            'work_hours_2_to' => '21:00',
            'work_day_from' => 0,
            'work_day_to' => 4,
            'show_hours_per_day' => true,
            'weekly_hours' => 40,
        ]);

        $html = $this->renderClause($contract);

        $this->assertStringContainsString('<strong>8 hours</strong> per day', $html);
    }

    private function renderClause(Contract $contract, bool $forPdf = false): string
    {
        return view('components.contract.schedule-clause', [
            'contract' => $contract,
            'forPdf' => $forPdf,
        ])->render();
    }

    private function makeContract(array $schedule): Contract
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

        $employee = $this->makeEmployee($city, 'clause-test-employee-'.uniqid());

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
            'pay_mode' => 1,
            'currency' => 1,
            'salary_per' => 1,
        ], $schedule));
    }
}
