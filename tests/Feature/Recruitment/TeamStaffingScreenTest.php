<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentRequestType;
use App\Livewire\Recruitment\Requests\Create;
use App\Livewire\Recruitment\Requests\Index;
use App\Livewire\Recruitment\Requests\TeamStaffing;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsRecruitmentRequests;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The staffing chart as a manager reads it.
 *
 * The chart exists so a manager can start the right request from the post that
 * needs it, without retyping into a form what the card already knows. These
 * tests hold the two faces of the chart, the one filter that matters, and the
 * handover to the request form.
 */
class TeamStaffingScreenTest extends TestCase
{
    use BuildsRecruitmentRequests, CreatesHrFixtures, RefreshDatabase;

    private \App\Models\User $manager;

    private Position $root;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $city = $this->makeCity('SYR', '000');
        $this->manager = $this->makeUser($city, 'manager');

        $this->root = Position::query()->create([
            'city_id' => $city->id,
            'program_id' => Program::query()->create([
                'name' => 'Field', 'code' => 'FLD', 'slug' => 'field',
                'start_date' => now(), 'is_active' => true,
            ])->id,
            'department_id' => Department::query()->create(['name' => 'Field', 'code' => 'FLD'])->id,
            'job_level_id' => JobLevel::query()->create(['name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 5])->id,
            'title_en' => 'Programme manager', 'title_ar' => 'مدير برنامج',
            'is_active' => true, 'max_emp' => 1, 'exist_emp' => 1,
        ]);

        Contract::query()->create([
            'employee_id' => $this->manager->employee_id, 'position_id' => $this->root->id,
            'contract_type_id' => ContractType::query()->create(['name_en' => 'Annual', 'name_ar' => 'سنوي'])->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addYear(),
            'currency' => 2, 'salary_per' => 1, 'weekly_hours' => 40, 'is_active' => true,
        ]);
    }

    /** Both tabs carry the same head, so moving between them is not a change of application. */
    public function test_both_tabs_share_one_header_with_the_primary_action(): void
    {
        foreach ([Index::class, TeamStaffing::class] as $screen) {
            Livewire::actingAs($this->manager)->test($screen)
                ->assertSee(__('recruitment.create'))
                ->assertSee(__('recruitment.navigation_requests'))
                ->assertSee(__('recruitment.team_staffing.tab'));
        }
    }

    /**
     * The same tree, asked two different questions.
     *
     * The staff view is read by name; the positions view by post. Both are the
     * chart — only what a card says about itself changes.
     */
    public function test_the_two_views_show_staff_and_posts_of_the_same_tree(): void
    {
        $holder = $this->seat('Field officer', filled: 0, capacity: 1);

        $screen = Livewire::actingAs($this->manager)->test(TeamStaffing::class)
            ->assertSet('view', 'staff')
            // A person's own name is what the staff view is for.
            ->assertSee($holder->name)
            ->assertSee(__('recruitment.team_staffing.views.positions'));

        $screen->call('setView', 'positions')
            ->assertSet('view', 'positions')
            ->assertSee('Field officer');

        // An unknown view is refused rather than rendered.
        $screen->call('setView', 'nonsense')->assertSet('view', 'positions');
    }

    /**
     * A post held by three people shows three cards.
     *
     * The chart used to show one card per post and a "+2 more in this position"
     * line under it, which is not a person and cannot be clicked. This view is
     * read to find somebody, so everybody is on it — and the unfilled seat is a
     * card in the same row, because a gap in a team is read beside the people in
     * it rather than as a number above them.
     */
    public function test_the_staff_view_draws_one_card_for_every_person_in_a_post(): void
    {
        // exist_emp is the observer's to maintain, so the post starts empty and
        // the two contracts below are what fill it.
        $shared = $this->openPost('Shared post', filled: 0, capacity: 3);
        $first = $this->place($shared);
        $second = $this->place($shared);

        Livewire::actingAs($this->manager)->test(TeamStaffing::class)
            ->assertSee($first->name)
            ->assertSee($second->name)
            // The seat nobody is in is drawn too, since one is still open.
            ->assertSee(__('general.vacant-position'))
            // And the line that stood in for the second person is gone.
            ->assertDontSee(__('recruitment.team_staffing.more_holders', ['count' => 1]));
    }

    /**
     * Every empty seat is drawn, not summarised.
     *
     * The organisation chart draws one card per unfilled seat, and this chart
     * now does the same: a post approved for three with nobody in it is three
     * empty cards, because that is what the manager is looking at when they ask
     * for people.
     */
    public function test_an_unfilled_post_draws_one_card_for_each_empty_seat(): void
    {
        $this->openPost('Triple post', filled: 0, capacity: 3);

        $html = Livewire::actingAs($this->manager)->test(TeamStaffing::class)->html();

        $this->assertSame(3, substr_count($html, e(__('general.vacant-position'))));
    }

    /**
     * Sibling posts flow: one wrapping row, never a fixed column count.
     *
     * Four posts sit side by side while the canvas is wide enough and fold onto
     * the next line when it is not, so the count of columns is never in the
     * markup. Managers come before the reports who manage nobody.
     */
    public function test_sibling_posts_wrap_by_available_width_in_both_views(): void
    {
        $branch = $this->openPost('Branch report', filled: 0, capacity: 1);
        $this->openPost('Leaf report', filled: 0, capacity: 1);

        $under = $this->openPost('Under the branch', filled: 0, capacity: 1);
        $under->update(['direct_manager_id' => $branch->getKey()]);

        foreach (['positions', 'staff'] as $view) {
            $html = $this->chart($view);

            $this->assertStringContainsString('flex flex-wrap items-start justify-center', $html);
            $this->assertStringNotContainsString('grid-template-columns', $html);
            $this->assertLessThan(strpos($html, 'Leaf report'), strpos($html, 'Branch report'));
        }
    }

    /** Several people in one post stay together as one wrapping group. */
    public function test_several_holders_of_one_post_stay_in_one_wrapping_group(): void
    {
        $shared = $this->openPost('Shared post', filled: 0, capacity: 3);
        $first = $this->place($shared);
        $second = $this->place($shared);

        $staff = $this->chart('staff');

        foreach ([$first->name, $second->name] as $name) {
            $this->assertStringContainsString(e($name), $staff);
        }

        $this->assertStringContainsString('flex flex-wrap items-start justify-center gap-2', $staff);
    }

    /**
     * A manager's box grows with its subtree instead of strangling it.
     *
     * The positions view fixes a card's width so posts can be compared, and once
     * the reports moved inside their manager's box that width capped the whole
     * subtree to one column — which is why a level of posts came out stacked one
     * under another. The width belongs to the card's own content only.
     */
    public function test_the_positions_view_does_not_cap_the_width_of_a_subtree(): void
    {
        $branch = $this->openPost('Branch report', filled: 0, capacity: 1);
        $under = $this->openPost('Under the branch', filled: 0, capacity: 1);
        $under->update(['direct_manager_id' => $branch->getKey()]);

        $html = $this->chart('positions');

        // The fixed width is on a content column, never on a box that wraps
        // children: every occurrence of it carries mx-auto with it.
        $this->assertStringContainsString('w-52 mx-auto', $html);
        $this->assertSame(
            substr_count($html, 'w-52'),
            substr_count($html, 'w-52 mx-auto'),
            'a fixed width escaped onto a box that contains a subtree',
        );
    }

    /** The filter that the page is really for: where is the establishment short. */
    public function test_the_unfilled_filter_hides_fully_staffed_posts(): void
    {
        $this->seat('Fully staffed post', filled: 1, capacity: 1);
        $this->openPost('Short post', filled: 0, capacity: 2);

        Livewire::actingAs($this->manager)->test(TeamStaffing::class)
            ->call('setView', 'positions')
            ->assertSee('Fully staffed post')
            ->assertSee('Short post')
            ->call('toggleVacantOnly')
            ->assertSet('vacantOnly', true)
            ->assertDontSee('Fully staffed post')
            ->assertSee('Short post');
    }

    /** Nothing lectures the manager about the requests they cannot raise. */
    public function test_the_card_never_explains_the_actions_it_is_withholding(): void
    {
        $this->openPost('Short post', filled: 0, capacity: 2);

        Livewire::actingAs($this->manager)->test(TeamStaffing::class)
            ->assertSee(__('recruitment.team_staffing.open_request'))
            // These two strings were the lecture, and they no longer exist.
            ->assertDontSee('Why other actions are unavailable')
            ->assertDontSee('No action available');
    }

    /**
     * The single open vacancy travels with the link, and the form takes it.
     *
     * Asking which vacancy is a real question when a post has two open. With one,
     * the card already knows, and making the manager answer it again is the
     * whole friction this screen exists to remove.
     */
    public function test_a_lone_open_vacancy_is_handed_to_the_request_form(): void
    {
        $position = $this->openPost('Post with one opening', filled: 0, capacity: 1);

        $vacancy = $this->opening($position);

        Livewire::actingAs($this->manager)->test(TeamStaffing::class)
            ->assertSee('vacancy_id='.$vacancy->id, escape: false);

        $this->actingAs($this->manager);

        Livewire::withQueryParams([
            'type' => RecruitmentRequestType::FillVacancy,
            'position_id' => $position->id,
            'vacancy_id' => $vacancy->id,
        ])->test(Create::class)
            ->assertSet('data.position_id', $position->id)
            ->assertSet('data.vacancy_id', $vacancy->id);
    }

    /** Two openings are a real choice, so the link stays silent about them. */
    public function test_two_open_vacancies_are_left_for_the_form_to_ask_about(): void
    {
        $position = $this->openPost('Post with two openings', filled: 0, capacity: 2);

        $this->opening($position);
        $this->opening($position);

        Livewire::actingAs($this->manager)->test(TeamStaffing::class)
            ->assertDontSee('vacancy_id=', escape: false);
    }

    /** The chart's rendered markup in one of its two views. */
    private function chart(string $view): string
    {
        return Livewire::actingAs($this->manager)->test(TeamStaffing::class)
            ->call('setView', $view)
            ->html();
    }

    /**
     * One open vacancy on a post.
     *
     * Every vacancy is born from a request, and `created_from_request_id` is
     * unique, so two openings on one post need two requests behind them — which
     * is what really happens: each opening was asked for separately.
     */
    private function opening(Position $position): Vacancy
    {
        $request = RecruitmentRequest::query()->create([
            'request_number' => 'HS-'.str_pad((string) (RecruitmentRequest::query()->count() + 1), 3, '0', STR_PAD_LEFT),
            'requester_user_id' => $this->manager->getKey(),
            'request_type' => RecruitmentRequestType::FillVacancy,
            'mode' => \App\Enums\RecruitmentRequestMode::Normal,
            'status' => \App\Enums\RecruitmentRequestStatus::Applied,
            'scope_city_id' => $this->root->city_id,
            'target_position_id' => $position->getKey(),
            'submitted_at' => now(),
        ]);

        return Vacancy::query()->create([
            'position_id' => $position->getKey(),
            'created_from_request_id' => $request->getKey(),
            'status' => Vacancy::OPEN,
            'needed_count' => 1,
            'filled_count' => 0,
            'open_at' => now()->subDay(),
        ]);
    }

    /** A post below the manager, with nobody in it. */
    private function openPost(string $title, int $filled, int $capacity): Position
    {
        $position = $this->root->replicate();
        $position->fill([
            'direct_manager_id' => $this->root->id,
            'title_en' => $title,
            'title_ar' => $title,
            'exist_emp' => $filled,
            'max_emp' => $capacity,
        ])->save();

        return $position;
    }

    /** The same, with somebody sitting in it, and their employee returned. */
    private function seat(string $title, int $filled, int $capacity): \App\Models\Employee
    {
        return $this->place($this->openPost($title, $filled, $capacity));
    }

    /** One more person into an existing post. */
    private function place(Position $position): \App\Models\Employee
    {
        $holder = $this->makeUser($this->root->city, 'employee');

        Contract::query()->create([
            'employee_id' => $holder->employee_id, 'position_id' => $position->getKey(),
            'contract_type_id' => ContractType::query()->value('id'),
            'start_date' => now()->subMonth(), 'end_date' => now()->addYear(),
            'currency' => 2, 'salary_per' => 1, 'weekly_hours' => 40, 'is_active' => true,
        ]);

        return $holder->profile->fresh();
    }
}
