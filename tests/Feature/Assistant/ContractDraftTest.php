<?php

namespace Tests\Feature\Assistant;

use App\Enums\Currency;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\User;
use App\Services\Assistant\Drafts\ContractDraft;
use App\Services\Assistant\Drafts\DraftRegistry;
use App\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class ContractDraftTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private const TOOLS = ['find_employee_for_contract', 'get_contract_options', 'draft_contract'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-02 10:00:00');
        Notification::fake();
        $this->mock(LeaveBalanceService::class)->shouldReceive('create')->andReturnNull();
    }

    public function test_no_tools_without_the_create_permission(): void
    {
        $f = $this->fixtures();
        // Another role than the fixtures' HR-ALP, which holds contracts.create.
        $user = $this->makeUser($f['hms'], 'HR-HMS', ['contracts.view']);

        $this->assertTrue($user->canViewSalary());

        $this->assertSame([], $this->draft()->declarations($user));
        $this->assertNull(app(DraftRegistry::class)->owner($user, 'draft_contract'));
    }

    public function test_no_tools_for_a_salary_blind_role(): void
    {
        $f = $this->fixtures();
        $admin = $this->makeUser($f['alp'], 'super-admin', ['contracts.create']);

        $this->assertTrue($admin->hasActivePermission('contracts.create'));
        $this->assertSame([], $this->draft()->declarations($admin));
        $this->assertArrayHasKey('error', $this->draft()->call($admin, 'draft_contract', $this->args($f)));
    }

    public function test_hr_with_the_permission_gets_the_three_tools(): void
    {
        $f = $this->fixtures();

        $this->assertSame(self::TOOLS, array_column($this->draft()->declarations($f['hr']), 'name'));
        $this->assertInstanceOf(ContractDraft::class, app(DraftRegistry::class)->owner($f['hr'], 'draft_contract'));
    }

    public function test_options_and_search_stay_in_the_users_cities(): void
    {
        $f = $this->fixtures();
        $this->makeEmployee($f['hms'], 'rami-homs', ['first_name_en' => 'Rami', 'last_name_en' => 'Homsi']);
        $full = $this->makePosition($f, $f['alpCenter'], 'Full Accountant', maxEmp: 1, existEmp: 1);

        $found = $this->draft()->call($f['hr'], 'find_employee_for_contract', ['name' => 'Rami']);
        $this->assertSame([$f['employee']->id], array_column($found['employees'], 'id'));

        $options = $this->draft()->call($f['hr'], 'get_contract_options', []);
        $positionIds = array_column($options['positions'], 'id');
        $this->assertContains($f['position']->id, $positionIds);
        $this->assertNotContains($f['hmsPosition']->id, $positionIds);
        $this->assertNotContains($full->id, $positionIds);
        $this->assertSame([$f['alp']->id], array_column($options['cities'], 'id'));
    }

    public function test_a_valid_draft_writes_nothing(): void
    {
        $f = $this->fixtures();

        $result = $this->draft()->call($f['hr'], 'draft_contract', $this->args($f));

        $this->assertSame('drafted', $result['status'], json_encode($result));
        $this->assertSame($f['employee']->id, $result['proposal']['employee_id']);
        $this->assertSame('2026-12-01', $result['proposal']['start_date']);
        $this->assertSame('2026-12-31', $result['proposal']['end_date']);
        $this->assertSame(40, $result['proposal']['weekly_hours']);
        $this->assertStringContainsString('USD', $result['proposal']['pay']);
        $this->assertSame(0, Contract::count());
        $this->assertSame(0, $f['position']->fresh()->exist_emp);

        $card = $this->draft()->card($result['proposal']);
        $this->assertSame(__('assistant-contract.title'), $card['title']);
        $this->assertContains('Rami Khoury', $card['rows']);
    }

    public function test_a_position_outside_the_users_city_is_refused(): void
    {
        $f = $this->fixtures();

        $result = $this->draft()->call($f['hr'], 'draft_contract', $this->args($f, ['position_id' => $f['hmsPosition']->id]));
        $this->assertSame('invalid', $result['status']);
        $this->assertContains(__('contracts.errors.position-not-in-city'), $result['errors']);

        $result = $this->draft()->call($f['hr'], 'draft_contract', $this->args($f, [
            'position_id' => $f['hmsPosition']->id,
            'city_id' => $f['hms']->id,
        ]));
        $this->assertSame('invalid', $result['status']);
        $this->assertContains(__('contracts.errors.city-not-allowed'), $result['errors']);

        $this->assertSame(0, Contract::count());
        $this->assertSame(0, $f['hmsPosition']->fresh()->exist_emp);
    }

    public function test_an_employee_outside_the_users_city_is_refused(): void
    {
        $f = $this->fixtures();
        $other = $this->makeEmployee($f['hms'], 'homs-employee');

        $result = $this->draft()->call($f['hr'], 'draft_contract', $this->args($f, ['employee_id' => $other->id]));

        $this->assertSame('invalid', $result['status']);
        $this->assertContains(__('assistant-contract.errors.employee'), $result['errors']);
    }

    public function test_invalid_values_return_the_screens_errors_and_write_nothing(): void
    {
        $f = $this->fixtures();

        $missingPay = $this->draft()->call($f['hr'], 'draft_contract', $this->args($f, ['salary' => null]));
        $this->assertSame('invalid', $missingPay['status']);

        $tooLong = $this->draft()->call($f['hr'], 'draft_contract', $this->args($f, ['end_date' => '2028-06-30']));
        $this->assertSame('invalid', $tooLong['status']);

        $full = $this->makePosition($f, $f['alpCenter'], 'Full Accountant', maxEmp: 1, existEmp: 1);
        $noSeat = $this->draft()->call($f['hr'], 'draft_contract', $this->args($f, ['position_id' => $full->id]));
        $this->assertContains(__('contracts.errors.position-full'), $noSeat['errors']);

        $noDays = $this->draft()->call($f['hr'], 'draft_contract', $this->args($f, ['work_day_from' => null]));
        $this->assertContains(__('assistant-contract.errors.days'), $noDays['errors']);

        $this->assertSame(0, Contract::count());
        $this->assertSame(0, $f['position']->fresh()->exist_emp);
        $this->assertSame(1, $full->fresh()->exist_emp);
    }

    public function test_submit_creates_the_contract_like_the_screen(): void
    {
        $f = $this->fixtures();
        $proposal = $this->draft()->call($f['hr'], 'draft_contract', $this->args($f))['proposal'];

        $result = $this->draft()->submit($f['hr'], $proposal, 'token-1');

        $contract = Contract::query()->sole();
        $this->assertSame(route('contracts.show', $contract), $result['url']);
        $this->assertSame($f['employee']->id, $contract->employee_id);
        $this->assertSame($f['position']->id, $contract->position_id);
        $this->assertSame('300.00', $contract->salary);
        $this->assertSame(Currency::USD, $contract->currency);
        $this->assertSame(0, $contract->work_day_from);
        $this->assertSame(4, $contract->work_day_to);
        $this->assertSame(1, $f['position']->fresh()->exist_emp);

        // Encrypted at rest, never the plain figure.
        $raw = DB::table('contracts')->where('id', $contract->id)->value('salary');
        $this->assertStringNotContainsString('300', (string) $raw);
    }

    public function test_the_same_token_twice_creates_one_contract(): void
    {
        $f = $this->fixtures();
        $proposal = $this->draft()->call($f['hr'], 'draft_contract', $this->args($f))['proposal'];

        $first = $this->draft()->submit($f['hr'], $proposal, 'token-1');
        $second = $this->draft()->submit($f['hr'], $proposal, 'token-1');

        $this->assertSame($first['url'], $second['url']);
        $this->assertSame(1, Contract::count());
        $this->assertSame(1, $f['position']->fresh()->exist_emp);
    }

    public function test_submit_is_refused_once_the_permission_is_gone(): void
    {
        $f = $this->fixtures();
        $proposal = $this->draft()->call($f['hr'], 'draft_contract', $this->args($f))['proposal'];

        Role::findByName('HR-ALP', 'web')->revokePermissionTo('contracts.create');
        $hr = User::query()->find($f['hr']->id);

        try {
            $this->draft()->submit($hr, $proposal, 'token-1');
            $this->fail('The contract was created without the permission.');
        } catch (ValidationException $e) {
            $this->assertContains(__('assistant.draft.forbidden'), array_merge(...array_values($e->errors())));
        }

        $this->assertSame(0, Contract::count());
        $this->assertSame(0, $f['position']->fresh()->exist_emp);
    }

    public function test_submit_is_refused_for_a_proposal_edited_to_another_city(): void
    {
        $f = $this->fixtures();
        $proposal = $this->draft()->call($f['hr'], 'draft_contract', $this->args($f))['proposal'];
        $proposal['position_id'] = $f['hmsPosition']->id;

        $this->expectException(ValidationException::class);

        try {
            $this->draft()->submit($f['hr'], $proposal, 'token-1');
        } finally {
            $this->assertSame(0, Contract::count());
            $this->assertSame(0, $f['hmsPosition']->fresh()->exist_emp);
        }
    }

    // ────────────────────────────────────────────────

    private function draft(): ContractDraft
    {
        return app(ContractDraft::class);
    }

    private function args(array $f, array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $f['employee']->id,
            'position_id' => $f['position']->id,
            'contract_type_id' => $f['annualType']->id,
            'start_date' => '2026-12-01',
            'work_day_from' => 0,
            'work_day_to' => 4,
            'salary' => 300,
            'currency' => Currency::USD,
        ], $overrides);
    }

    private function fixtures(): array
    {
        $alp = $this->makeCity('ALP', '001');
        $hms = $this->makeCity('HMS', '002');

        $alpCenter = $this->makeCenter($alp, 'Aleppo Center');
        $hmsCenter = $this->makeCenter($hms, 'Homs Center');

        $f = compact('alp', 'hms', 'alpCenter', 'hmsCenter');
        $f['department'] = Department::query()->forceCreate(['name' => 'Finance', 'code' => 'FIN']);
        $f['jobLevel'] = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $f['program'] = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => 'CORE', 'is_active' => true]);

        $f['position'] = $this->makePosition($f, $alpCenter, 'Accountant');
        $f['hmsPosition'] = $this->makePosition($f, $hmsCenter, 'Homs Accountant');

        $f['employee'] = $this->makeEmployee($alp, 'rami-khoury', ['first_name_en' => 'Rami', 'last_name_en' => 'Khoury']);
        $f['annualType'] = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        $f['hr'] = $this->makeUser($alp, 'HR-ALP', ['contracts.create', 'contracts.view']);

        return $f;
    }

    private function makeCenter($city, string $name): Center
    {
        return Center::query()->create([
            'name_en' => $name,
            'name_ar' => $name,
            'city_id' => $city->id,
            'is_active' => true,
            'weekends' => 'friday',
            'open_at' => '08:00:00',
            'close_at' => '16:00:00',
        ]);
    }

    private function makePosition(array $f, Center $center, string $title, int $maxEmp = 5, int $existEmp = 0): Position
    {
        return Position::query()->forceCreate([
            'center_id' => $center->id,
            'city_id' => $center->city_id,
            'department_id' => $f['department']->id,
            'job_level_id' => $f['jobLevel']->id,
            'program_id' => $f['program']->id,
            'title_en' => $title,
            'title_ar' => $title,
            'max_emp' => $maxEmp,
            'exist_emp' => $existEmp,
            'is_active' => true,
        ]);
    }
}
