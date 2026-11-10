<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentInterviewStatus as Status;
use App\Enums\RecruitmentInterviewType;
use App\Livewire\Recruitment\Interviews\Board;
use App\Livewire\Recruitment\Interviews\Index;
use App\Models\City;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewParticipant as Participant;
use App\Models\Role;
use App\Models\User;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentInterviewEvaluationService;
use App\Services\RecruitmentInterviewSchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The interviews board and the briefing above it: which lane an interview
 * sits in, that the lanes only ever hold what the user may see, the filters,
 * and that no meeting link reaches a card.
 *
 * The clock is frozen on 2026-10-05 09:00 (Europe/Istanbul).
 */
class RecruitmentInterviewBoardTest extends TestCase
{
    use BuildsRecruitmentApplications, CreatesHrFixtures, RefreshDatabase;

    private const MEETING_URL = 'https://meet.example.test/j/secret-room-4711';

    private City $homs;

    private User $hr;

    private User $interviewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'Europe/Istanbul'));

        $this->homs = $this->makeCity('HMS', '002');
        $this->hr = $this->hr($this->homs);
        $this->interviewer = $this->makeUser($this->homs, 'employee');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Lanes
    // ------------------------------------------------------------------

    public function test_an_interview_moves_through_the_lanes(): void
    {
        $interview = $this->schedule($this->shortlisted('Candidate Moving'));
        $cancelled = $this->cancel($this->schedule($this->shortlisted('Candidate Cancelled'), ['startsAt' => '2026-10-09T10:00']));
        $this->actingAs($this->hr);

        $this->assertLanes(['upcoming' => [$interview->id], 'closed' => [$cancelled->id]]);

        // Under way: still upcoming until its end time.
        $this->travelTo(Carbon::parse('2026-10-08 10:30:00'));
        $this->assertLanes(['upcoming' => [$interview->id], 'closed' => [$cancelled->id]]);

        // Over, with no outcome yet: awaiting evaluation.
        $this->travelTo(Carbon::parse('2026-10-08 11:30:00'));
        $this->assertLanes(['awaiting' => [$interview->id], 'closed' => [$cancelled->id]]);

        // Completed, evaluation still open: still awaiting.
        $interview = $this->outcome($interview, Status::Completed);
        $this->assertLanes(['awaiting' => [$interview->id], 'closed' => [$cancelled->id]]);

        // HR closes evaluation: held.
        app(RecruitmentInterviewEvaluationService::class)->close($this->hr, $interview, Status::Completed);
        $this->assertLanes(['held' => [$interview->id], 'closed' => [$cancelled->id]]);
    }

    public function test_a_no_show_is_in_the_closed_lane(): void
    {
        $interview = $this->schedule($this->shortlisted('Candidate Absent'));
        $this->travelTo(Carbon::parse('2026-10-08 10:15:00'));
        $this->outcome($interview, Status::NoShow);
        $this->actingAs($this->hr);

        $this->assertLanes(['closed' => [$interview->id]]);
    }

    public function test_the_evaluations_closed_scope_follows_the_auto_close_policy(): void
    {
        config(['recruitment.evaluations.auto_close_days' => 3]);
        $interview = $this->schedule($this->shortlisted());
        $this->travelTo(Carbon::parse('2026-10-08 11:30:00'));
        $interview = $this->outcome($interview, Status::Completed);

        $this->assertClosedByScope($interview, false);

        // Exactly three days after the outcome: not yet (the model is strict too).
        $this->travelTo(Carbon::parse('2026-10-11 11:30:00'));
        $this->assertClosedByScope($interview, false);

        $this->travelTo(Carbon::parse('2026-10-12 11:30:00'));
        $this->assertClosedByScope($interview, true);
        $this->actingAs($this->hr);
        $this->assertLanes(['held' => [$interview->id]]);

        // Without the policy, only HR closes it.
        config(['recruitment.evaluations.auto_close_days' => null]);
        $this->assertClosedByScope($interview, false);
        config(['recruitment.evaluations.auto_close_days' => 3]);

        // Completed with no recorded outcome time: never auto-closed, and the
        // negated scope does not lose it to a NULL comparison.
        RecruitmentInterview::query()->whereKey($interview->id)->update(['outcome_recorded_at' => null]);
        $this->assertClosedByScope($interview, false);
    }

    // ------------------------------------------------------------------
    // Visibility
    // ------------------------------------------------------------------

    public function test_a_participant_sees_only_their_own_interviews_and_rounds(): void
    {
        $application = $this->shortlisted('Candidate Shared');
        $hrRound = $this->schedule($application, ['startsAt' => '2026-10-07T10:00']);
        $theirs = $this->schedule($application->fresh(), [
            'type' => RecruitmentInterviewType::Technical,
            'participants' => [$this->interviewer->id => Participant::ROLE_INTERVIEWER],
        ]);
        $other = $this->schedule($this->shortlisted('Candidate Other'), ['startsAt' => '2026-10-09T10:00']);

        $this->actingAs($this->interviewer);
        $board = Livewire::test(Board::class)
            ->assertSee('Candidate Shared')
            ->assertDontSee('Candidate Other');
        $lanes = $board->viewData('lanes');

        $this->assertSame([$theirs->id], $this->allKeys($lanes));
        $card = $lanes['upcoming']['items']->first();
        $this->assertSame([$theirs->id], $card->application->interviews->modelKeys(), 'The round track only shows rounds this user may see.');
        $this->assertNotContains($hrRound->id, $card->application->interviews->modelKeys());
        $this->assertNotContains($other->id, $card->application->interviews->modelKeys());

        // HR in scope sees both rounds on the card.
        $this->actingAs($this->hr);
        $card = Livewire::test(Board::class)->viewData('lanes')['upcoming']['items']->firstWhere('id', $theirs->id);
        $this->assertSame([$hrRound->id, $theirs->id], $card->application->interviews->modelKeys());
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    public function test_the_filters_narrow_the_lanes_and_clear(): void
    {
        $accountant = $this->shortlisted('Candidate Numbers', title: 'Accountant');
        $hrOne = $this->schedule($this->shortlisted('Candidate Alpha'), ['participants' => [$this->hr->id => Participant::ROLE_INTERVIEWER]]);
        $technical = $this->schedule($this->shortlisted('Candidate Beta'), ['type' => RecruitmentInterviewType::Technical, 'startsAt' => '2026-10-09T10:00']);
        $finance = $this->schedule($accountant, ['startsAt' => '2026-10-10T10:00']);
        $this->actingAs($this->hr);

        $board = Livewire::test(Board::class);
        $this->assertSame([$hrOne->id, $technical->id, $finance->id], $this->allKeys($board->viewData('lanes')));

        $board->set('type', RecruitmentInterviewType::Technical);
        $this->assertSame([$technical->id], $this->allKeys($board->viewData('lanes')));

        $board->set('type', '')->set('search', 'Alpha');
        $this->assertSame([$hrOne->id], $this->allKeys($board->viewData('lanes')));

        // The position title matches too.
        $board->set('search', 'Accoun');
        $this->assertSame([$finance->id], $this->allKeys($board->viewData('lanes')));

        // LIKE wildcards are literal.
        $board->set('search', '%');
        $this->assertSame([], $this->allKeys($board->viewData('lanes')));

        $board->set('search', '')->set('mine', true);
        $this->assertSame([$hrOne->id], $this->allKeys($board->viewData('lanes')));

        $board->set('mine', false)->set('vacancy', (string) $accountant->vacancy_id);
        $this->assertSame([$finance->id], $this->allKeys($board->viewData('lanes')));

        $board->set('type', RecruitmentInterviewType::Technical)->set('search', 'Beta')->set('mine', true)
            ->call('clearFilters')
            ->assertSet('type', '')->assertSet('search', '')->assertSet('vacancy', '')->assertSet('mine', false);
        $this->assertSame([$hrOne->id, $technical->id, $finance->id], $this->allKeys($board->viewData('lanes')));

        // Unknown values are ignored rather than trusted.
        $board->set('type', 'lunch')->set('vacancy', '1 or 1=1');
        $this->assertSame([$hrOne->id, $technical->id, $finance->id], $this->allKeys($board->viewData('lanes')));
    }

    public function test_show_more_grows_a_lane_and_the_limits_are_locked(): void
    {
        $this->actingAs($this->hr);

        Livewire::test(Board::class)
            ->assertSet('limits.upcoming', 8)
            ->call('showMore', 'upcoming')
            ->assertSet('limits.upcoming', 24)
            ->call('showMore', 'nonsense')
            ->assertSet('limits', ['upcoming' => 24, 'awaiting' => 8, 'held' => 8, 'closed' => 8]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Board::class)->set('limits.upcoming', 100000);
    }

    // ------------------------------------------------------------------
    // Privacy
    // ------------------------------------------------------------------

    public function test_the_meeting_link_never_reaches_the_board_and_only_allowed_users_see_it_in_the_briefing(): void
    {
        $this->schedule($this->shortlisted(), ['participants' => [$this->interviewer->id => Participant::ROLE_INTERVIEWER]]);

        // The board never carries it, whoever is looking.
        $this->actingAs($this->hr);
        Livewire::test(Board::class)->assertSee('Candidate One')->assertDontSee(self::MEETING_URL);
        $this->actingAs($this->interviewer);
        Livewire::test(Board::class)->assertSee('Candidate One')->assertDontSee(self::MEETING_URL);

        // A participant: in their briefing.
        Livewire::test(Index::class)
            ->assertViewHas('nextMeetingUrl', self::MEETING_URL)
            ->assertSee(self::MEETING_URL);

        // HR in scope who may not schedule and is not on it: not even in the briefing.
        $reader = $this->without($this->hr, ['recruitment-applicants.schedule-interviews']);
        $this->actingAs($reader);
        Livewire::test(Index::class)
            ->assertViewHas('nextMeetingUrl', null)
            ->assertDontSee(self::MEETING_URL);
        Livewire::test(Board::class)->assertDontSee(self::MEETING_URL);
    }

    // ------------------------------------------------------------------
    // Briefing
    // ------------------------------------------------------------------

    public function test_the_briefing_shows_the_viewers_next_interview_and_waiting_evaluations(): void
    {
        $sooner = $this->schedule($this->shortlisted('Candidate Sooner'), ['startsAt' => '2026-10-06T10:00']);
        $mine = $this->schedule($this->shortlisted('Candidate Mine'), ['participants' => [$this->hr->id => Participant::ROLE_INTERVIEWER]]);
        $this->schedule($this->shortlisted('Candidate Later'), [
            'startsAt' => '2026-10-10T10:00',
            'participants' => [$this->hr->id => Participant::ROLE_INTERVIEWER],
        ]);

        // Their own next interview, even though another in scope is sooner.
        $this->actingAs($this->hr);
        $index = Livewire::test(Index::class)->assertViewHas('nextIsMine', true);
        $this->assertTrue($index->viewData('next')->is($mine));

        // HR with none of their own: the next one in scope, flagged as not theirs.
        $colleague = $this->hr($this->homs);
        $this->actingAs($colleague);
        $index = Livewire::test(Index::class)->assertViewHas('nextIsMine', false);
        $this->assertTrue($index->viewData('next')->is($sooner));

        // Waiting evaluations count what awaitingEvaluationBy() finds.
        $this->travelTo(Carbon::parse('2026-10-08 10:30:00'));
        $this->actingAs($this->hr);
        $this->assertSame([$mine->id], RecruitmentInterview::query()->awaitingEvaluationBy($this->hr)->pluck('id')->all());
        Livewire::test(Index::class)->assertViewHas('waitingCount', 1);
        $this->actingAs($colleague);
        Livewire::test(Index::class)->assertViewHas('waitingCount', 0);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param  array<string, array<int, int>>  $expected  lanes not named must be empty */
    private function assertLanes(array $expected): void
    {
        $lanes = Livewire::test(Board::class)->viewData('lanes');

        foreach (Board::LANES as $lane) {
            $this->assertSame($expected[$lane] ?? [], $lanes[$lane]['items']->modelKeys(), "Lane '{$lane}'");
            $this->assertSame(count($expected[$lane] ?? []), $lanes[$lane]['count'], "Lane '{$lane}' count");
        }
    }

    /** The scope agrees with the model method, in both directions. */
    private function assertClosedByScope(RecruitmentInterview $interview, bool $closed): void
    {
        $this->assertSame($closed, $interview->fresh()->evaluationsClosed(), 'Model');
        $this->assertSame($closed, RecruitmentInterview::query()->whereKey($interview->id)->evaluationsClosed()->exists(), 'Scope (closed)');
        $this->assertSame(! $closed, RecruitmentInterview::query()->whereKey($interview->id)->evaluationsClosed(false)->exists(), 'Scope (open)');
    }

    /** @return array<int, int> every interview on the board, sorted */
    private function allKeys(array $lanes): array
    {
        return collect($lanes)->flatMap(fn (array $lane) => $lane['items']->modelKeys())->sort()->values()->all();
    }

    private function hr(City $city): User
    {
        return $this->makeUser($city, 'HR-'.$city->slug, [
            'recruitment-campaigns.view',
            ...array_map(
                fn (string $action): string => "recruitment-applicants.{$action}",
                config('access-control.groups.recruitment-applicants'),
            ),
        ]);
    }

    private function without(User $user, array $permissions): User
    {
        Role::findByName($user->myRole(), 'web')->revokePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function service(): RecruitmentInterviewSchedulingService
    {
        return app(RecruitmentInterviewSchedulingService::class);
    }

    private function shortlisted(string $name = 'Candidate One', string $title = 'IT Specialist'): RecruitmentApplication
    {
        $application = $this->applyTo($this->makeCampaignIn($this->homs, title: $title), $this->makeApplicant($name));

        $statuses = app(RecruitmentApplicationStatusService::class);
        $statuses->change($this->hr, $application, AppStatus::Submitted, AppStatus::UnderReview, null);
        $statuses->change($this->hr, $application->fresh(), AppStatus::UnderReview, AppStatus::Shortlisted, null);

        return $application->fresh();
    }

    private function schedule(RecruitmentApplication $application, array $overrides = []): RecruitmentInterview
    {
        return $this->service()->schedule($this->hr, $application, array_merge([
            'type' => RecruitmentInterviewType::Hr,
            'startsAt' => '2026-10-08T10:00',
            'duration' => 60,
            'locationType' => 'online',
            'locationValue' => 'Microsoft Teams',
            'meetingUrl' => self::MEETING_URL,
            'participants' => [],
        ], $overrides));
    }

    private function cancel(RecruitmentInterview $interview): RecruitmentInterview
    {
        $interview = $interview->fresh();

        return $this->service()->cancel($this->hr, $interview, $interview->status, $interview->scheduled_at->toIso8601String(), 'candidate_declined', null)->fresh();
    }

    private function outcome(RecruitmentInterview $interview, string $outcome): RecruitmentInterview
    {
        $interview = $interview->fresh();

        return $this->service()->recordOutcome($this->hr, $interview, $interview->status, $interview->scheduled_at->toIso8601String(), $outcome, null)->fresh();
    }
}
