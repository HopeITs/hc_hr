<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentRequestStatus as Status;
use App\Enums\RecruitmentRequestType as Type;
use App\Livewire\Recruitment\Requests\Index;
use App\Livewire\Recruitment\Requests\RequestTable;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Services\RecruitmentRequestListQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssertsOrderedColumnsExist;
use Tests\Concerns\BuildsWorkflowFixtures;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Finding one request among many.
 *
 * The relationship tabs and the table filters narrow the same scoped query.
 * Neither may widen it: a count that promises rows the viewer cannot open,
 * or a filter option naming a person outside their scope, leaks more than it
 * helps.
 */
class RecruitmentRequestListTest extends TestCase
{
    use AssertsOrderedColumnsExist, BuildsWorkflowFixtures, CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    /** The counts are read through the same boundary as the rows. */
    public function test_the_counts_match_what_the_viewer_can_open(): void
    {
        [$hr, $request] = $this->seeded();
        $this->copy($request, Status::Approved);
        $this->copy($request, Status::Approved);

        $counts = Livewire::actingAs($hr)->test(Index::class)->viewData('viewCounts');

        $this->assertSame(count($this->rows($hr)), $counts[RecruitmentRequestListQuery::ALL]);
        $this->assertSame(3, $counts[RecruitmentRequestListQuery::TEAM]);
    }

    public function test_request_views_separate_mine_team_and_requests_needing_my_review(): void
    {
        $workflow = $this->workflow();
        $manager = $workflow['manager']->fresh();
        $teamRequest = $workflow['request'];
        $mine = $this->copy($teamRequest, Status::Approved);
        $mine->forceFill(['requester_user_id' => $manager->id])->save();

        $this->assertSame(
            [$mine->id],
            $this->rows($manager, RecruitmentRequestListQuery::MINE),
        );
        $this->assertSame(
            [$teamRequest->id],
            $this->rows($manager, RecruitmentRequestListQuery::TEAM),
        );
        $this->assertSame(
            [$teamRequest->id],
            $this->rows($manager, RecruitmentRequestListQuery::NEEDS_REVIEW),
        );
        $this->assertSame(
            [$teamRequest->id],
            app(RecruitmentRequestListQuery::class)->pendingRequestIds($manager),
        );
        $this->assertEqualsCanonicalizing(
            [$mine->id, $teamRequest->id],
            $this->rows($manager),
        );
    }

    public function test_the_page_exposes_only_the_useful_relationship_tabs(): void
    {
        $workflow = $this->workflow();
        $manager = $workflow['manager']->fresh();
        $mine = $this->copy($workflow['request'], Status::Approved);
        $mine->forceFill(['requester_user_id' => $manager->id])->save();

        $screen = Livewire::actingAs($manager)
            ->test(Index::class)
            ->assertSee(__('recruitment.request_views.mine'))
            ->assertSee(__('recruitment.request_views.team'))
            ->assertSee(__('recruitment.request_views.needs_review'))
            ->assertSee('cursor-pointer', false)
            ->call('setRequestView', RecruitmentRequestListQuery::MINE)
            ->assertSet('viewFilter', RecruitmentRequestListQuery::MINE);

        $this->assertSame([
            RecruitmentRequestListQuery::NEEDS_REVIEW,
            RecruitmentRequestListQuery::MINE,
            RecruitmentRequestListQuery::TEAM,
            RecruitmentRequestListQuery::ALL,
        ], $screen->viewData('views'));
    }

    public function test_an_approver_keeps_the_review_tab_when_nothing_is_waiting(): void
    {
        $workflow = $this->workflow();
        $finance = $workflow['finance']->fresh();

        $screen = Livewire::actingAs($finance)
            ->test(Index::class)
            ->assertSee(__('recruitment.request_views.needs_review'));

        $this->assertContains(
            RecruitmentRequestListQuery::NEEDS_REVIEW,
            $screen->viewData('views'),
        );
        $this->assertSame(
            0,
            $screen->viewData('viewCounts')[RecruitmentRequestListQuery::NEEDS_REVIEW],
        );
    }

    public function test_the_relationship_tabs_are_hidden_when_only_my_requests_can_apply(): void
    {
        $workflow = $this->workflow();
        $requester = $workflow['requester']->fresh();

        $screen = Livewire::actingAs($requester)
            ->test(Index::class)
            ->assertDontSee(__('recruitment.request_views.mine'))
            ->assertDontSee(__('recruitment.request_views.team'))
            ->assertDontSee(__('recruitment.request_views.needs_review'));

        $this->assertSame(
            [RecruitmentRequestListQuery::MINE],
            $screen->viewData('views'),
        );
    }

    /** A relationship tab cannot show what the viewer's full scope hides. */
    public function test_a_relationship_tab_never_widens_the_scope(): void
    {
        [$hr, $request] = $this->seeded();

        // A request in another governorate entirely. Built directly rather
        // than through the fixture, which would collide on the city slug.
        $elsewhere = $this->makeCity('LAT', (string) random_int(100, 899));
        $stranger = $this->copy($request, Status::UnderReview);
        $stranger->forceFill(['scope_city_id' => $elsewhere->id])->save();

        $this->assertNotContains($stranger->id, $this->rows($hr));
        $this->assertNotContains(
            $stranger->id,
            $this->rows($hr, RecruitmentRequestListQuery::TEAM),
        );
    }

    /**
     * Every column but the date carries its own control under its heading.
     *
     * The date is deliberately left out: a dropdown of every timestamp is one
     * entry per row, which sorts well and filters not at all.
     */
    public function test_every_column_but_the_date_can_be_filtered(): void
    {
        [$hr] = $this->seeded();
        $this->actingAs($hr);

        $table = new RequestTable;

        $searchable = collect((fn () => $this->columns())->call($table))
            ->filter(fn ($column): bool => $column->isSearchable())
            ->map(fn ($column): string => $column->code())
            ->all();

        foreach (['request_number', 'request_type', 'status', 'mode',
            'priority', 'requester_user_id', 'scope_city_id', 'target_position_id'] as $column) {
            $this->assertContains($column, $searchable, "{$column} should be filterable.");
        }

        $this->assertNotContains('created_at', $searchable);
    }

    public function test_priority_is_shown_and_filters_requests_from_their_payload(): void
    {
        [$hr, $request] = $this->seeded();
        $urgent = $this->copy($request, Status::UnderReview);
        $urgent->forceFill(['draft_payload_json' => ['priority' => 'urgent']])->save();
        $normal = $this->copy($request, Status::UnderReview);
        $normal->forceFill(['draft_payload_json' => ['priority' => 'normal']])->save();

        Livewire::actingAs($hr)
            ->test(RequestTable::class)
            ->set('search.priority', 'urgent')
            ->assertSee($urgent->request_number)
            ->assertSee(__('recruitment.priority_urgent'))
            ->assertDontSee($normal->request_number);
    }

    /** The list orders by columns that exist — sqlite would not say otherwise. */
    public function test_the_list_orders_only_by_columns_that_exist(): void
    {
        [$hr] = $this->seeded();

        $this->assertEveryOrderedColumnExists(function () use ($hr): void {
            Livewire::actingAs($hr)->test(Index::class)->assertOk();
            Livewire::actingAs($hr)->test(RequestTable::class)->assertOk();
        });
    }

    /** @return array{0: User, 1: RecruitmentRequest} */
    private function seeded(): array
    {
        ['hr' => $hr, 'request' => $request] = $this->workflow();

        return [$hr->fresh(), $request];
    }

    /** Another request just like it, in a chosen status. */
    private function copy(RecruitmentRequest $request, int $status): RecruitmentRequest
    {
        return RecruitmentRequest::query()->create([
            'request_number' => 'ZZ-'.random_int(100, 999).'-'.uniqid(),
            'requester_user_id' => $request->requester_user_id,
            'request_type' => Type::FillVacancy,
            'mode' => $request->mode,
            'status' => $status,
            'scope_city_id' => $request->scope_city_id,
            'target_position_id' => $request->target_position_id,
        ]);
    }

    /**
     * The request ids the table would list under a relationship tab.
     *
     * @return array<int, int>
     */
    private function rows(
        User $viewer,
        string $viewFilter = RecruitmentRequestListQuery::ALL,
    ): array {
        $this->actingAs($viewer);

        $table = new RequestTable;
        $table->viewFilter = $viewFilter;

        return (fn () => $this->query())->call($table)->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
    }
}
