<?php

namespace App\Livewire\Recruitment\Campaigns;

use App\Enums\HiringCampaignStatus;
use App\Models\HiringCampaign;
use App\Models\Position;
use App\Models\User;
use App\Services\HiringCampaignStateMachine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Enums\Direction;
use RamonRietdijk\LivewireTables\Enums\SearchScope;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

class CampaignTable extends LivewireTable
{
    public string $sortColumn = 'created_at';

    public string $sortDirection = 'desc';

    protected string $model = HiringCampaign::class;

    public function boot(): void
    {
        Gate::authorize('viewAny', HiringCampaign::class);
    }

    protected function query(): Builder
    {
        Gate::authorize('viewAny', HiringCampaign::class);

        return HiringCampaign::query()->visibleTo(auth()->user())
            ->with(['vacancy.position', 'vacancy.createdFromRequest', 'creator.profile']);
    }

    protected function link(Model $model): ?string
    {
        return route('recruitment.campaigns.show', ['campaignId' => $model->getKey()]);
    }

    /** @return array<int, Column> */
    protected function columns(): array
    {
        return [
            Column::make(__('recruitment-campaign.request'), 'vacancy.createdFromRequest.request_number')
                ->displayUsing(function ($value, HiringCampaign $campaign): string {
                    $request = $campaign->vacancy?->createdFromRequest;

                    if ($request === null) {
                        return '—';
                    }

                    return Blade::render(
                        '<a href="{{ $url }}" wire:navigate class="font-semibold text-primary hover:underline">{{ $number }}</a>',
                        [
                            'url' => route('recruitment.requests.show', ['requestId' => $request->getKey()]),
                            'number' => $request->request_number,
                        ],
                    );
                })
                ->asHtml()
                ->clickable(false)
                ->sortable()
                ->searchable(),

            SelectColumn::make(__('recruitment-campaign.position'), 'vacancy.position_id')
                ->options($this->positionOptions())
                ->displayUsing(fn ($value, HiringCampaign $campaign): string => $campaign->vacancy?->position?->title ?? '—')
                ->searchable(),

            SelectColumn::make(__('recruitment-campaign.link_mode'), 'link_mode')
                ->options($this->linkModeOptions())
                ->displayUsing(fn ($value): string => __('recruitment-campaign.modes.'.$value))
                ->searchable(),

            // The status as the clock sees it, not as the column reads: a
            // campaign whose window shut an hour ago is expired here too.
            SelectColumn::make(__('recruitment-campaign.status'), 'status')
                ->options($this->statusOptions())
                ->displayUsing(function ($value, HiringCampaign $campaign): string {
                    $effective = app(HiringCampaignStateMachine::class)->effective($campaign);

                    return HiringCampaignStatus::getLocalizedName($effective)['value'];
                })
                ->searchable(fn (Builder $query, mixed $search, SearchScope $scope) => $this
                    ->applyStatusSearch($query, $search, $scope)),

            Column::make(__('recruitment-campaign.starts_at'), 'starts_at')
                ->displayUsing(fn ($value): string => $value?->format('Y-m-d') ?? '—'),

            Column::make(__('recruitment-campaign.ends_at'), 'ends_at')
                ->displayUsing(fn ($value): string => $value?->format('Y-m-d') ?? '—'),

            SelectColumn::make(__('recruitment-campaign.created_by'), 'created_by_user_id')
                ->options($this->creatorOptions())
                ->displayUsing(fn ($value, HiringCampaign $campaign): string => $campaign->creator?->profile?->name
                    ?? $campaign->creator?->name ?? '—')
                ->searchable(),

            Column::make(__('recruitment-campaign.created_at'), 'created_at')
                ->displayUsing(fn ($value): string => $value?->format('Y-m-d H:i') ?? '—')
                ->sortable(function (Builder $query, Direction $direction): void {
                    $query
                        ->orderBy('hiring_campaigns.created_at', $direction->value)
                        ->orderBy('hiring_campaigns.id', $direction->value);
                }),
        ];
    }

    /** @return array<int, string> */
    private function positionOptions(): array
    {
        return Position::query()
            ->whereIn(
                'id',
                $this->visible()
                    ->join('vacancies', 'vacancies.id', '=', 'hiring_campaigns.vacancy_id')
                    ->distinct()
                    ->pluck('vacancies.position_id'),
            )
            ->orderBy(transColumn('title'))
            ->get()
            ->mapWithKeys(fn (Position $position): array => [$position->id => $position->title])
            ->all();
    }

    /** @return array<string, string> */
    private function linkModeOptions(): array
    {
        return $this->visible()
            ->distinct()
            ->orderBy('link_mode')
            ->pluck('link_mode')
            ->mapWithKeys(fn (string $mode): array => [
                $mode => __('recruitment-campaign.modes.'.$mode),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function statusOptions(): array
    {
        return $this->visible()
            ->get()
            ->map(fn (HiringCampaign $campaign): int => app(HiringCampaignStateMachine::class)->effective($campaign))
            ->unique()
            ->sort()
            ->mapWithKeys(fn (int $status): array => [
                $status => HiringCampaignStatus::getLocalizedName($status)['value'],
            ])
            ->all();
    }

    private function applyStatusSearch(Builder $query, mixed $search, SearchScope $scope): void
    {
        $statuses = collect($this->statusOptions());

        if ($scope === SearchScope::Column) {
            $statuses = $statuses->filter(
                fn (string $label, int $status): bool => $status === (int) $search,
            );
        } else {
            $needle = mb_strtolower((string) $search);
            $statuses = $statuses->filter(
                fn (string $label): bool => str_contains(mb_strtolower($label), $needle),
            );
        }

        if ($statuses->isEmpty()) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where(function (Builder $matching) use ($statuses): void {
            foreach ($statuses->keys() as $status) {
                $matching->orWhere(function (Builder $candidate) use ($status): void {
                    if ((int) $status === HiringCampaignStatus::Expired) {
                        $candidate
                            ->where(function (Builder $expiredByClock): void {
                                $expiredByClock
                                    ->whereIn('hiring_campaigns.status', [
                                        HiringCampaignStatus::Published,
                                        HiringCampaignStatus::Open,
                                    ])
                                    ->whereNotNull('hiring_campaigns.ends_at')
                                    ->where('hiring_campaigns.ends_at', '<=', now());
                            })
                            ->orWhere('hiring_campaigns.status', HiringCampaignStatus::Expired);

                        return;
                    }

                    $candidate->where('hiring_campaigns.status', $status);

                    if (in_array((int) $status, [HiringCampaignStatus::Published, HiringCampaignStatus::Open], true)) {
                        $candidate->where(function (Builder $window): void {
                            $window->whereNull('hiring_campaigns.ends_at')
                                ->orWhere('hiring_campaigns.ends_at', '>', now());
                        });
                    }
                });
            }
        });
    }

    /** @return array<int, string> */
    private function creatorOptions(): array
    {
        return User::query()
            ->whereIn(
                'id',
                $this->visible()
                    ->whereNotNull('created_by_user_id')
                    ->distinct()
                    ->pluck('created_by_user_id'),
            )
            ->with('profile')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => $user->profile?->name ?? $user->name ?? '—',
            ])
            ->all();
    }

    private function visible(): Builder
    {
        return HiringCampaign::query()->visibleTo(auth()->user());
    }
}
