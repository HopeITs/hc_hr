<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Exports\LeavingReasonsExport;
use App\Livewire\Reports\LeavingReasons;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\TerminationReason;
use App\Services\LeavingReasonsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/** Why people leave: reasons recorded on the contracts of people who left. */
class LeavingReasonsReportTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $alp;

    private City $hms;

    private Position $position;

    private TerminationReason $resigned;

    private TerminationReason $relocated;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo('2026-11-23 10:00:00');

        $this->alp = $this->makeCity('ALP', '001');
        $this->hms = $this->makeCity('HMS', '002');
        $center = Center::query()->create([
            'name_en' => 'Hope Center', 'name_ar' => 'مركز الرجاء', 'city_id' => $this->alp->id,
            'is_active' => true, 'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);
        $this->position = Position::query()->forceCreate([
            'title_en' => 'Officer', 'title_ar' => 'مسؤول', 'center_id' => $center->id, 'city_id' => $this->alp->id,
            'department_id' => Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG'])->id,
            'job_level_id' => JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2])->id,
            'program_id' => Program::query()->forceCreate(['name' => 'Core', 'slug' => 'core', 'code' => 'CORE', 'is_active' => true])->id,
            'max_emp' => 50, 'exist_emp' => 0, 'is_active' => true,
        ]);
        ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);

        $this->resigned = TerminationReason::query()->create(['title_en' => 'Resigned', 'title_ar' => 'استقالة']);
        $this->relocated = TerminationReason::query()->create(['title_en' => 'Relocated', 'title_ar' => 'انتقال']);
    }

    public function test_it_counts_one_row_per_person_with_the_reasons_cities_and_the_gaps(): void
    {
        $this->left('a', $this->alp, '2026-10-01', $this->resigned);
        $this->left('b', $this->alp, '2026-10-15', $this->resigned);
        $this->left('c', $this->hms, '2026-09-10', $this->relocated);
        $this->left('d', $this->hms, '2026-09-20', null);
        $this->left('old', $this->alp, '2024-01-01', $this->resigned);
        $this->contract($this->makeEmployee($this->alp, 'still-here'), ['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $r = (new LeavingReasonsReport($this->headOffice(), 365))->build();

        $this->assertSame(4, $r['total'], 'Only people who left inside the period; "old" and the one still here are out.');
        $this->assertSame(3, $r['recorded']);
        $this->assertSame(1, $r['missing']);
        $this->assertSame(['Resigned' => 2, 'Relocated' => 1], $r['byReason']->pluck('count', 'label')->all());
        $this->assertSame(['ALP' => 2, 'HMS' => 2], $r['byCity']->pluck('count', 'label')->sort()->all());
        $this->assertSame(['2026-09' => 2, '2026-10' => 2], $r['monthly']->all());
        $this->assertCount(1, $r['withoutReason']);

        $this->assertSame(5, (new LeavingReasonsReport($this->headOffice(), 0))->build()['total'], 'All time includes the old one.');
    }

    public function test_someone_who_left_and_came_back_is_not_counted_as_leaving(): void
    {
        $employee = $this->makeEmployee($this->alp, 'returner');
        $this->contract($employee, ['start_date' => '2026-01-01', 'end_date' => '2026-06-30', 'terminated_at' => '2026-06-30', 'termination_reason_id' => $this->resigned->id, 'is_active' => false]);
        $this->contract($employee, ['start_date' => '2026-09-01', 'end_date' => '2027-03-01']);

        $this->assertSame(0, (new LeavingReasonsReport($this->headOffice(), 365))->build()['total']);
    }

    public function test_a_city_hr_sees_only_their_own_city(): void
    {
        $this->left('a', $this->alp, '2026-10-01', $this->resigned);
        $this->left('c', $this->hms, '2026-09-10', $this->relocated);

        $hrAlp = $this->makeUser($this->alp, 'HR-ALP', ['contracts.view']);

        $r = (new LeavingReasonsReport($hrAlp, 365))->build();

        $this->assertSame(1, $r['total']);
        $this->assertSame(['ALP'], $r['byCity']->pluck('label')->all());
    }

    public function test_only_hr_and_head_office_open_the_page_and_the_export(): void
    {
        $this->left('a', $this->alp, '2026-10-01', $this->resigned);

        Livewire::actingAs($this->headOffice())->test(LeavingReasons::class)->assertOk()->assertSee('Resigned');
        Livewire::actingAs($this->makeUser($this->alp, 'HR-ALP', ['contracts.view']))->test(LeavingReasons::class)->assertOk();

        foreach (['manager', 'employee'] as $role) {
            Livewire::actingAs($this->makeUser($this->alp, $role, ['contracts.view']))->test(LeavingReasons::class)->assertForbidden();
        }

        $this->actingAs($this->makeUser($this->alp, 'manager', ['contracts.view']))
            ->get(route('reports.leaving-reasons'))->assertForbidden();
    }

    public function test_the_export_carries_the_same_figures(): void
    {
        $this->left('a', $this->alp, '2026-10-01', $this->resigned);

        $sheets = (new LeavingReasonsExport((new LeavingReasonsReport($this->headOffice(), 365))->build()))->sheets();

        $this->assertSame(['Summary', 'By reason', 'By city', 'By month', 'No reason'], array_map(fn ($s) => $s->title(), $sheets));
        $this->assertSame(['Leavers', 1], $sheets[0]->array()[1]);
    }

    private function headOffice()
    {
        return $this->makeUser($this->alp, 'super-admin', ['contracts.view']);
    }

    private function left(string $username, City $city, string $on, ?TerminationReason $reason)
    {
        return $this->contract($this->makeEmployee($city, 'leaver.'.$username), [
            'start_date' => '2025-01-01',
            'end_date' => $on,
            'terminated_at' => $on,
            'termination_reason_id' => $reason?->id,
            'is_active' => false,
        ]);
    }

    private function contract($employee, array $overrides = []): Contract
    {
        return Contract::query()->create(array_merge([
            'employee_id' => $employee->id,
            'position_id' => $this->position->id,
            'contract_type_id' => 1,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'currency' => 2,
            'salary' => 1000,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '17:00:00',
            'is_active' => true,
        ], $overrides));
    }
}
