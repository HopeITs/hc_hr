<?php

namespace Tests\Feature\Recruitment;

use App\Enums\HiringCampaignStatus as Status;
use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Models\HiringCampaign;
use App\Models\RecruitmentRequestEvent;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\HiringCampaignService;
use App\Services\HiringCampaignStateMachine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsPublishedCampaignAssets;
use Tests\Concerns\BuildsWorkflowFixtures;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * A campaign's life, and the two moments that matter in it.
 *
 * Everything here turns on when a campaign becomes public and when it stops
 * being so. The tests that carry the most weight are the ones about the clock:
 * a campaign whose window has run out must refuse an application whatever its
 * status column still says, because the column is written by a nightly sweep
 * and an applicant does not wait for the morning.
 */
class HiringCampaignTest extends TestCase
{
    use BuildsPublishedCampaignAssets, BuildsWorkflowFixtures, CreatesHrFixtures, RefreshDatabase;

    private HiringCampaignService $campaigns;

    private HiringCampaignStateMachine $states;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->campaigns = app(HiringCampaignService::class);
        $this->states = app(HiringCampaignStateMachine::class);
    }

    public function test_hr_can_create_a_campaign_on_an_open_vacancy(): void
    {
        [$hr, $vacancy] = $this->seat();

        $campaign = $this->campaigns->create($hr, $vacancy, [
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addMonth()->toDateTimeString(),
        ]);

        $this->assertSame(Status::Draft, (int) $campaign->status);
        $this->assertSame($vacancy->id, (int) $campaign->vacancy_id);
        $this->assertSame($hr->id, (int) $campaign->created_by_user_id);

        // Nothing is public yet: a draft is a 404 to a stranger (D48).
        $this->assertFalse($this->campaigns->isPubliclyVisible($campaign));
        $this->assertFalse($this->campaigns->acceptsSubmissions($campaign));
    }

    /** A seat nobody is recruiting for should not be advertised. */
    public function test_a_campaign_cannot_be_created_on_a_closed_vacancy(): void
    {
        [$hr, $vacancy] = $this->seat();
        $vacancy->forceFill(['status' => Vacancy::CLOSED, 'closed_at' => now()])->save();

        $this->expectException(ValidationException::class);

        $this->campaigns->create($hr, $vacancy->fresh());
    }

    /** Several campaigns may run on one seat — a public one and an invited one. */
    public function test_more_than_one_campaign_may_run_on_a_vacancy(): void
    {
        [$hr, $vacancy] = $this->seat();

        $this->campaigns->create($hr, $vacancy);
        $this->campaigns->create($hr, $vacancy->fresh());

        $this->assertSame(2, HiringCampaign::query()->where('vacancy_id', $vacancy->id)->count());
    }

    /** C2: there has to be something to read and something to fill in. */
    public function test_publishing_needs_a_job_post_and_a_form(): void
    {
        [$hr, $vacancy] = $this->seat();
        $campaign = $this->campaigns->create($hr, $vacancy);

        try {
            $this->campaigns->publish($hr, $campaign);
            $this->fail('A campaign with nothing to read should not publish.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('campaign', $exception->errors());
            $this->assertSame(Status::Draft, (int) $campaign->fresh()->status);
        }
    }

    public function test_hr_can_set_and_correct_the_window(): void
    {
        [$hr, $vacancy] = $this->seat();
        $campaign = $this->campaigns->create($hr, $vacancy);

        $campaign = $this->campaigns->setWindow($hr, $campaign, [
            'starts_at' => now()->addDays(2)->toDateTimeString(),
            'ends_at' => now()->addDays(20)->toDateTimeString(),
        ]);

        $this->assertTrue($campaign->ends_at->isFuture());

        // An end before the start is not a window at all.
        try {
            $this->campaigns->setWindow($hr, $campaign->fresh(), [
                'starts_at' => now()->addDays(10)->toDateTimeString(),
                'ends_at' => now()->addDays(3)->toDateTimeString(),
            ]);
            $this->fail('An end before the start should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ends_at', $exception->errors());
        }

        // And an end already past would create something born expired.
        try {
            $this->campaigns->setWindow($hr, $campaign->fresh(), [
                'ends_at' => now()->subDay()->toDateTimeString(),
            ]);
            $this->fail('An end in the past should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ends_at', $exception->errors());
        }
    }

    public function test_date_windows_store_the_start_of_the_start_date_and_end_of_the_end_date(): void
    {
        [$hr, $vacancy] = $this->seat();
        $start = now()->addDays(2)->toDateString();
        $end = now()->addDays(20)->toDateString();

        $campaign = $this->campaigns->create($hr, $vacancy, [
            'starts_at' => $start,
            'ends_at' => $end,
        ]);

        $this->assertSame($start.' 00:00:00', $campaign->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($end.' 23:59:59', $campaign->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_opening_then_closing_a_campaign(): void
    {
        [$hr, $campaign] = $this->openCampaign();

        $this->assertTrue($this->campaigns->acceptsSubmissions($campaign));

        $closed = $this->campaigns->close($hr, $campaign, 'Enough applicants.');

        $this->assertSame(Status::Closed, (int) $closed->status);
        $this->assertSame($hr->id, (int) $closed->closed_by_user_id);

        // The advert stays readable; only applications stop (D3 · D48).
        $this->assertTrue($this->campaigns->isPubliclyVisible($closed));
        $this->assertFalse($this->campaigns->acceptsSubmissions($closed));
    }

    /**
     * The clock decides, not the column.
     *
     * This is the one that matters. The sweep that writes expiry runs nightly;
     * an applicant arriving a minute after the window shuts must be refused
     * then, not the next morning.
     */
    public function test_a_campaign_whose_window_ran_out_refuses_submissions_before_any_sweep(): void
    {
        [, $campaign] = $this->openCampaign();

        // Still literally open in the column, and already over by the clock.
        $campaign->forceFill(['ends_at' => now()->subMinute()])->save();

        $this->assertSame(Status::Open, (int) $campaign->fresh()->status);
        $this->assertSame(Status::Expired, $this->states->effective($campaign->fresh()));
        $this->assertFalse($this->campaigns->acceptsSubmissions($campaign->fresh()));

        // The advert is still readable — it says the window has ended (D48).
        $this->assertTrue($this->campaigns->isPubliclyVisible($campaign->fresh()));
    }

    /** A campaign whose start has not arrived shows the advert but takes nothing. */
    public function test_a_campaign_before_its_start_date_takes_no_submissions(): void
    {
        [$hr, $vacancy] = $this->seat();
        $campaign = $this->campaigns->create($hr, $vacancy, [
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->addMonth()->toDateTimeString(),
        ]);
        $campaign->forceFill(['status' => Status::Published])->save();

        $this->assertTrue($this->campaigns->isPubliclyVisible($campaign));
        $this->assertFalse($this->campaigns->acceptsSubmissions($campaign));
    }

    /** The sweep only tidies the column; it invents nothing. */
    public function test_the_sweep_writes_expiry_onto_campaigns_whose_window_ended(): void
    {
        [, $campaign] = $this->openCampaign();
        $campaign->forceFill(['ends_at' => now()->subHour()])->save();

        $this->artisan('recruitment:expire-campaigns')->assertSuccessful();

        $this->assertSame(Status::Expired, (int) $campaign->fresh()->status);

        // Running it again changes nothing.
        $this->artisan('recruitment:expire-campaigns')->assertSuccessful();
        $this->assertSame(Status::Expired, (int) $campaign->fresh()->status);
    }

    /** C7: reopening onto a window that has run out would take dead applications. */
    public function test_reopening_requires_an_end_date_in_the_future(): void
    {
        [$hr, $campaign] = $this->openCampaign();
        $closed = $this->campaigns->close($hr, $campaign);

        $closed->forceFill(['ends_at' => now()->subDay()])->save();

        try {
            $this->campaigns->reopen($hr, $closed->fresh());
            $this->fail('Reopening onto a past window should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('campaign', $exception->errors());
        }

        $closed->forceFill(['ends_at' => now()->addWeek()])->save();
        $reopened = $this->campaigns->reopen($hr, $closed->fresh());

        $this->assertSame(Status::Open, (int) $reopened->status);
        $this->assertNull($reopened->closed_by_user_id);
    }

    /** C8: disabling hides the advert outright, unlike closing. */
    public function test_disabling_hides_the_campaign_completely(): void
    {
        [$hr, $campaign] = $this->openCampaign();

        $disabled = $this->campaigns->disable($hr, $campaign, 'Spam.');

        $this->assertSame(Status::Disabled, (int) $disabled->status);
        $this->assertFalse($this->campaigns->isPubliclyVisible($disabled));
        $this->assertFalse($this->campaigns->acceptsSubmissions($disabled));
    }

    /**
     * C9: reactivation recomputes rather than restores.
     *
     * A campaign that was open when it was hidden, and whose window ran out
     * while it was hidden, comes back expired. Restoring it literally would
     * quietly reopen applications on a window that has already closed.
     */
    public function test_reactivating_recomputes_the_status_against_the_clock(): void
    {
        [$hr, $campaign] = $this->openCampaign();

        $disabled = $this->campaigns->disable($hr, $campaign);
        $disabled->forceFill(['ends_at' => now()->subDay()])->save();

        $back = $this->campaigns->reactivate($hr, $disabled->fresh());

        $this->assertSame(Status::Expired, (int) $back->status);
        $this->assertNotSame(Status::Open, (int) $back->status);
    }

    /** And when the window still stands, it comes back where it was. */
    public function test_reactivating_returns_an_open_campaign_to_open(): void
    {
        [$hr, $campaign] = $this->openCampaign();

        $disabled = $this->campaigns->disable($hr, $campaign);
        $back = $this->campaigns->reactivate($hr, $disabled->fresh());

        $this->assertSame(Status::Open, (int) $back->status);
        $this->assertTrue($this->campaigns->acceptsSubmissions($back));
    }

    /** Somebody without the permission cannot touch a campaign. */
    public function test_a_user_without_the_permission_cannot_create_a_campaign(): void
    {
        [, $vacancy] = $this->seat();
        $stranger = $this->makeUser($this->makeCity('ALP', (string) random_int(100, 899)), 'manager');

        $this->expectException(AuthorizationException::class);

        $this->campaigns->create($stranger, $vacancy);
    }

    /** Every move leaves a row, and the role is stored as it was. */
    public function test_campaign_actions_are_audited(): void
    {
        [$hr, $campaign] = $this->openCampaign();
        $this->campaigns->close($hr, $campaign, 'Done.');

        $events = RecruitmentRequestEvent::query()
            ->where('event_type', RecruitmentEventType::CampaignStateChanged)
            ->get()
            ->filter(fn ($event): bool => (int) ($event->meta['campaign_id'] ?? 0) === $campaign->id);

        $actions = $events->map(fn ($event): string => $event->meta['action'] ?? '')->all();

        $this->assertContains('created', $actions);
        $this->assertContains('closed', $actions);

        $closing = $events->firstWhere(fn ($event): bool => ($event->meta['action'] ?? '') === 'closed');
        $this->assertSame($hr->id, (int) $closing->actor_user_id);
        $this->assertSame($hr->myRole(), $closing->actor_role);
        $this->assertSame('Done.', $closing->meta['reason']);
    }

    /** An HR user and an open seat to recruit for. @return array{0: User, 1: Vacancy} */
    private function seat(): array
    {
        ['hr' => $hr, 'request' => $request, 'position' => $position] = $this->workflow();

        $request->forceFill([
            'request_type' => RecruitmentRequestType::FillVacancy,
            'status' => RecruitmentRequestStatus::Applied,
        ])->save();

        $vacancy = Vacancy::query()->create([
            'position_id' => $position->id,
            'created_from_request_id' => $request->id,
            'status' => Vacancy::OPEN,
            'needed_count' => 1,
            'filled_count' => 0,
            'open_at' => now()->subDay(),
        ]);

        return [$hr, $vacancy];
    }

    /** A campaign already taking applications. @return array{0: User, 1: HiringCampaign} */
    private function openCampaign(): array
    {
        [$hr, $vacancy] = $this->seat();

        $campaign = $this->campaigns->create($hr, $vacancy, [
            'ends_at' => now()->addMonth()->toDateTimeString(),
        ]);

        // Published without the post and form, which task 13 and 14 will make
        // reachable; prepare the frozen public assets before applications open.
        $campaign->forceFill(['status' => Status::Published])->save();
        $campaign = $this->prepareCampaignForOpening($campaign->fresh(), $hr);

        return [$hr, $this->campaigns->open($hr, $campaign->fresh())];
    }
}
