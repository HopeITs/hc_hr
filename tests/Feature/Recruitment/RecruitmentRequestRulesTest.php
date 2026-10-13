<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentRequestType as Type;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Services\RecruitmentRequestDraftService;
use App\Services\RecruitmentRequestNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\BuildsRecruitmentRequests;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The rules the form applies to what is typed into it.
 *
 * Dates that cannot be met and titles in the wrong alphabet are the two things
 * people actually get wrong here, and neither is caught by anything downstream:
 * a post needed by yesterday still submits, still routes, and still reaches an
 * approver who has to work out for themselves that it is nonsense.
 */
class RecruitmentRequestRulesTest extends TestCase
{
    use BuildsRecruitmentRequests, CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    /** A post needed by yesterday is a post nobody can still be recruited for. */
    public function test_the_needed_by_date_must_be_in_the_future(): void
    {
        [$user, $payload] = $this->requester();

        foreach (['needed_by_date' => now()->subDay(), 'today' => now()] as $label => $date) {
            try {
                $this->draft($user, ['needed_by_date' => $date->toDateString()] + $payload);
                $this->fail("A date of {$label} should be refused.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('data.needed_by_date', $exception->errors());
            }
        }

        $accepted = $this->draft($user, [
            'needed_by_date' => now()->addWeek()->toDateString(),
            'volunteer_period_start_date' => now()->addWeek()->toDateString(),
        ] + $payload);

        $this->assertNotNull($accepted->id);
    }

    /** Volunteering cannot begin before the date the post was needed by. */
    public function test_the_period_cannot_start_before_the_needed_by_date(): void
    {
        [$user, $payload] = $this->requester();

        try {
            $this->draft($user, [
                'needed_by_date' => now()->addMonth()->toDateString(),
                'volunteer_period_start_date' => now()->addWeek()->toDateString(),
            ] + $payload);
            $this->fail('A period starting before the need should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.volunteer_period_start_date', $exception->errors());
        }

        // The same day is fine: needed by then, starting then.
        $sameDay = now()->addMonth()->toDateString();
        $this->assertNotNull($this->draft($user, [
            'needed_by_date' => $sameDay,
            'volunteer_period_start_date' => $sameDay,
            'volunteer_period_end_date' => now()->addMonths(6)->toDateString(),
        ] + $payload)->id);
    }

    /** One alphabet each: the mistake is typing a title into the wrong field. */
    public function test_each_title_accepts_only_its_own_alphabet(): void
    {
        [$user, $payload] = $this->requester(Type::CreatePosition);

        foreach ([
            'title_en' => 'ضابط ميداني',
            'title_ar' => 'Field Officer',
        ] as $field => $wrongAlphabet) {
            try {
                $this->draft($user, [$field => $wrongAlphabet] + $payload, Type::CreatePosition);
                $this->fail("{$field} should refuse the other alphabet.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('data.'.$field, $exception->errors());
            }
        }

        // Letters and spaces, each in its own script.
        $this->assertNotNull($this->draft($user, [
            'title_en' => 'Field Officer',
            'title_ar' => 'ضابط ميداني',
        ] + $payload, Type::CreatePosition)->id);
    }

    /** Numbers are not letters, and the rule says letters and spaces. */
    public function test_a_title_with_digits_is_refused(): void
    {
        [$user, $payload] = $this->requester(Type::CreatePosition);

        $this->expectException(ValidationException::class);

        $this->draft($user, ['title_en' => 'Field Officer 2'] + $payload, Type::CreatePosition);
    }

    public function test_the_generic_volunteer_contract_cannot_be_selected_for_recruitment(): void
    {
        [$user, $payload] = $this->requester();
        $generic = ContractType::query()->create([
            'name_en' => 'Volunteer Contract',
            'name_ar' => 'عقد تطوّع',
        ]);

        try {
            $this->draft($user, ['contract_type_id' => $generic->id] + $payload);
            $this->fail('The generic volunteer contract should not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.contract_type_id', $exception->errors());
        }
    }

    /**
     * The number reads as a governorate, a programme and a count.
     *
     * Short enough to say over the telephone, which the ULID it replaced was
     * not.
     */
    public function test_the_request_number_is_short_and_speakable(): void
    {
        [$user, $payload] = $this->requester();

        $first = $this->draft($user, $payload);
        $second = $this->draft($user, $payload);

        $this->assertMatchesRegularExpression('/^[A-Z]{2}-\d{3}$/', $first->request_number);
        $this->assertSame('-001', substr($first->request_number, -4));
        $this->assertSame('-002', substr($second->request_number, -4));

        // Same governorate and programme, so the same prefix.
        $this->assertSame(
            substr($first->request_number, 0, 2),
            substr($second->request_number, 0, 2),
        );
    }

    /** Three digits is the shape, not the ceiling. */
    public function test_the_counter_widens_rather_than_running_out(): void
    {
        [$user, $payload] = $this->requester();

        $existing = $this->draft($user, $payload);
        $prefix = substr($existing->request_number, 0, 2);

        $existing->forceFill(['request_number' => $prefix.'-999'])->save();

        $next = $this->draft($user, $payload);

        $this->assertSame($prefix.'-1000', $next->request_number);
    }

    /** A name with no Latin initial still produces a usable number. */
    public function test_a_name_without_a_latin_initial_falls_back(): void
    {
        $number = app(RecruitmentRequestNumber::class)->next(null, null);

        $this->assertSame('XX-001', $number);
    }

    /** @param array<string, mixed> $payload */
    private function draft(User $user, array $payload, int $type = Type::FillVacancy): RecruitmentRequest
    {
        return app(RecruitmentRequestDraftService::class)->create($user, ['request_type' => $type] + $payload);
    }

    /**
     * A requester with a post beneath them, and a payload aimed at it.
     *
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function requester(int $type = Type::FillVacancy): array
    {
        $city = City::query()->where('slug', 'SYR')->first() ?? $this->makeCity('SYR', '000');
        $user = $this->makeUser($city, 'manager');
        $user->givePermissionTo(Permission::findOrCreate('recruitment-requests.submit-without-manager', 'web'));

        $program = Program::query()->create([
            'name' => 'Study Zone', 'code' => 'SZ', 'slug' => 'sz-'.uniqid(),
            'start_date' => now()->subYear(), 'is_active' => true,
        ]);

        $root = Position::query()->create([
            'city_id' => $city->id,
            'program_id' => $program->id,
            'department_id' => Department::query()->create(['name' => 'Dept', 'code' => strtoupper(uniqid('D'))])->id,
            'job_level_id' => JobLevel::query()->create(['name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 3])->id,
            'title_en' => 'Manager', 'title_ar' => 'مدير',
            'is_active' => true, 'max_emp' => 9, 'exist_emp' => 0,
        ]);

        Contract::query()->create([
            'employee_id' => $user->employee_id, 'position_id' => $root->id,
            'contract_type_id' => $this->recruitmentContractTypeId(),
            'start_date' => now()->subMonth(), 'end_date' => now()->addYear(),
            'currency' => 2, 'salary_per' => 1, 'weekly_hours' => 40, 'is_active' => true,
        ]);

        $target = $root->replicate();
        $target->fill(['direct_manager_id' => $root->id, 'title_en' => 'Analyst', 'exist_emp' => 0])->save();

        // Remote work needs no centre, which keeps this fixture to the fields
        // the titles are actually being tested through.
        $payload = $this->recruitmentFormDefaults(
            $type,
            $type === Type::CreatePosition ? ['volunteer_type' => 'remote'] : [],
        );

        $payload += $type === Type::CreatePosition
            ? [
                'direct_manager_id' => $target->id,
                'city_id' => $city->id,
                'program_id' => $program->id,
                'department_id' => $root->department_id,
                // Strictly junior to the requester's own grade: nobody
                // creates a peer or a superior for themselves.
                'job_level_id' => JobLevel::query()->create([
                    'name_en' => 'Officer', 'name_ar' => 'موظف', 'grade' => 7,
                ])->id,
            ]
            : ['position_id' => $target->id];

        return [$user->fresh(), $payload];
    }
}
