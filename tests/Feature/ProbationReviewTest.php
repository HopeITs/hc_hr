<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Livewire\Probation\Review;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\ProbationReview;
use App\Models\Program;
use App\Models\User;
use App\Notifications\ProbationFinalThird;
use App\Notifications\ProbationRequested;
use App\Services\ProbationReviews;
use App\Services\WorkInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Probation review (meeting 19/11, track 4): a trial in its last third asks the
 * direct manager what to request from HR. The answer is a request with a
 * mandatory reason; the contract itself is never changed.
 */
class ProbationReviewTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
        // Trial 2026-09-01 → 2026-12-01 is 91 days: the last third starts after 60, on 2026-10-31.
        $this->travelTo('2026-10-30 10:00:00');
        $this->f = $this->fixtures();
    }

    // ── Entering the last third ──────────────────────────────────────

    public function test_the_review_starts_on_the_first_day_of_the_last_third_and_only_for_a_running_trial(): void
    {
        $trial = $this->trial('due');
        $annual = $this->contract('annual', ['contract_type_id' => $this->f['annualType']->id]);
        $terminated = $this->trial('terminated', ['terminated_at' => '2026-10-01']);
        $draft = $this->trial('draft', ['type' => ContractTypeEnum::Draft]);

        $this->assertSame('2026-10-31', app(ProbationReviews::class)->lastThirdStart($trial)->toDateString());

        $this->artisan('probation:start-reviews')->assertSuccessful();
        $this->assertSame(0, ProbationReview::query()->count(), 'One day before the last third.');

        $this->travelTo('2026-10-31 10:00:00');
        $this->artisan('probation:start-reviews')->assertSuccessful();

        $this->assertSame([$trial->id], ProbationReview::query()->pluck('contract_id')->all());
        $this->assertSame(0, ProbationReview::query()->whereIn('contract_id', [$annual->id, $terminated->id, $draft->id])->count());
        $this->assertNull(ProbationReview::query()->sole()->decision);
    }

    public function test_it_is_idempotent_and_tells_the_volunteer_the_manager_and_city_hr_once(): void
    {
        $trial = $this->trial('due');

        $this->travelTo('2026-10-31 10:00:00');
        $this->artisan('probation:start-reviews')->assertSuccessful();
        $this->artisan('probation:start-reviews')->assertSuccessful();

        $this->assertSame(1, ProbationReview::query()->count());
        $this->assertNull(app(ProbationReviews::class)->startFor($trial->fresh()), 'Unique per contract.');

        foreach (['employee' => $trial->employee->account ?? null, 'manager' => $this->f['manager'], 'hr' => $this->f['hr']] as $audience => $user) {
            if ($user === null) {
                continue;
            }

            Notification::assertSentToTimes($user, ProbationFinalThird::class, 1);
            Notification::assertSentTo($user, ProbationFinalThird::class, fn ($n) => $n->audience === $audience && $n->reminder === false);
        }

        $this->assertSame(1, Activity::query()->where('log_name', 'probation')->where('description', 'probation_started')->count());
    }

    public function test_the_manager_is_reminded_every_seven_days_until_they_answer(): void
    {
        $this->trial('due');
        $this->travelTo('2026-10-31 10:00:00');
        $this->artisan('probation:start-reviews')->assertSuccessful();

        $this->travelTo('2026-11-06 10:00:00');
        $this->artisan('probation:start-reviews')->assertSuccessful();
        $this->assertReminders(0);

        $this->travelTo('2026-11-07 10:00:00');
        $this->artisan('probation:start-reviews')->assertSuccessful();
        $this->artisan('probation:start-reviews')->assertSuccessful();
        $this->assertReminders(1);

        $this->travelTo('2026-11-14 10:00:00');
        $this->artisan('probation:start-reviews')->assertSuccessful();
        $this->assertReminders(2);

        $this->assertSame(0, $this->remindersTo($this->f['hr']), 'Only the manager is reminded.');
    }

    public function test_no_reminder_once_answered(): void
    {
        $this->trial('due');
        $this->travelTo('2026-10-31 10:00:00');
        $this->artisan('probation:start-reviews')->assertSuccessful();

        app(ProbationReviews::class)->request(ProbationReview::query()->sole(), ProbationReview::EXTEND, 'Needs more time', $this->f['manager']);

        $this->travelTo('2026-11-07 10:00:00');
        $this->artisan('probation:start-reviews')->assertSuccessful();

        $this->assertReminders(0);
    }

    public function test_a_trial_is_never_set_to_renew_by_itself(): void
    {
        $trial = $this->trial('due');

        $this->assertFalse($trial->canAutoRenew());
    }

    // ── The decision: a request to HR, with the reason ───────────────

    public function test_each_of_the_three_requests_is_recorded_and_tells_hr_without_touching_the_contract(): void
    {
        foreach (ProbationReview::DECISIONS as $i => $decision) {
            $contract = $this->trial("decide{$i}");
            $before = $contract->fresh()->only(['end_date', 'is_active', 'terminated_at', 'termination_reason_id']);
            $review = $this->review($contract);

            Livewire::actingAs($this->f['manager'])
                ->test(Review::class, ['review' => $review])
                ->set('decision', $decision)
                ->set('reason', "Because {$decision}")
                ->call('send')
                ->assertHasNoErrors();

            $review->refresh();
            $this->assertSame($decision, $review->decision);
            $this->assertSame("Because {$decision}", $review->reason);
            $this->assertSame($this->f['manager']->id, (int) $review->decided_by);
            $this->assertNotNull($review->decided_at);

            $this->assertEquals($before, $contract->fresh()->only(array_keys($before)), 'Nothing is applied to the contract.');
            $this->assertSame(0, $contract->amendments()->count());
            $this->assertSame(1, Contract::query()->where('employee_id', $contract->employee_id)->count(), 'No new contract.');

            Notification::assertSentTo($this->f['hr'], ProbationRequested::class, fn ($n) => $n->review->is($review));
        }

        $this->assertSame(3, Activity::query()->where('log_name', 'probation')->where('description', 'probation_requested')->count());
    }

    public function test_the_reason_is_required_for_every_request(): void
    {
        $review = $this->review($this->trial('reason'));

        foreach (ProbationReview::DECISIONS as $decision) {
            foreach (['', '   '] as $blank) {
                Livewire::actingAs($this->f['manager'])
                    ->test(Review::class, ['review' => $review])
                    ->set('decision', $decision)
                    ->set('reason', $blank)
                    ->call('send')
                    ->assertHasErrors('reason');
            }
        }

        Livewire::actingAs($this->f['manager'])
            ->test(Review::class, ['review' => $review])
            ->set('reason', 'A reason')
            ->call('send')
            ->assertHasErrors('decision');

        $this->assertNull($review->fresh()->decision);
        Notification::assertNotSentTo($this->f['hr'], ProbationRequested::class);
    }

    public function test_the_service_refuses_a_blank_reason_and_an_unknown_request(): void
    {
        $review = $this->review($this->trial('service'));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ProbationReviews::class)->request($review, ProbationReview::EXTEND, '  ', $this->f['manager']);
    }

    public function test_a_second_answer_is_refused(): void
    {
        $review = $this->review($this->trial('twice'));
        $service = app(ProbationReviews::class);

        $this->assertTrue($service->request($review, ProbationReview::EXTEND, 'First', $this->f['manager']));
        $this->assertFalse($service->request($review->fresh(), ProbationReview::CANCEL, 'Second', $this->f['manager']));

        $review->refresh();
        $this->assertSame(ProbationReview::EXTEND, $review->decision);
        $this->assertSame('First', $review->reason);
        Notification::assertSentToTimes($this->f['hr'], ProbationRequested::class, 1);
    }

    public function test_only_the_direct_manager_may_answer(): void
    {
        $review = $this->review($this->trial('who'));
        $stranger = $this->makeUser($this->f['city'], 'volunteer', []);
        $otherCityHr = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', ['employees.update']);

        foreach ([$stranger, $otherCityHr] as $user) {
            Livewire::actingAs($user)->test(Review::class, ['review' => $review])->assertForbidden();
            $this->assertFalse(app(ProbationReviews::class)->request($review, ProbationReview::EXTEND, 'x', $user));
        }

        $this->assertNull($review->fresh()->decision);
    }

    public function test_the_volunteer_cannot_answer_their_own_review(): void
    {
        $trial = $this->trial('self');
        $review = $this->review($trial);
        $own = $this->makeUser($this->f['city'], 'volunteer', [], $trial->employee);

        Livewire::actingAs($own)->test(Review::class, ['review' => $review])->assertForbidden();
    }

    public function test_city_hr_reads_the_request_and_marks_it_seen_but_does_not_answer_while_a_manager_runs_the_position(): void
    {
        $review = $this->review($this->trial('hr'));

        Livewire::actingAs($this->f['hr'])
            ->test(Review::class, ['review' => $review])
            ->assertOk()
            ->assertDontSee('data-testid="probation-form"', false);

        $this->assertNull($review->fresh()->hr_seen_at, 'Nothing to see before the manager answers.');

        app(ProbationReviews::class)->request($review, ProbationReview::CONFIRM, 'Doing well', $this->f['manager']);

        Livewire::actingAs($this->f['hr'])
            ->test(Review::class, ['review' => $review])
            ->assertSee('Doing well')
            ->assertSee(__('probation.decision.confirm'));

        $this->assertNotNull($review->fresh()->hr_seen_at);
    }

    public function test_hr_answers_when_nobody_runs_the_managers_position(): void
    {
        $orphan = Position::query()->forceCreate([
            'center_id' => $this->f['position']->center_id, 'city_id' => $this->f['city']->id,
            'department_id' => $this->f['position']->department_id, 'job_level_id' => $this->f['position']->job_level_id,
            'program_id' => $this->f['position']->program_id, 'title_en' => 'Lead', 'title_ar' => 'قائد',
            'max_emp' => 5, 'exist_emp' => 0, 'is_active' => true,
        ]);
        $trial = $this->trial('orphan', ['position_id' => $this->orphanReport($orphan)->id]);
        $review = $this->review($trial);

        Livewire::actingAs($this->f['hr'])
            ->test(Review::class, ['review' => $review])
            ->set('decision', ProbationReview::CANCEL)
            ->set('reason', 'No manager in place')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(ProbationReview::CANCEL, $review->fresh()->decision);
    }

    // ── Where it shows ───────────────────────────────────────────────

    public function test_the_managers_inbox_lists_the_pending_review_and_nobody_elses_does(): void
    {
        $review = $this->review($this->trial('inbox'));

        $snapshot = app(WorkInbox::class)->snapshot($this->f['manager']);
        $row = $snapshot['rows']->firstWhere('source', 'probation');

        $this->assertNotNull($row);
        $this->assertSame("probation:{$review->id}:journey", $row['key']);
        $this->assertSame(route('probation.show', $review), $row['url']);
        $this->assertSame(1, $snapshot['counts']['journey']);
        $this->assertSame(1, $snapshot['counts']['all']);

        $this->assertNull(app(WorkInbox::class)->snapshot($this->f['hr'])['rows']->firstWhere('source', 'probation'));

        app(ProbationReviews::class)->request($review, ProbationReview::EXTEND, 'More time', $this->f['manager']);

        $this->assertNull(app(WorkInbox::class)->snapshot($this->f['manager'])['rows']->firstWhere('source', 'probation'));
    }

    public function test_the_contract_page_card_shows_the_review_state(): void
    {
        $trial = $this->trial('card');
        $review = $this->review($trial);

        $this->actingAs($this->f['hr'])
            ->get(route('contracts.show', ['locale' => 'en', 'contract' => $trial->id]))
            ->assertOk()
            ->assertSee('data-testid="contract-probation"', false)
            ->assertSee(__('probation.status.waiting'));

        app(ProbationReviews::class)->request($review, ProbationReview::EXTEND, 'More time', $this->f['manager']);

        $this->actingAs($this->f['hr'])
            ->get(route('contracts.show', ['locale' => 'en', 'contract' => $trial->id]))
            ->assertSee(__('probation.decision.extend'));
    }

    public function test_notifications_carry_both_languages_and_the_category(): void
    {
        $review = $this->review($this->trial('texts'));
        app(ProbationReviews::class)->request($review, ProbationReview::EXTEND, 'More time', $this->f['manager']);

        $data = (new ProbationRequested($review->fresh()))->toArray($this->f['hr']);

        $this->assertSame(['en', 'ar'], array_keys($data['title']));
        $this->assertStringContainsString('More time', $data['body']['en']);
        $this->assertSame(\App\Enums\NotificationCategory::Contracts, \App\Enums\NotificationCategory::forNotification(new ProbationRequested($review)));
        $this->assertSame(\App\Enums\NotificationCategory::Contracts, \App\Enums\NotificationCategory::forNotification(new ProbationFinalThird($review)));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function assertReminders(int $count): void
    {
        $this->assertSame($count, $this->remindersTo($this->f['manager']));
    }

    private function remindersTo(User $user): int
    {
        return Notification::sent($user, ProbationFinalThird::class)->filter(fn ($n) => $n->reminder)->count();
    }

    private function review(Contract $contract): ProbationReview
    {
        app(ProbationReviews::class)->startFor($contract);

        return ProbationReview::query()->where('contract_id', $contract->id)->firstOrFail();
    }

    private function trial(string $username, array $overrides = []): Contract
    {
        return $this->contract($username, array_merge([
            'contract_type_id' => $this->f['trialType']->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-01',
        ], $overrides));
    }

    private function contract(string $username, array $overrides = []): Contract
    {
        $employee = $this->makeEmployee($this->f['city'], 'probation.'.$username);

        return Contract::query()->create(array_merge([
            'employee_id' => $employee->id,
            'position_id' => $this->f['position']->id,
            'contract_type_id' => $this->f['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 2,
            'salary' => 987654,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '17:00:00',
            'is_active' => true,
        ], $overrides));
    }

    /** A position reporting to a manager's position nobody runs. */
    private function orphanReport(Position $managerPosition): Position
    {
        return Position::query()->forceCreate([
            'center_id' => $managerPosition->center_id, 'city_id' => $managerPosition->city_id,
            'department_id' => $managerPosition->department_id, 'job_level_id' => $managerPosition->job_level_id,
            'program_id' => $managerPosition->program_id, 'title_en' => 'Report', 'title_ar' => 'تابع',
            'max_emp' => 5, 'exist_emp' => 0, 'is_active' => true, 'direct_manager_id' => $managerPosition->id,
        ]);
    }

    private function fixtures(): array
    {
        $city = $this->makeCity('ALP', '001');
        $center = Center::query()->create([
            'name_en' => 'Hope Center', 'name_ar' => 'مركز الرجاء', 'city_id' => $city->id,
            'is_active' => true, 'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);
        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $program = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => 'CORE', 'is_active' => true]);
        $base = [
            'center_id' => $center->id, 'city_id' => $city->id, 'department_id' => $department->id,
            'job_level_id' => $jobLevel->id, 'program_id' => $program->id,
            'max_emp' => 50, 'exist_emp' => 0, 'is_active' => true,
        ];
        $managerPosition = Position::query()->forceCreate($base + ['title_en' => 'Manager', 'title_ar' => 'مدير']);
        $position = Position::query()->forceCreate($base + [
            'title_en' => 'Program Officer', 'title_ar' => 'مسؤول برنامج', 'direct_manager_id' => $managerPosition->id,
        ]);

        $annualType = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        $trialType = ContractType::query()->forceCreate(['id' => 3, 'name_en' => 'Trial', 'name_ar' => 'تجريبي']);

        $managerEmployee = $this->makeEmployee($city, 'probation.manager');
        Contract::query()->create([
            'employee_id' => $managerEmployee->id, 'position_id' => $managerPosition->id,
            'contract_type_id' => $annualType->id, 'type' => ContractTypeEnum::FullTime, 'place' => 1,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency' => 2, 'salary' => 1000,
            'salary_per' => 1, 'weekly_hours' => 40, 'work_hours_from' => '09:00:00', 'work_hours_to' => '17:00:00',
            'is_active' => true,
        ]);
        $manager = $this->makeUser($city, 'manager', [], $managerEmployee);
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.update', 'contracts.view', 'contracts.create', 'employees.update']);

        return compact('city', 'position', 'annualType', 'trialType', 'manager', 'hr');
    }
}
