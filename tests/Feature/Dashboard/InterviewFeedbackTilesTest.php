<?php

namespace Tests\Feature\Dashboard;

use App\Enums\RecruitmentInterviewType;
use App\Livewire\Dashboard\ItemCard;
use App\Livewire\Feedback\FeedbackIndex;
use App\Livewire\Position\ProfileDrawer;
use App\Models\City;
use App\Models\Department;
use App\Models\InAppFeedback;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewParticipant as Participant;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * "Add to dashboard" on an interview card (the board, the interview page), a
 * feedback message, and the profile drawer the org chart's cards open
 * (2026-11-04). Each behind its page's own rule.
 */
class InterviewFeedbackTilesTest extends TestCase
{
    use BuildsRecruitmentApplications, CreatesContractFixtures, RefreshDatabase;

    private const MEETING_URL = 'https://meet.example.test/j/secret-room-4711';

    private City $homs;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'Europe/Istanbul'));
        $this->homs = $this->makeCity('HMS', '002');
        $this->hr = $this->makeUser($this->homs, 'HR-HMS', [
            'recruitment-campaigns.view',
            ...array_map(fn (string $action): string => "recruitment-applicants.{$action}", config('access-control.groups.recruitment-applicants')),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_interview_card_is_for_whoever_may_open_the_interview_and_never_shows_the_meeting_link(): void
    {
        $interviewer = $this->makeUser($this->homs, 'employee');
        $outsider = $this->makeUser($this->homs, 'manager', ['leaves.view-own']);
        $interview = $this->interview([$interviewer->id => Participant::ROLE_INTERVIEWER]);
        $key = DashboardLayout::itemKey('interview', $interview->id);
        $layout = new DashboardLayout;

        $this->assertTrue($layout->allowed($this->hr, $key));
        $this->assertTrue($layout->allowed($interviewer, $key), 'a participant sees the interviews they are on');
        $this->assertFalse($layout->allowed($outsider, $key));
        $this->assertSame(route('recruitment.interviews.show', ['interviewId' => $interview->id]), $layout->page($interviewer, $key));

        Livewire::actingAs($interviewer)->test(ItemCard::class, ['type' => 'interview', 'itemId' => $interview->id])
            ->assertSee('Candidate One')
            ->assertSee('Microsoft Teams')
            ->assertDontSee(self::MEETING_URL)
            ->assertDontSee('secret-room');
        Livewire::actingAs($outsider)->test(ItemCard::class, ['type' => 'interview', 'itemId' => $interview->id])
            ->assertSee(__('dashboard.record.gone'))
            ->assertDontSee('Candidate One');
    }

    public function test_a_feedback_card_is_for_the_feedback_readers_only(): void
    {
        $it = Department::query()->firstOrCreate(['code' => config('feedback.reader_department_code')], ['name' => 'IT']);
        $reader = $this->makeUser($this->homs, 'employee', ['leaves.view-own']);
        $this->makeContractFor($reader->profile, ['position_id' => $this->makePosition($this->homs, ['department_id' => $it->id])->id, 'end_date' => '2027-12-31']);
        $other = $this->makeUser($this->homs, 'manager', ['leaves.view-own']);
        $item = InAppFeedback::query()->forceCreate([
            'user_id' => $other->id, 'message' => 'The leave form is slow', 'category' => config('feedback.categories')[0],
            'route_name' => 'leaves.index', 'locale' => 'en', 'role_name' => 'manager',
        ]);
        $key = DashboardLayout::itemKey('feedback', $item->id);
        $layout = new DashboardLayout;

        $this->assertTrue($layout->allowed($reader->fresh(), $key));
        $this->assertFalse($layout->allowed($other, $key));

        Livewire::actingAs($reader->fresh())->test(ItemCard::class, ['type' => 'feedback', 'itemId' => $item->id])
            ->assertSee('The leave form is slow');
        Livewire::actingAs($reader->fresh())->test(FeedbackIndex::class)
            ->assertSeeHtml('atd-feedback-'.$item->id);
    }

    public function test_the_profile_drawer_offers_the_persons_contact_card(): void
    {
        $viewer = $this->makeUser($this->homs, 'employee', ['leaves.view-own']);
        $colleague = $this->makeEmployee($this->homs, 'colleague', ['is_active' => true]);
        $this->makeContractFor($colleague);

        Livewire::actingAs($viewer)->test(ProfileDrawer::class)
            ->call('openProfile', $colleague->id)
            ->assertSeeHtml('atd-drawer-'.$colleague->id);

        foreach (['tiles.item-interview', 'tiles.item-feedback', 'item.where'] as $text) {
            foreach (['en', 'ar'] as $locale) {
                $this->assertNotSame('dashboard.'.$text, __('dashboard.'.$text, [], $locale), "{$text} {$locale}");
            }
        }
    }

    /**
     * Written directly: the scheduling service's status flow fails in the current
     * recruitment fixtures (the board's own tests are in the known baseline).
     */
    private function interview(array $participants): RecruitmentInterview
    {
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant('Candidate One'));

        $interview = RecruitmentInterview::query()->forceCreate([
            'application_id' => $application->id,
            'interview_type' => RecruitmentInterviewType::Hr,
            'status' => 'scheduled',
            'scheduled_at' => '2026-10-08 07:00:00',
            'ends_at' => '2026-10-08 08:00:00',
            'timezone' => 'Asia/Damascus',
            'location_type' => 'online',
            'location_value' => 'Microsoft Teams',
            'meeting_url' => self::MEETING_URL,
            'created_by_user_id' => $this->hr->id,
        ]);

        foreach ($participants as $userId => $role) {
            Participant::query()->forceCreate(['interview_id' => $interview->id, 'user_id' => $userId, 'role' => $role]);
        }

        return $interview->fresh();
    }
}
