<?php

namespace Tests\Feature\Recruitment;

use App\Enums\HiringCampaignStatus as Status;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Livewire\Recruitment\Campaigns\Create;
use App\Livewire\Recruitment\Campaigns\Show;
use App\Models\HiringCampaign;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\HiringCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsPublishedCampaignAssets;
use Tests\Concerns\BuildsWorkflowFixtures;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The screens that run a campaign's life.
 *
 * What they mostly have to get right is not offering a move the server would
 * refuse, and not hiding one it would allow — the buttons are asked of the
 * state machine rather than decided in the view, and these tests hold them to
 * that.
 */
class HiringCampaignScreensTest extends TestCase
{
    use BuildsPublishedCampaignAssets, BuildsWorkflowFixtures, CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_hr_can_create_a_campaign_from_the_screen(): void
    {
        [$hr, $vacancy] = $this->seat();

        Livewire::actingAs($hr)
            ->test(Create::class)
            ->set('vacancyId', $vacancy->id)
            ->set('linkMode', HiringCampaign::INTERNAL)
            ->set('minimumAge', '18')
            ->set('maximumAge', '35')
            ->set('endsAt', now()->addMonth()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasNoErrors();

        $campaign = HiringCampaign::query()->sole();

        $this->assertSame(HiringCampaign::INTERNAL, $campaign->link_mode);
        $this->assertSame(18, $campaign->minimum_age);
        $this->assertSame(35, $campaign->maximum_age);
        $this->assertSame(Status::Draft, (int) $campaign->status);
    }

    /** The three modes run in sequence on one seat, and the screen offers all three. */
    public function test_the_create_screen_offers_every_link_mode(): void
    {
        [$hr] = $this->seat();

        Livewire::actingAs($hr)
            ->test(Create::class)
            ->assertSee(__('recruitment-campaign.modes.internal'))
            ->assertSee(__('recruitment-campaign.modes.public_open'))
            ->assertSee(__('recruitment-campaign.modes.invitation_only'))
            ->assertSee('id="startsAt" type="date"', false)
            ->assertSee('id="endsAt" type="date"', false)
            ->assertDontSee('datetime-local', false);
    }

    /** With a single seat on offer there is nothing to choose, so it is chosen. */
    public function test_the_create_screen_preselects_the_only_available_seat(): void
    {
        [$hr, $vacancy] = $this->seat();

        Livewire::actingAs($hr)
            ->test(Create::class)
            ->assertSet('vacancyId', $vacancy->id);
    }

    public function test_request_context_limits_campaign_creation_to_that_requests_vacancy(): void
    {
        [$hr, $vacancy] = $this->seat();
        $otherRequest = $vacancy->createdFromRequest->replicate();
        $otherRequest->request_number = 'RR-OTHER-'.uniqid();
        $otherRequest->save();
        $otherVacancy = Vacancy::query()->create([
            'position_id' => $vacancy->position_id,
            'created_from_request_id' => $otherRequest->id,
            'status' => Vacancy::OPEN,
            'needed_count' => 1,
            'filled_count' => 0,
            'open_at' => now()->subDay(),
        ]);

        Livewire::actingAs($hr)
            ->test(Create::class, ['vacancyId' => $vacancy->id])
            ->assertSet('vacancyId', $vacancy->id)
            ->assertViewHas('vacancies', function ($vacancies) use ($vacancy, $otherVacancy): bool {
                return $vacancies->contains('id', $vacancy->id)
                    && ! $vacancies->contains('id', $otherVacancy->id)
                    && $vacancies->count() === 1;
            })
            ->set('vacancyId', $otherVacancy->id)
            ->call('save')
            ->assertHasErrors(['vacancyId']);

        $this->assertDatabaseMissing('hiring_campaigns', ['vacancy_id' => $otherVacancy->id]);
    }

    /** A window that makes no sense is refused on the way in, not stored. */
    public function test_the_create_screen_reports_a_window_that_ends_before_it_starts(): void
    {
        [$hr, $vacancy] = $this->seat();

        Livewire::actingAs($hr)
            ->test(Create::class)
            ->set('vacancyId', $vacancy->id)
            ->set('startsAt', now()->addDays(10)->format('Y-m-d\TH:i'))
            ->set('endsAt', now()->addDays(3)->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasErrors('ends_at');

        $this->assertSame(0, HiringCampaign::query()->count());
    }

    /** A new draft is a checklist with the next step marked and going live blocked. */
    public function test_a_draft_campaign_shows_the_checklist_and_blocks_going_live(): void
    {
        [$hr, $vacancy] = $this->seat();
        $campaign = app(HiringCampaignService::class)->create($hr, $vacancy);

        Livewire::actingAs($hr)
            ->test(Show::class, ['campaignId' => $campaign->getKey()])
            ->assertSee(__('recruitment-campaign.page.step_post'))
            ->assertSee(__('recruitment-campaign.page.step_form'))
            ->assertSee(__('recruitment-campaign.page.step_link'))
            ->assertSee(__('recruitment-campaign.page.link_waits'))
            ->assertSee(__('recruitment-campaign.page.ready_count', ['done' => 1, 'total' => 4]))
            ->assertSee(__('recruitment-campaign.page.launch_blocked'))
            ->assertSee('disabled data-campaign-launch', false)
            ->assertSee(__('recruitment-campaign.act.disable'))
            ->assertSee(__('recruitment-campaign.confirm.disable'))
            ->assertSee('data-recruitment-confirmation-modal="campaign-actions"', false)
            ->assertSee('requireReason: true', false)
            ->assertDontSee('wire:confirm', false)
            ->assertDontSee(__('recruitment-campaign.act.close'))
            ->assertDontSee(__('recruitment-campaign.act.reopen'));
    }

    public function test_going_live_is_enabled_once_post_form_and_link_are_ready(): void
    {
        [$hr, $vacancy] = $this->seat();
        $campaign = app(HiringCampaignService::class)->create($hr, $vacancy);
        $this->prepareCampaignForOpening($campaign, $hr);

        Livewire::actingAs($hr)
            ->test(Show::class, ['campaignId' => $campaign->getKey()])
            ->assertSee(__('recruitment-campaign.page.ready_count', ['done' => 4, 'total' => 4]))
            ->assertDontSee(__('recruitment-campaign.page.launch_blocked'))
            ->assertDontSee('disabled data-campaign-launch', false)
            ->assertSee('wire:click="act(\'publish\')"', false);
    }

    /** One click publishes and opens when the window has started. */
    public function test_going_live_publishes_and_opens_in_one_step(): void
    {
        [$hr, $vacancy] = $this->seat();
        $campaign = app(HiringCampaignService::class)->create($hr, $vacancy);
        $this->prepareCampaignForOpening($campaign, $hr);

        Livewire::actingAs($hr)
            ->test(Show::class, ['campaignId' => $campaign->getKey()])
            ->call('launch');

        $this->assertSame(Status::Open, (int) $campaign->fresh()->status);
        $this->assertTrue(app(HiringCampaignService::class)->acceptsSubmissions($campaign->fresh()));
    }

    /** With a future start, going live publishes now and leaves opening to the schedule. */
    public function test_going_live_before_the_start_publishes_and_waits(): void
    {
        [$hr, $vacancy] = $this->seat();
        $campaign = app(HiringCampaignService::class)->create($hr, $vacancy, [
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addMonth(),
        ]);
        $this->prepareCampaignForOpening($campaign, $hr);

        Livewire::actingAs($hr)
            ->test(Show::class, ['campaignId' => $campaign->getKey()])
            ->assertSee(__('recruitment-campaign.page.launch_scheduled'))
            ->call('launch');

        $this->assertSame(Status::Published, (int) $campaign->fresh()->status);
    }

    /** Without an advert there is nothing to go live with, and nothing changes. */
    public function test_going_live_without_an_advert_is_refused(): void
    {
        [$hr, $vacancy] = $this->seat();
        $campaign = app(HiringCampaignService::class)->create($hr, $vacancy);

        Livewire::actingAs($hr)
            ->test(Show::class, ['campaignId' => $campaign->getKey()])
            ->call('launch')
            ->call('act', 'publish');

        $this->assertSame(Status::Draft, (int) $campaign->fresh()->status);
    }

    public function test_an_age_restricted_campaign_requires_a_required_birth_date_field_before_launch(): void
    {
        [$hr, $vacancy] = $this->seat();
        $campaign = app(HiringCampaignService::class)->create($hr, $vacancy, [
            'minimum_age' => 18,
            'maximum_age' => 35,
        ]);
        $this->prepareCampaignForOpening($campaign, $hr);

        Livewire::actingAs($hr)
            ->test(Show::class, ['campaignId' => $campaign->getKey()])
            ->call('launch');

        $this->assertSame(Status::Draft, (int) $campaign->fresh()->status);
    }

    /** Who may apply is chosen in the details and locked once the advert is out. */
    public function test_the_audience_changes_only_while_the_campaign_is_a_draft(): void
    {
        [$hr, $vacancy] = $this->seat();
        $campaign = app(HiringCampaignService::class)->create($hr, $vacancy);

        Livewire::actingAs($hr)
            ->test(Show::class, ['campaignId' => $campaign->getKey()])
            ->call('editDetails')
            ->set('linkMode', HiringCampaign::INVITE_ONLY)
            ->call('saveDetails')
            ->assertHasNoErrors();

        $this->assertTrue($campaign->fresh()->isInvitationOnly());
    }

    public function test_the_audience_is_locked_once_the_campaign_is_live(): void
    {
        [$hr, $open] = $this->openCampaign();

        Livewire::actingAs($hr)
            ->test(Show::class, ['campaignId' => $open->getKey()])
            ->call('editDetails')
            ->set('linkMode', HiringCampaign::INVITE_ONLY)
            ->call('saveDetails')
            ->assertHasErrors('linkMode');

        $this->assertFalse($open->fresh()->isInvitationOnly());
    }

    /** Campaigns stored with the early spelling still enforce their list. */
    public function test_the_legacy_invite_only_spelling_reads_as_invitation_only(): void
    {
        [$hr, $vacancy] = $this->seat();
        $campaign = app(HiringCampaignService::class)->create($hr, $vacancy);
        $campaign->forceFill(['link_mode' => HiringCampaign::LEGACY_INVITE_ONLY])->save();

        $this->assertTrue($campaign->fresh()->isInvitationOnly());
        $this->assertSame(HiringCampaign::INVITE_ONLY, $campaign->fresh()->link_mode);
    }

    public function test_close_and_disable_require_a_reason_from_the_screen(): void
    {
        [$hr, $campaign] = $this->openCampaign();

        Livewire::actingAs($hr)
            ->test(Show::class, ['campaignId' => $campaign->getKey()])
            ->call('act', 'close');

        $this->assertSame(Status::Open, (int) $campaign->fresh()->status);

        Livewire::actingAs($hr)
            ->test(Show::class, ['campaignId' => $campaign->getKey()])
            ->call('act', 'disable');

        $this->assertSame(Status::Open, (int) $campaign->fresh()->status);
    }

    public function test_closing_from_the_screen_stops_submissions(): void
    {
        [$hr, $campaign] = $this->openCampaign();

        Livewire::actingAs($hr)
            ->test(Show::class, ['campaignId' => $campaign->getKey()])
            ->assertSee(__('recruitment-campaign.page.badge_open'))
            ->set('reason', 'Enough applicants.')
            ->call('act', 'close')
            ->assertHasNoErrors();

        $this->assertSame(Status::Closed, (int) $campaign->fresh()->status);
        $this->assertFalse(app(HiringCampaignService::class)->acceptsSubmissions($campaign->fresh()));
    }

    /**
     * A campaign whose window ran out shows as expired, before any sweep.
     *
     * The screen reads the clock like every other gate, so a list that still
     * said "open" would be quietly lying to whoever is managing it.
     */
    public function test_the_screen_shows_a_lapsed_campaign_as_expired(): void
    {
        [$hr, $campaign] = $this->openCampaign();
        $campaign->forceFill(['ends_at' => now()->subHour()])->save();

        $this->assertSame(Status::Open, (int) $campaign->fresh()->status);

        Livewire::actingAs($hr)
            ->test(Show::class, ['campaignId' => $campaign->fresh()->getKey()])
            ->assertSee(__('recruitment-campaign.page.badge_expired'));
    }

    /**
     * Somebody who may only look sees no buttons at all.
     *
     * The managing permission is taken away and nothing else is touched, so
     * the buttons disappearing can only be measuring that.
     */
    public function test_a_viewer_without_manage_sees_no_actions(): void
    {
        [$hr, $campaign] = $this->openCampaign();

        Role::findByName($hr->myRole(), 'web')
            ->revokePermissionTo(Permission::findByName('recruitment-campaigns.manage'));
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Livewire::actingAs($hr->fresh())
            ->test(Show::class, ['campaignId' => $campaign->getKey()])
            ->assertSee(__('recruitment-campaign.page.status_title'))
            // The link section may still offer its own controls to someone
            // who manages links; the campaign's own moves are what vanish.
            ->assertDontSee('data-campaign-action="close"', false)
            ->assertDontSee('data-campaign-action="disable"', false)
            ->assertDontSee(__('recruitment-campaign.page.save_details'));
    }

    /** @return array{0: User, 1: Vacancy} */
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

    /** @return array{0: User, 1: HiringCampaign} */
    private function openCampaign(): array
    {
        [$hr, $vacancy] = $this->seat();
        $campaigns = app(HiringCampaignService::class);

        $campaign = $campaigns->create($hr, $vacancy, ['ends_at' => now()->addMonth()->toDateTimeString()]);
        $campaign->forceFill(['status' => Status::Published])->save();
        $campaign = $this->prepareCampaignForOpening($campaign->fresh(), $hr);

        return [$hr, $campaigns->open($hr, $campaign->fresh())];
    }
}
