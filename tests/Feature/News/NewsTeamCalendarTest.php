<?php

namespace Tests\Feature\News;

use App\Enums\News\AudienceType;
use App\Enums\News\NewsStatus;
use App\Livewire\Team\Calendar;
use App\Models\City;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\Concerns\CreatesNews;
use Tests\TestCase;

/**
 * Organization events on the team calendar: live events the viewer is in the
 * audience of (NewsFeed::eventsBetween), on each of their days, linking to the
 * event's page. Drafts, scheduled items and other audiences never appear,
 * and the "Show events" toggle hides them all.
 */
class NewsTeamCalendarTest extends TestCase
{
    use CreatesContractFixtures, CreatesNews, RefreshDatabase;

    private City $dms;

    private City $alp;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));

        $this->dms = $this->makeCity('DMS', '003');
        $this->alp = $this->makeCity('ALP', '001');

        $this->viewer = $this->makeUser($this->dms, 'employee', ['leaves.view-own']);
        $this->makeContractFor($this->viewer->profile, ['position_id' => $this->makePosition($this->dms)->id]);
    }

    public function test_a_live_event_for_the_viewer_appears_on_its_day_with_its_time_and_link(): void
    {
        $event = $this->makeEvent('2026-11-12 14:30:00', null, ['title_en' => 'Volunteer gathering']);

        $this->calendar(['view' => 'day', 'date' => '2026-11-12'])
            ->assertSee('Volunteer gathering')
            ->assertSeeHtml('14:30</bdi>')
            ->assertSeeHtml('href="'.route('news.show', $event->getKey()).'"')
            ->assertSeeHtml('data-testid="calendar-event"');

        $this->calendar(['view' => 'day', 'date' => '2026-11-13'])
            ->assertDontSee('Volunteer gathering');
    }

    public function test_an_event_shows_in_the_month_view(): void
    {
        $this->makeEvent('2026-11-20 09:00:00', null, ['title_en' => 'Annual meeting']);

        $this->calendar(['date' => '2026-11-04'])
            ->assertSee('Annual meeting')
            ->assertSee(__('team_calendar.events.legend'));
    }

    public function test_a_multi_day_event_is_on_each_of_its_days(): void
    {
        $this->makeEvent('2026-11-20 10:00:00', '2026-11-22 16:00:00', ['title_en' => 'Staff retreat']);

        foreach (['2026-11-20', '2026-11-21', '2026-11-22'] as $date) {
            $this->calendar(['view' => 'day', 'date' => $date])->assertSee('Staff retreat');
        }

        $this->calendar(['view' => 'day', 'date' => '2026-11-19'])->assertDontSee('Staff retreat');
        $this->calendar(['view' => 'day', 'date' => '2026-11-23'])->assertDontSee('Staff retreat');
        // The time belongs to the first day only.
        $this->calendar(['view' => 'day', 'date' => '2026-11-21'])->assertDontSeeHtml('10:00</bdi>');
        $this->calendar(['view' => 'day', 'date' => '2026-11-20'])->assertSeeHtml('10:00</bdi>');
    }

    public function test_drafts_scheduled_and_out_of_audience_events_stay_off(): void
    {
        $this->makeEvent('2026-11-12 09:00:00', null, ['title_en' => 'Draft event', 'status' => NewsStatus::Draft]);
        $this->makeEvent('2026-11-12 09:00:00', null, ['title_en' => 'Scheduled event', 'published_at' => now()->addDay()]);
        $this->makeEvent('2026-11-12 09:00:00', null, ['title_en' => 'Aleppo event'])
            ->syncAudience(AudienceType::Cities, [$this->alp->id]);
        $this->makeEvent('2026-11-12 09:00:00', null, ['title_en' => 'Damascus event'])
            ->syncAudience(AudienceType::Cities, [$this->dms->id]);

        $this->calendar(['view' => 'day', 'date' => '2026-11-12'])
            ->assertSee('Damascus event')
            ->assertDontSee('Draft event')
            ->assertDontSee('Scheduled event')
            ->assertDontSee('Aleppo event');
    }

    public function test_the_toggle_hides_events(): void
    {
        $this->makeEvent('2026-11-12 09:00:00', null, ['title_en' => 'Volunteer gathering']);

        $this->calendar(['view' => 'day', 'date' => '2026-11-12'])
            ->assertSet('showEvents', true)
            ->assertSee('Volunteer gathering')
            ->set('showEvents', false)
            ->assertDontSee('Volunteer gathering')
            ->assertDontSee(__('team_calendar.events.legend'));
    }

    private function calendar(array $query)
    {
        return Livewire::withQueryParams($query)->actingAs($this->viewer->fresh())->test(Calendar::class);
    }
}
