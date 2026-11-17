<?php

namespace App\Livewire\Recruitment\Applicants;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentApplicationStatus;
use App\Enums\RecruitmentCvAvailability;
use App\Enums\RecruitmentEvaluationRecommendation;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationFlag;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentTag;
use App\Services\HiringCampaignStateMachine;
use App\Services\RecruitmentApplicationFilters;
use App\Services\RecruitmentInterviewSchedulingService;
use App\Services\RecruitmentMatchingIndicators;
use App\Support\Recruitment\MatchingIndicator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use RamonRietdijk\LivewireTables\Actions\Action;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Filters\DateFilter;
use RamonRietdijk\LivewireTables\Filters\SelectFilter;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

/**
 * One row per applicant. Filters first narrow the matching applications,
 * then the newest matching application represents each applicant in the
 * table. The profile remains the complete application history.
 *
 * Every row comes through RecruitmentApplication::profileVisibleTo(), and so does
 * every filter option; each filter goes through RecruitmentApplicationFilters,
 * which only narrows. A campaign or city id typed into the query string can
 * shrink an already scoped result, never widen it.
 *
 * The indicator column is a summary for the reviewer, computed for the rows
 * on the page only. It is not sortable or filterable on purpose: a list
 * ranked by it would be a decision made by the tool.
 */
class ApplicationTable extends LivewireTable
{
    public const BULK_TAG = 'bulk_tag';

    public const BULK_REJECT = 'bulk_reject';

    public const BULK_REVIEW_ACCESS = 'bulk_review_access';

    /** Opening of the page's one neutral pill; callers close the class attribute. */
    private const PILL = '<span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-control bg-neutral-100 px-2 py-0.5 text-xs font-semibold text-secondary-dark';

    /** Row actions share one width so the column lines up whatever the label. */
    private const ACTION = 'inline-flex h-8 min-w-36 items-center justify-center whitespace-nowrap rounded-control border px-3 text-sm font-semibold shadow-e1 transition duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';

    public string $sortColumn = 'submitted_at';

    public string $sortDirection = 'desc';

    #[Reactive]
    public ?string $stageStatus = null;

    #[Reactive]
    public int|string|null $campaignFilter = null;

    /** 'preferred' | 'alternative' | 'unvoted' | null */
    #[Reactive]
    public ?string $endorsementGroup = null;

    /** 'yes' | 'maybe' | 'no' | 'none' | null */
    #[Reactive]
    public ?string $evaluationRecommendation = null;

    protected string $model = RecruitmentApplication::class;

    /** @var array<int, \App\Support\Recruitment\MatchingIndicatorSet> for the current page */
    protected array $indicatorSets = [];

    public function boot(): void
    {
        Gate::authorize('viewAny', RecruitmentApplicant::class);
    }

    /**
     * Each bulk action hands the selected ids to its own panel without
     * loading a model for each; the panel's service re-scopes every id. The
     * two actions need different permissions, so each is authorized here by
     * its own policy ability, and an unknown code is refused.
     */
    public function executeAction(string $code): mixed
    {
        [$ability, $event, $panel] = match ($code) {
            self::BULK_TAG => ['bulkTag', 'recruitment-bulk-tag', BulkTags::class],
            self::BULK_REJECT => ['bulkReject', 'recruitment-bulk-reject', BulkReject::class],
            self::BULK_REVIEW_ACCESS => ['bulkManageReviewParticipants', 'recruitment-bulk-review-access', BulkReviewParticipants::class],
            default => abort(403),
        };

        Gate::authorize($ability, RecruitmentApplication::class);

        $this->dispatch($event, applicationIds: array_values($this->selected))->to($panel);

        return null;
    }

    /** No per-row actions exist; the call is refused rather than routed. */
    public function executeItemAction(string $code, string $key): mixed
    {
        abort(403);
    }

    /**
     * Selecting the whole filtered table reads ids only. The package default
     * hydrates every row with its relations, which on a large result set is
     * the slowest thing the page can do.
     */
    public function selectTable(bool $select): void
    {
        if (! $this->canSelect()) {
            return;
        }

        $table = $this->appliedQuery()
            ->setEagerLoads([])
            ->limit(app(\App\Services\RecruitmentTagService::class)->bulkLimit())
            ->pluck('recruitment_applications.id')
            ->map(fn ($id): string => (string) $id);

        $selected = collect($this->selected);

        $this->selected = ($select
            ? $selected->merge($table)->unique()
            : $selected->diff($table))->values()->all();
        $this->selectedPage = false;

        $this->updateSession();
    }

    #[On('recruitment-tags-changed')]
    public function refreshTags(): void
    {
        $this->clearSelection();
    }

    #[On('recruitment-flags-changed')]
    public function refreshFlags(): void
    {
        $this->clearSelection();
    }

    #[On('recruitment-review-access-changed')]
    public function refreshReviewAccess(): void
    {
        $this->clearSelection();
    }

    #[On('recruitment-decisions-changed')]
    public function refreshDecisions(): void
    {
        $this->clearSelection();
    }

    #[On('recruitment-applicant-status-filter')]
    public function filterByStatus(?string $status = null): void
    {
        $this->filters['status'] = in_array($status, RecruitmentApplicationStatus::values(), true) ? $status : null;
        $this->clearSelection();
    }

    public function updatedStageStatus(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedCampaignFilter(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedEvaluationRecommendation(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    protected function query(): Builder
    {
        Gate::authorize('viewAny', RecruitmentApplicant::class);

        $filters = app(RecruitmentApplicationFilters::class);

        $query = RecruitmentApplication::query()
            ->profileVisibleTo(auth()->user())
            ->addSelect(['has_previous_application' => $filters->earlierApplication(RecruitmentApplication::query())])
            ->with(['applicant', 'campaign.vacancy.position', 'cvFiles', 'endorsements', 'tags', 'flags'])
            ->when(
                $this->canViewEvaluationDashboard(),
                fn (Builder $applications): Builder => $applications->with([
                    'interviews.evaluations' => function (HasMany $evaluations): void {
                        $evaluations->active();
                    },
                ]),
            );

        $filters->status($query, $this->stageStatus);
        $filters->campaign($query, $this->campaignFilter);
        $filters->endorsementGroup($query, $this->endorsementGroup);
        $filters->evaluationRecommendation($query, $this->evaluationRecommendation);

        return $query;
    }

    /**
     * Apply every table concern before collapsing matching applications so
     * campaign, stage, search, and assessment filters still find applicants
     * through the application that matched the user's selection.
     */
    protected function appliedQuery(): Builder
    {
        $query = $this->query();

        $this
            ->applySelect($query)
            ->applySoftDeletes($query)
            ->applyRelations($query)
            ->applyGlobalSearch($query)
            ->applyColumnSearch($query)
            ->applyFilters($query);

        $qualifiedApplicationId = $query->getQuery()->getGrammar()->wrap(
            $query->qualifyColumn('id'),
        );

        $representativeApplicationIds = (clone $query)
            ->setEagerLoads([])
            ->reorder()
            ->select([])
            ->selectRaw("MAX($qualifiedApplicationId)")
            ->groupBy($query->qualifyColumn('applicant_id'));

        $query->whereIn($query->qualifyColumn('id'), $representativeApplicationIds);

        $this->applySorting($query);

        return $query;
    }

    /** Indicators for the rows on this page, in one pass. */
    protected function paginate(): LengthAwarePaginator
    {
        $paginator = parent::paginate();

        $this->indicatorSets = app(RecruitmentMatchingIndicators::class)
            ->evaluateMany(new Collection($paginator->items()));

        return $paginator;
    }

    protected function link(Model $model): ?string
    {
        return route('recruitment.applicants.show', ['applicantId' => $model->applicant_id]);
    }

    /** @return array<int, Action> */
    protected function actions(): array
    {
        return [
            Action::make(__('recruitment-applicant.tags.bulk_action'), fn () => null, self::BULK_TAG)
                ->canSee(Gate::allows('bulkTag', RecruitmentApplication::class))
                ->keepSelection(),
            Action::make(__('recruitment-applicant.decisions.bulk_action'), fn () => null, self::BULK_REJECT)
                ->canSee(Gate::allows('bulkReject', RecruitmentApplication::class))
                ->keepSelection(),
            Action::make(__('recruitment-applicant.profile_reviews.bulk_action'), fn () => null, self::BULK_REVIEW_ACCESS)
                ->canSee(Gate::allows('bulkManageReviewParticipants', RecruitmentApplication::class))
                ->keepSelection(),
        ];
    }

    /** @return array<int, Column> */
    protected function columns(): array
    {
        $states = app(HiringCampaignStateMachine::class);

        return [
            Column::make(__('recruitment-applicant.name'), 'applicant.full_name')
                ->searchable()
                ->sortable()
                ->displayUsing(fn ($value): string => $this->nameCell((string) $value))
                ->asHtml(),

            Column::make(__('recruitment-applicant.campaign'), function (RecruitmentApplication $application) use ($states): string {
                $title = e($application->campaign?->vacancy?->position?->title ?? __('recruitment-applicant.no_vacancy'));

                if ($application->campaign !== null) {
                    $title = '<span class="block whitespace-nowrap font-semibold text-neutral-900">'.e(__('recruitment-applicant.campaign_number', ['number' => $application->campaign->id])).'</span>'
                        .'<span class="block text-xs text-secondary-medium">'.$title.'</span>';
                }

                // A closed or expired campaign is still worth reviewing, but the
                // reviewer should know nothing more will arrive.
                if ($application->campaign !== null) {
                    $effective = $states->effective($application->campaign);
                    if (! in_array($effective, [HiringCampaignStatus::Published, HiringCampaignStatus::Open], true)) {
                        $title .= '<span class="mt-1 inline-flex items-center gap-1 rounded-inset bg-neutral-100 px-1.5 py-0.5 text-xs text-secondary-dark">'
                            .e(HiringCampaignStatus::getLocalizedName($effective)['value']).'</span>';
                    }
                }

                return $title;
            })->asHtml(),

            Column::make(__('recruitment-applicant.applied_at'), 'submitted_at')
                ->sortable()
                ->displayUsing(fn ($value): string => $value
                    ? '<time datetime="'.e($value->toDateString()).'" class="whitespace-nowrap tabular-nums text-secondary-dark">'.e($value->translatedFormat('j M Y')).'</time>'
                    : '<span class="text-secondary-light">—</span>')
                ->asHtml(),

            Column::make(__('recruitment-applicant.status'), 'status')
                ->sortable()
                ->displayUsing(fn ($value): string => $this->statusPill((string) $value))
                ->asHtml(),

            Column::make(__('recruitment-applicant.endorsement.column'), function (RecruitmentApplication $application): string {
                $roles = $application->endorsements->pluck('voter_role')->unique()->values();
                $count = $roles->count();
                $group = match (true) {
                    $count >= 2 => 'preferred',
                    $count === 1 => 'alternative',
                    default => 'unvoted',
                };
                if ($group === 'unvoted') {
                    return '<span class="whitespace-nowrap text-xs text-secondary-medium">'.e(__('recruitment-applicant.endorsement.group_unvoted')).'</span>';
                }

                return self::PILL.'"><span aria-hidden="true" class="size-1.5 rounded-full '.($group === 'preferred' ? 'bg-neutral-900' : 'bg-neutral-500').'"></span>'
                    .e(__('recruitment-applicant.endorsement.group_'.$group)).'</span>';
            })->asHtml(),

            ...($this->canViewEvaluationDashboard() ? [
                Column::make(__('recruitment-applicant.dashboard.evaluation_column'), function (RecruitmentApplication $application): string {
                    $counts = $application->interviews
                        ->flatMap->evaluations
                        ->countBy('recommendation');

                    if ($counts->isEmpty()) {
                        return '<span class="whitespace-nowrap text-xs text-secondary-medium">'.e(__('recruitment-applicant.dashboard.not_evaluated')).'</span>';
                    }

                    return '<span class="flex flex-wrap gap-1">'.collect(RecruitmentEvaluationRecommendation::values())
                        ->filter(fn (string $recommendation): bool => (int) $counts->get($recommendation, 0) > 0)
                        ->map(fn (string $recommendation): string => self::PILL.'">'
                            .e(__('recruitment-applicant.evaluations.recommendations.'.$recommendation))
                            .' <span class="tabular-nums text-secondary-medium">'.(int) $counts->get($recommendation).'</span></span>')
                        ->implode('').'</span>';
                })->asHtml(),
            ] : []),

            Column::make(__('recruitment-applicant.source'), 'applicant.source')
                ->displayUsing(fn ($value): string => '<span class="whitespace-nowrap text-secondary-dark">'.e(RecruitmentApplicant::sourceLabel($value)).'</span>')
                ->asHtml(),

            Column::make(__('recruitment-applicant.cv'), function (RecruitmentApplication $application): string {
                $availability = RecruitmentCvAvailability::of($application->cvFiles);

                return self::PILL.'">'
                    .e(__('recruitment-applicant.cv_availability.'.$availability)).'</span>';
            })->asHtml(),

            // A flag, never a decision: nothing is rejected for having applied before.
            Column::make(__('recruitment-applicant.previous_applicant'), function (RecruitmentApplication $application): string {
                return $application->has_previous_application
                    ? self::PILL.'">'.e(__('recruitment-applicant.previous_applicant_flag')).'</span>'
                    : '<span class="text-secondary-light" aria-hidden="true">—</span>';
            })->asHtml(),

            Column::make(__('recruitment-applicant.tags.title'), function (RecruitmentApplication $application): string {
                return '<span class="flex flex-wrap gap-1">'.$application->tags
                    ->map(fn (RecruitmentTag $tag): string => '<span class="inline-flex whitespace-nowrap rounded-control px-2 py-0.5 text-xs font-semibold '
                        .$tag->classes().'">'.e($tag->name).'</span>')
                    ->implode('').'</span>';
            })->asHtml(),

            Column::make(__('recruitment-applicant.flags.title'), function (RecruitmentApplication $application): string {
                // A red flag is a warning a reviewer must not miss, so it keeps the brand red outline; everything else is grey.
                return '<span class="flex flex-wrap gap-1">'.$application->flags
                    ->map(fn (RecruitmentApplicationFlag $flag): string => '<span class="inline-flex whitespace-nowrap rounded-control px-2 py-0.5 text-xs font-semibold '.($flag->type === RecruitmentApplicationFlag::TYPE_RED ? 'border border-primary bg-white text-primary' : 'bg-neutral-100 text-secondary-dark').'">'.e($flag->typeLabel()).': '.e($flag->reasonLabel()).'</span>')
                    ->implode('').'</span>';
            })->asHtml(),

            Column::make(__('recruitment-applicant.indicators.column'), function (RecruitmentApplication $application): string {
                return $this->indicatorSummary($application);
            })->asHtml(),

            Column::make(__('recruitment-applicant.next_action'), function (RecruitmentApplication $application): string {
                if (Gate::allows('schedule', [RecruitmentInterview::class, $application])
                    && in_array($application->status, RecruitmentInterviewSchedulingService::SCHEDULABLE, true)) {
                    $label = $application->status === RecruitmentApplicationStatus::Finalist
                        ? __('recruitment-applicant.interviews.schedule_final')
                        : __('recruitment-applicant.interviews.schedule');

                    // Solid ink: this row is waiting on someone. Red stays rationed to page-level decisions.
                    return '<a href="'.e(route('recruitment.interviews.create', ['applicationId' => $application->id])).'" wire:navigate x-on:click.stop'
                        .' class="'.self::ACTION.' border-neutral-900 bg-neutral-900 text-white hover:bg-neutral-800">'
                        .e($label).'</a>';
                }

                return '<a href="'.e(route('recruitment.applicants.show', ['applicantId' => $application->applicant_id])).'" wire:navigate x-on:click.stop'
                    .' class="'.self::ACTION.' border-neutral-200 bg-white text-secondary-dark hover:bg-selection">'
                    .e(__('recruitment-applicant.profile.open')).'</a>';
            })->asHtml(),
        ];
    }

    /** @return array<int, SelectFilter|DateFilter> */
    protected function filters(): array
    {
        $user = auth()->user();
        $filters = app(RecruitmentApplicationFilters::class);

        return [
            SelectFilter::make(__('recruitment-applicant.campaign'), fn (Builder $query, $value) => $filters->campaign($query, $value), 'campaign_id')
                ->options($filters->campaignOptions($user)),

            SelectFilter::make(__('recruitment-applicant.status'), fn (Builder $query, $value) => $filters->status($query, $value), 'status')
                ->options($filters->statusOptions()),

            SelectFilter::make(__('recruitment-applicant.filters.city'), fn (Builder $query, $value) => $filters->city($query, $value), 'city')
                ->options($filters->cityOptions($user)),

            DateFilter::make(__('recruitment-applicant.applied_at'), 'submitted_at'),

            SelectFilter::make(__('recruitment-applicant.source'), fn (Builder $query, $value) => $filters->source($query, $value), 'applicant_source')
                ->options($filters->sourceOptions($user)),

            SelectFilter::make(__('recruitment-applicant.tags.title'), fn (Builder $query, $value) => $filters->tag($query, $value), 'tag')
                ->options($filters->tagOptions()),

            SelectFilter::make(__('recruitment-applicant.flags.type'), fn (Builder $query, $value) => $filters->flagType($query, $value), 'flag_type')
                ->options($filters->flagTypeOptions()),

            SelectFilter::make(__('recruitment-applicant.flags.category'), fn (Builder $query, $value) => $filters->flagCategory($query, $value), 'flag_category')
                ->options($filters->flagCategoryOptions()),

            SelectFilter::make(__('recruitment-applicant.cv'), fn (Builder $query, $value) => $filters->cv($query, $value), 'cv')
                ->options($filters->cvOptions()),

            SelectFilter::make(__('recruitment-applicant.previous_applicant'), fn (Builder $query, $value) => $filters->previousApplicant($query, $value), 'previous')
                ->options($filters->previousOptions()),

            SelectFilter::make(__('recruitment-applicant.endorsement.groups_label'), fn (Builder $query, $value) => $filters->endorsementGroup($query, $value), 'endorsement_group')
                ->options($filters->endorsementGroupOptions()),

            ...($this->canViewEvaluationDashboard() ? [
                SelectFilter::make(__('recruitment-applicant.dashboard.evaluation_filter'), fn (Builder $query, $value) => $filters->evaluationRecommendation($query, $value), 'evaluation')
                    ->options($filters->evaluationRecommendationOptions()),
            ] : []),
        ];
    }

    private function canViewEvaluationDashboard(): bool
    {
        return auth()->user()->hasActivePermission('recruitment-applicants.view-evaluations');
    }

    /**
     * "n of m met", with every indicator named — the count alone is never
     * shown without what it counts. A tooltip only reaches a mouse, so the
     * same list is also in the cell as text for a screen reader; the full
     * breakdown is on the profile the row links to.
     */
    private function indicatorSummary(RecruitmentApplication $application): string
    {
        $set = $this->indicatorSets[$application->getKey()] ?? null;

        if ($set === null) {
            return '';
        }

        $lines = collect($set->indicators)
            ->map(fn (MatchingIndicator $indicator): string => $indicator->label().': '.__('recruitment-applicant.indicators.outcomes.'.$indicator->outcome));

        $summary = $set->assessedCount() === 0
            ? __('recruitment-applicant.indicators.none_assessed')
            : __('recruitment-applicant.indicators.summary', ['met' => $set->metCount(), 'assessed' => $set->assessedCount()]);

        // A short segmented meter under the words: one segment per assessed indicator, filled when met.
        $meter = $set->assessedCount() === 0 ? '' : '<span aria-hidden="true" class="mt-1 flex gap-0.5">'
            .str_repeat('<span class="h-1 w-4 rounded-full bg-neutral-900"></span>', $set->metCount())
            .str_repeat('<span class="h-1 w-4 rounded-full bg-neutral-200"></span>', max($set->assessedCount() - $set->metCount(), 0))
            .'</span>';

        return '<span title="'.e($lines->implode("\n")).'" class="inline-flex cursor-help flex-col whitespace-nowrap text-xs font-semibold text-secondary-dark">'
            .e($summary).$meter.'</span>'
            .($lines->isEmpty() ? '' : '<span class="sr-only"> ('.e($lines->implode(__('recruitment-applicant.list_separator'))).')</span>');
    }

    /** Monogram + name: the row's anchor, so the eye finds the person before the data. */
    private function nameCell(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = collect(array_slice($words, 0, 2))->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('') ?: '?';

        return '<span class="flex min-w-40 items-center gap-2.5">'
            .'<span aria-hidden="true" class="inline-flex size-8 shrink-0 select-none items-center justify-center rounded-full bg-neutral-100 text-caption font-semibold text-secondary-dark">'.e($initials).'</span>'
            .'<span class="font-semibold text-neutral-900"><bdi>'.e($name).'</bdi></span></span>';
    }

    /**
     * Status as a dot + label in one grey pill. The dot carries the stage:
     * hollow while waiting, mid-grey while in review, solid ink once chosen,
     * faded once out of the process — so the column scans without colour.
     */
    private function statusPill(string $status): string
    {
        $dot = match ($status) {
            RecruitmentApplicationStatus::Submitted => 'border border-neutral-500 bg-white',
            RecruitmentApplicationStatus::Shortlisted, RecruitmentApplicationStatus::InterviewScheduled,
            RecruitmentApplicationStatus::Interviewed, RecruitmentApplicationStatus::Finalist => 'bg-neutral-500',
            RecruitmentApplicationStatus::Selected, RecruitmentApplicationStatus::OfferSent,
            RecruitmentApplicationStatus::OfferAccepted, RecruitmentApplicationStatus::Hired => 'bg-neutral-900',
            default => 'bg-neutral-300',
        };

        return self::PILL.'"><span aria-hidden="true" class="size-1.5 shrink-0 rounded-full '.$dot.'"></span>'
            .e(__('recruitment-applicant.statuses.'.$status)).'</span>';
    }
}
