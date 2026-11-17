<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestStatus as Status;
use App\Enums\RecruitmentRequestType as Type;
use App\Models\Position;
use App\Models\RecruitmentRequest;
use App\Models\Vacancy;
use App\Services\RecruitmentRequestApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWorkflowFixtures;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * What an approved request does to the organisation, and when.
 *
 * Approval and execution are separate, so the interesting cases are as much
 * about *not* acting — a closure dated ahead, a request applied twice — as
 * about acting.
 */
class RecruitmentApplicationTest extends TestCase
{
    use BuildsWorkflowFixtures, CreatesHrFixtures, RefreshDatabase;

    private RecruitmentRequestApplicationService $applications;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->applications = app(RecruitmentRequestApplicationService::class);
    }

    public function test_filling_a_vacancy_opens_a_seat_when_none_exists(): void
    {
        ['request' => $request, 'position' => $position] = $this->approved(Type::FillVacancy);

        $applied = $this->applications->apply($request);

        $this->assertSame(Status::Applied, (int) $applied->status);
        $this->assertNotNull($applied->applied_at);

        $vacancy = Vacancy::query()->where('position_id', $position->id)->sole();
        $this->assertSame(Vacancy::OPEN, $vacancy->status);
        $this->assertSame($vacancy->id, (int) $applied->target_vacancy_id);

        // The establishment is untouched: the seat was already paid for.
        $this->assertSame(2, (int) $position->fresh()->max_emp);
    }

    /** One vacancy needing three people — the request may only produce one row. */
    public function test_increasing_the_headcount_widens_the_post_and_opens_the_seats(): void
    {
        ['request' => $request, 'position' => $position] =
            $this->approved(Type::IncreaseHeadcount, ['additional_count' => 3]);

        $this->applications->apply($request);

        $this->assertSame(5, (int) $position->fresh()->max_emp);

        $vacancy = Vacancy::query()->where('position_id', $position->id)->sole();
        $this->assertSame(3, (int) $vacancy->needed_count);
        $this->assertSame(0, (int) $vacancy->filled_count);

        $this->assertDatabaseHas('recruitment_request_events', [
            'recruitment_request_id' => $request->id,
            'event_type' => RecruitmentEventType::HeadcountChanged,
        ]);
    }

    /** The one rule that cannot bend, re-checked at execution and not only at approval. */
    public function test_a_reduction_below_the_people_in_the_post_fails_without_changing_it(): void
    {
        ['request' => $request, 'position' => $position] = $this->approved(
            Type::DecreaseHeadcount,
            ['reduce_by' => 2, 'reason' => 'Budget', 'effective_date' => now()->toDateString()],
        );
        $position->forceFill(['exist_emp' => 2])->save();

        $applied = $this->applications->apply($request);

        $this->assertSame(Status::FailedApplication, (int) $applied->status);
        $this->assertSame(2, (int) $position->fresh()->max_emp, 'A failed application changes nothing.');

        $this->assertDatabaseHas('recruitment_request_events', [
            'recruitment_request_id' => $request->id,
            'event_type' => RecruitmentEventType::RequestApplicationFailed,
        ]);
    }

    /** Emptiest first, newest first — hiring already underway is not stranded. */
    public function test_a_reduction_closes_the_emptiest_newest_surplus_seats(): void
    {
        ['request' => $request, 'position' => $position] = $this->approved(
            Type::DecreaseHeadcount,
            ['reduce_by' => 1, 'reason' => 'Budget', 'effective_date' => now()->toDateString()],
        );

        $partlyFilled = $this->seat($request, $position, filled: 1);
        $olderEmpty = $this->seat($request, $position, filled: 0);
        $newerEmpty = $this->seat($request, $position, filled: 0);

        // One person is in the post, so the reduced establishment of one has
        // no room left for either empty seat.
        $position->forceFill(['exist_emp' => 1])->save();

        $this->applications->apply($request);

        $this->assertSame(1, (int) $position->fresh()->max_emp);
        $this->assertSame(Vacancy::CLOSED, $newerEmpty->fresh()->status);
        $this->assertSame(Vacancy::CLOSED, $olderEmpty->fresh()->status);
        $this->assertSame(Vacancy::OPEN, $partlyFilled->fresh()->status,
            'A seat with somebody hired against it is the last to go.');
    }

    public function test_creating_a_position_writes_it_and_its_seats_together(): void
    {
        ['request' => $request, 'position' => $parent, 'city' => $city] = $this->approved(Type::CreatePosition);

        $request->forceFill(['target_position_id' => null])->save();
        $this->rewriteSnapshot($request, [
            'request_type' => Type::CreatePosition,
            'program_id' => $parent->program_id,
            'city_id' => $city->id,
            'job_level_id' => $parent->job_level_id,
            'department_id' => $parent->department_id,
            'direct_manager_id' => $parent->id,
            'title_en' => 'Field Officer',
            'title_ar' => 'ضابط ميداني',
            'qualifications' => 'A degree.',
            'responsibilities' => 'The work.',
            'max_emp' => 2,
        ]);

        $applied = $this->applications->apply($request->fresh());

        $this->assertSame(Status::Applied, (int) $applied->status);

        $created = Position::query()->where('title_en', 'Field Officer')->sole();
        $this->assertTrue((bool) $created->is_active);
        $this->assertSame(0, (int) $created->exist_emp);
        $this->assertSame($created->id, (int) $applied->target_position_id);

        // One vacancy standing for the whole establishment.
        $vacancy = Vacancy::query()->where('position_id', $created->id)->sole();
        $this->assertSame(2, (int) $vacancy->needed_count);
    }

    public function test_closing_a_position_is_refused_while_somebody_is_in_it(): void
    {
        ['request' => $request, 'position' => $position] = $this->approved(
            Type::ClosePosition,
            ['reason' => 'Programme ended', 'effective_date' => now()->toDateString()],
        );
        $position->forceFill(['exist_emp' => 1])->save();

        $applied = $this->applications->apply($request);

        $this->assertSame(Status::FailedApplication, (int) $applied->status);
        $this->assertTrue((bool) $position->fresh()->is_active);
    }

    public function test_closing_a_position_retires_it_and_all_its_seats(): void
    {
        ['request' => $request, 'position' => $position] = $this->approved(
            Type::ClosePosition,
            ['reason' => 'Programme ended', 'effective_date' => now()->toDateString()],
        );
        $seat = $this->seat($request, $position);
        $this->emptyThePost($position);

        $this->applications->apply($request);

        $this->assertFalse((bool) $position->fresh()->is_active);
        $this->assertSame(Vacancy::CLOSED, $seat->fresh()->status);
        $this->assertNotNull($seat->fresh()->closed_at);
    }

    public function test_closing_a_vacant_position_removes_one_approved_seat_and_its_card(): void
    {
        ['request' => $request, 'position' => $position] = $this->approved(
            Type::CloseVacancy,
            ['reason' => 'No longer needed', 'effective_date' => now()->toDateString()],
        );
        $seat = $this->seat($request, $position);
        $this->rewriteSnapshot($request, [
            'request_type' => Type::CloseVacancy,
            'reason' => 'No longer needed',
            'effective_date' => now()->toDateString(),
        ]);

        $this->applications->apply($request->fresh());

        $this->assertSame(Vacancy::CLOSED, $seat->fresh()->status);
        $this->assertSame('No longer needed', $seat->fresh()->close_reason);
        $this->assertTrue((bool) $position->fresh()->is_active);
        $this->assertSame(1, (int) $position->fresh()->max_emp);
    }

    /** Approved is not carried out. A future date means the post stays as it is. */
    public function test_a_closure_dated_ahead_waits_and_changes_nothing(): void
    {
        ['request' => $request, 'position' => $position] = $this->approved(
            Type::ClosePosition,
            ['reason' => 'Next quarter', 'effective_date' => now()->addMonth()->toDateString()],
        );

        $applied = $this->applications->apply($request);

        $this->assertSame(Status::Approved, (int) $applied->status);
        $this->assertNull($applied->applied_at);
        $this->assertTrue((bool) $position->fresh()->is_active);
        $this->assertTrue($this->applications->isAwaitingEffectiveDate($applied));
        $this->assertFalse($this->applications->isDue($applied));
    }

    /** HR may bring it forward, and the record says they did. */
    public function test_hr_can_execute_before_the_effective_date_and_it_is_recorded_as_early(): void
    {
        ['request' => $request, 'position' => $position, 'hr' => $hr] = $this->approved(
            Type::ClosePosition,
            ['reason' => 'Next quarter', 'effective_date' => now()->addMonth()->toDateString()],
        );

        $this->emptyThePost($position);

        $applied = $this->applications->apply($request, $hr, force: true);

        $this->assertSame(Status::Applied, (int) $applied->status);
        $this->assertFalse((bool) $position->fresh()->is_active);

        $event = $request->events()->where('event_type', RecruitmentEventType::RequestApplied)->sole();
        $this->assertTrue((bool) ($event->meta['early'] ?? false));
        $this->assertSame('user', $event->meta['triggered_by'] ?? null);
        $this->assertSame($hr->id, (int) $event->actor_user_id);
        $this->assertSame($hr->myRole(), $event->actor_role);
    }

    /** The scheduled run picks it up once the date has passed, as nobody. */
    public function test_the_scheduled_run_applies_it_once_the_date_has_arrived(): void
    {
        ['request' => $request, 'position' => $position] = $this->approved(
            Type::ClosePosition,
            ['reason' => 'Done', 'effective_date' => now()->subDay()->toDateString()],
        );

        $this->emptyThePost($position);

        $this->artisan('recruitment:apply-due')->assertSuccessful();

        $this->assertSame(Status::Applied, (int) $request->fresh()->status);
        $this->assertFalse((bool) $position->fresh()->is_active);

        $event = $request->events()->where('event_type', RecruitmentEventType::RequestApplied)->sole();
        $this->assertSame('schedule', $event->meta['triggered_by'] ?? null);
        $this->assertNull($event->actor_user_id);
        $this->assertSame('system', $event->actor_role);
    }

    /** Running it twice must not widen the post twice. */
    public function test_applying_twice_changes_nothing_the_second_time(): void
    {
        ['request' => $request, 'position' => $position] =
            $this->approved(Type::IncreaseHeadcount, ['additional_count' => 1]);

        $this->applications->apply($request);
        $this->applications->apply($request->fresh());

        $this->assertSame(3, (int) $position->fresh()->max_emp);
        $this->assertSame(1, Vacancy::query()->where('position_id', $position->id)->count());
        $this->assertSame(1, $request->events()
            ->where('event_type', RecruitmentEventType::RequestApplied)->count());
    }

    /** A request that is not approved is not applied, whatever asks. */
    public function test_a_request_still_under_review_is_not_applied(): void
    {
        ['request' => $request, 'position' => $position] = $this->approved(Type::IncreaseHeadcount, ['additional_count' => 1]);
        $request->forceFill(['status' => Status::UnderReview])->save();

        $applied = $this->applications->apply($request->fresh());

        $this->assertSame(Status::UnderReview, (int) $applied->status);
        $this->assertSame(2, (int) $position->fresh()->max_emp);
    }

    /** A failure leaves the approval standing, and the retry succeeds. */
    public function test_a_failed_application_can_be_retried(): void
    {
        ['request' => $request, 'position' => $position] = $this->approved(
            Type::ClosePosition,
            ['reason' => 'Ended', 'effective_date' => now()->toDateString()],
        );
        $position->forceFill(['exist_emp' => 1])->save();

        $this->assertSame(Status::FailedApplication, (int) $this->applications->apply($request)->status);

        $this->emptyThePost($position);

        $this->assertSame(Status::Applied, (int) $this->applications->apply($request->fresh())->status);
        $this->assertFalse((bool) $position->fresh()->is_active);
    }

    /**
     * An approved request of the given type, with its snapshot written.
     *
     * @param  array<string, mixed>  $payload
     * @return array{request: RecruitmentRequest, position: Position, hr: \App\Models\User, city: \App\Models\City}
     */
    private function approved(int $type, array $payload = []): array
    {
        $fixture = $this->workflow();
        $request = $fixture['request'];

        $request->forceFill([
            'request_type' => $type,
            'status' => Status::Approved,
            'applied_at' => null,
        ])->save();

        $this->rewriteSnapshot($request, ['request_type' => $type] + $payload + ['position_id' => $fixture['position']->id]);

        return [
            'request' => $request->fresh(),
            'position' => $fixture['position'],
            'hr' => $fixture['hr'],
            'city' => $fixture['position']->city,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function rewriteSnapshot(RecruitmentRequest $request, array $payload): void
    {
        $request->versions()->orderByDesc('version_no')->first()
            ?->forceFill(['snapshot_json' => $payload])->save();
    }

    /**
     * Clear the post of people.
     *
     * The workflow fixture seats its requester on this position, and a post
     * with somebody in it is never closed — which is the rule under test
     * elsewhere, and in the way here.
     */
    private function emptyThePost(Position $position): void
    {
        $position->contracts()->update(['is_active' => false]);

        // Written through the query rather than the model: the observer moved
        // `exist_emp` after this instance was loaded, so the copy in memory
        // still says 0 and saving 0 over it would be no change at all.
        Position::query()->whereKey($position->getKey())->update(['exist_emp' => 0]);
    }

    /**
     * A seat on the position, standing from some earlier request.
     *
     * Each one needs a request of its own: `created_from_request_id` is unique,
     * so several seats on a post are several requests' worth of history.
     */
    private function seat(RecruitmentRequest $request, Position $position, int $filled = 0, int $needed = 1): Vacancy
    {
        $origin = RecruitmentRequest::query()->create([
            'request_number' => 'RR-'.uniqid(),
            'requester_user_id' => $request->requester_user_id,
            'request_type' => Type::FillVacancy,
            'mode' => $request->mode,
            'status' => Status::Applied,
            'scope_city_id' => $request->scope_city_id,
            'target_position_id' => $position->id,
        ]);

        return Vacancy::query()->create([
            'position_id' => $position->id,
            'created_from_request_id' => $origin->id,
            'status' => Vacancy::OPEN,
            'needed_count' => $needed,
            'filled_count' => $filled,
            'open_at' => now()->subDay(),
        ]);
    }
}
