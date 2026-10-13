<?php

namespace App\Livewire\Recruitment\Requests;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Models\City;
use App\Models\HiringCampaign;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\HiringCampaignStateMachine;
use App\Services\RecruitmentRequestListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Enums\SearchScope;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

class RequestTable extends LivewireTable
{
    public string $sortColumn = 'created_at';

    public string $sortDirection = 'desc';

    public string $viewFilter = RecruitmentRequestListQuery::ALL;

    protected string $model = RecruitmentRequest::class;

    /** @var array<int, true>|null */
    private ?array $pendingReviewLookup = null;

    public function boot(): void
    {
        Gate::authorize('viewAny', RecruitmentRequest::class);
    }

    protected function query(): Builder
    {
        Gate::authorize('viewAny', RecruitmentRequest::class);

        return app(RecruitmentRequestListQuery::class)
            ->forUser(auth()->user(), $this->viewFilter)
            ->with([
                'requester.profile',
                'scopeCity',
                'targetPosition',
                'latestVersion.positionDetail',
                'vacancy.campaigns',
            ]);
    }

    protected function link(Model $model): ?string
    {
        return route('recruitment.requests.show', ['requestId' => $model->getKey()]);
    }

    /**
     * The columns, each carrying its own control under its own heading.
     *
     * The package renders a second header row with a control for every column
     * that is searchable, which is where a filter belongs: under the name of
     * the thing it filters. A separate panel makes people hunt for the filter
     * and then remember which column it applied to.
     *
     * The date is deliberately left unsearchable. A dropdown of every
     * timestamp is one entry per row, which sorts well and filters not at all,
     * and the column is already sortable.
     *
     * Options come from the rows this viewer can see rather than from the
     * whole table, so the list never names a person or a place outside their
     * scope.
     *
     * @return array<int, Column>
     */
    protected function columns(): array
    {
        return [
            Column::make(__('recruitment.number'), 'request_number')->sortable()->searchable(),

            SelectColumn::make(__('recruitment.type'), 'request_type')
                ->options($this->enumOptions(RecruitmentRequestType::class, 'request_type'))
                ->displayUsing(fn ($value) => RecruitmentRequestType::getLocalizedName((int) $value)['value'])
                ->sortable()->searchable(),

            SelectColumn::make(__('recruitment.status'), 'status')
                ->options($this->enumOptions(RecruitmentRequestStatus::class, 'status'))
                ->displayUsing(fn ($value) => RecruitmentRequestStatus::getLocalizedName((int) $value)['value'])
                ->sortable()->searchable(),

            SelectColumn::make(__('recruitment.mode'), 'mode')
                ->options($this->enumOptions(RecruitmentRequestMode::class, 'mode'))
                ->displayUsing(fn ($value) => RecruitmentRequestMode::getLocalizedName((int) $value)['value'])
                ->searchable(),

            SelectColumn::make(
                __('recruitment.priority'),
                'latestVersion.positionDetail.priority',
                'priority',
            )
                ->options($this->priorityOptions())
                ->displayUsing(fn ($value, Model $request): string => $this->priorityLabel(
                    $this->requestPriority($request),
                ))
                ->searchable(fn (Builder $query, mixed $search, SearchScope $scope) => $this
                    ->applyPrioritySearch($query, $search, $scope)),

            SelectColumn::make(__('recruitment.requester'), 'requester_user_id')
                ->options($this->requesterOptions())
                ->displayUsing(fn ($value, Model $request): string => $this->requesterCell($request))
                ->asHtml()
                ->searchable(),

            SelectColumn::make(__('recruitment.city'), 'scope_city_id')
                ->options($this->cityOptions())
                ->displayUsing(fn ($value, Model $request) => $request->scopeCity?->name ?? '—')
                ->searchable(),

            SelectColumn::make(__('recruitment.position'), 'target_position_id')
                ->options($this->positionOptions())
                ->displayUsing(fn ($value, Model $request) => $request->targetPosition?->title ?? '—')
                ->searchable(),

            Column::make(__('recruitment.created_at'), 'created_at')
                ->displayUsing(fn ($value) => $value?->format('Y-m-d H:i'))->sortable(),

            // An approved request has a seat waiting to be advertised, and the
            // person who advertises it is HR. Handing the vacancy over here
            // saves them finding it again in a list of every open seat.
            //
            // The column is hidden outright from anybody who cannot run a
            // campaign, and each row only offers the link when the request has
            // actually produced a seat to advertise.
            Column::make(__('recruitment-campaign.campaign'), 'id')
                ->displayUsing(fn ($value, Model $request): string => $this->campaignActions($request))
                ->asHtml()
                ->clickable(false)
                ->visible($this->mayRunCampaigns()),
        ];
    }

    /** @return array<int, string> */
    private function requesterOptions(): array
    {
        return User::query()
            ->whereIn('id', $this->visible()->distinct()->pluck('requester_user_id'))
            ->with('profile')->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->profile?->name ?? $user->name ?? '—'])
            ->all();
    }

    /** @return array<string, string> */
    private function priorityOptions(): array
    {
        return $this->visible()
            ->with('latestVersion.positionDetail')
            ->get()
            ->map(fn (RecruitmentRequest $request): ?string => $this->requestPriority($request))
            ->filter()
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $priority): array => [
                $priority => $this->priorityLabel($priority),
            ])
            ->all();
    }

    private function requestPriority(Model $request): ?string
    {
        return $request->latestVersion?->positionDetail?->priority
            ?? data_get($request->draft_payload_json, 'priority');
    }

    private function priorityLabel(?string $priority): string
    {
        return $priority === null ? '—' : __('recruitment.priority_'.$priority);
    }

    private function applyPrioritySearch(
        Builder $query,
        mixed $search,
        SearchScope $scope,
    ): void {
        $priorities = collect(config('recruitment.request_form.priorities', []));

        if ($scope === SearchScope::Column) {
            $priorities = $priorities->filter(
                fn (string $priority): bool => $priority === (string) $search,
            );
        } else {
            $needle = mb_strtolower((string) $search);
            $priorities = $priorities->filter(fn (string $priority): bool => str_contains(
                mb_strtolower($this->priorityLabel($priority)),
                $needle,
            ));
        }

        if ($priorities->isEmpty()) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where(function (Builder $prioritized) use ($priorities): void {
            $prioritized
                ->whereIn('draft_payload_json->priority', $priorities->all())
                ->orWhereHas('latestVersion.positionDetail', fn (Builder $details): Builder => $details
                    ->whereIn('priority', $priorities->all()));
        });
    }

    private function requesterCell(Model $request): string
    {
        return Blade::render(
            <<<'BLADE'
                <div class="space-y-1.5">
                    <div>{{ $name }}</div>
                    @if ($needsReview)
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="inline-flex h-5 items-center rounded-full bg-primary px-2 text-xs leading-none font-semibold text-white">
                                {{ __('recruitment.needs_my_review') }}
                            </span>
                        </div>
                    @endif
                </div>
                BLADE,
            [
                'name' => $request->requester?->profile?->name ?? $request->requester?->name ?? '—',
                'needsReview' => isset($this->pendingReviewLookup()[(int) $request->getKey()]),
            ],
        );
    }

    /** @return array<int, true> */
    private function pendingReviewLookup(): array
    {
        if ($this->pendingReviewLookup === null) {
            $this->pendingReviewLookup = array_fill_keys(
                app(RecruitmentRequestListQuery::class)->pendingRequestIds(auth()->user()),
                true,
            );
        }

        return $this->pendingReviewLookup;
    }

    /** @return array<int, string> */
    private function cityOptions(): array
    {
        return City::query()
            ->whereIn('id', $this->visible()->whereNotNull('scope_city_id')->distinct()->pluck('scope_city_id'))
            ->orderBy(transColumn('name'))->get()
            ->mapWithKeys(fn (City $city): array => [$city->id => $city->name])
            ->all();
    }

    /** @return array<int, string> */
    private function positionOptions(): array
    {
        return $this->visible()
            ->whereNotNull('target_position_id')
            ->with('targetPosition')->get()
            ->filter(fn (RecruitmentRequest $request): bool => $request->targetPosition !== null)
            ->mapWithKeys(fn (RecruitmentRequest $request): array => [
                $request->target_position_id => $request->targetPosition->title,
            ])
            ->all();
    }

    /**
     * Every value of an enum that this viewer's rows actually use.
     *
     * Offering a filter that can only ever return nothing is worse than not
     * offering it, so the options come from the data rather than the enum.
     *
     * @param  class-string  $enum
     * @return array<int, string>
     */
    private function enumOptions(string $enum, string $column): array
    {
        return $this->visible()
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->mapWithKeys(fn ($value): array => [
                (int) $value => $enum::getLocalizedName((int) $value)['value'],
            ])
            ->all();
    }

    /** The rows this viewer may see, before any filter narrows them. */
    private function visible(): Builder
    {
        return RecruitmentRequest::query()->visibleTo(auth()->user());
    }

    /** May this user create campaigns at all? */
    private function mayRunCampaigns(): bool
    {
        return (bool) auth()->user()?->hasActivePermission('recruitment-campaigns.manage');
    }

    private function campaignActions(Model $request): string
    {
        if (! in_array((int) $request->status, [RecruitmentRequestStatus::Approved, RecruitmentRequestStatus::Applied], true)) {
            return '';
        }

        $vacancy = $request->vacancy;

        if ($vacancy === null) {
            return '';
        }

        $states = app(HiringCampaignStateMachine::class);
        $campaigns = $vacancy->campaigns->sortByDesc('id');
        $ongoing = $campaigns->first(fn (HiringCampaign $campaign): bool => in_array(
            $states->effective($campaign),
            [HiringCampaignStatus::Draft, HiringCampaignStatus::Published, HiringCampaignStatus::Open],
            true,
        ));

        if ($ongoing !== null) {
            return $this->campaignButton(
                route('recruitment.campaigns.show', ['campaignId' => $ongoing->getKey()]),
                (int) $ongoing->status === HiringCampaignStatus::Draft
                    ? __('recruitment-campaign.continue_campaign')
                    : __('recruitment-campaign.view_campaign'),
            );
        }

        $buttons = [];
        $latest = $campaigns->first();

        if ($latest !== null) {
            $buttons[] = $this->campaignButton(
                route('recruitment.campaigns.show', ['campaignId' => $latest->getKey()]),
                __('recruitment-campaign.view_campaign'),
                outlined: true,
            );
        }

        if ($this->vacancyIsAvailable($vacancy)) {
            $buttons[] = $this->campaignButton(
                route('recruitment.campaigns.create', ['vacancyId' => $vacancy->getKey()]),
                __('recruitment-campaign.new_campaign'),
            );
        }

        return $buttons === [] ? '' : '<div class="flex flex-wrap gap-2">'.implode('', $buttons).'</div>';
    }

    private function campaignButton(string $url, string $label, bool $outlined = false): string
    {
        return Blade::render(
            '<a href="{{ $url }}" wire:navigate @class(['
            .'"inline-flex h-7 min-h-7 w-40 items-center justify-center rounded-control px-3 py-0 text-center text-sm font-semibold transition", '
            .'"border border-primary text-primary hover:bg-neutral-50" => $outlined, '
            .'"bg-primary text-white hover:bg-primary-600" => ! $outlined, '
            .'])>{{ $label }}</a>',
            compact('url', 'label', 'outlined'),
        );
    }

    private function vacancyIsAvailable(Vacancy $vacancy): bool
    {
        return $vacancy->status === Vacancy::OPEN
            && $vacancy->closed_at === null
            && ($vacancy->open_at === null || $vacancy->open_at->isPast())
            && $vacancy->filled_count < $vacancy->needed_count;
    }
}
