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
    private const PILL = '<span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-control bg-neutral-100 px-2 py-0.5 text-xs font-semibold text-secondary-dark';

    private const DASH = '<span class="text-secondary-light">—</span>';

    public string $sortColumn = 'created_at';

    public string $sortDirection = 'desc';

    /** Set by the status tiles on the page; narrows by effective status. */
    public ?int $statusFilter = null;

    protected string $model = HiringCampaign::class;

    public function boot(): void
    {
        Gate::authorize('viewAny', HiringCampaign::class);
    }

    protected function query(): Builder
    {
        Gate::authorize('viewAny', HiringCampaign::class);

        $query = HiringCampaign::query()->visibleTo(auth()->user())
            ->with(['vacancy.position', 'vacancy.createdFromRequest', 'creator.profile'])
            ->withCount('applications');

        if ($this->statusFilter !== null) {
            $this->applyStatusSearch($query, $this->statusFilter, SearchScope::Column);
        }

        return $query;
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
                        return self::DASH;
                    }

                    // Long generated numbers are cut with an ellipsis; the full value is on hover.
                    return Blade::render(
                        '<a href="{{ $url }}" wire:navigate title="{{ $number }}" x-on:click.stop class="block max-w-40 truncate font-semibold tabular-nums text-neutral-900 underline decoration-neutral-300 underline-offset-4 hover:text-primary hover:decoration-primary"><bdi>{{ $number }}</bdi></a>',
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
                ->displayUsing(fn ($value, HiringCampaign $campaign): string => ($title = $campaign->vacancy?->position?->title)
                    ? '<span title="'.e($title).'" class="block max-w-72 truncate font-medium text-neutral-900">'.e($title).'</span>'
                    : self::DASH)
                ->asHtml()
                ->searchable(),

            SelectColumn::make(__('recruitment-campaign.link_mode'), 'link_mode')
                ->options($this->linkModeOptions())
                ->displayUsing(fn ($value): string => $this->audienceCell((string) $value))
                ->asHtml()
                ->searchable(),

            // The status as the clock sees it, not as the column reads: a
            // campaign whose window shut an hour ago is expired here too.
            SelectColumn::make(__('recruitment-campaign.status'), 'status')
                ->options($this->statusOptions())
                ->displayUsing(function ($value, HiringCampaign $campaign): string {
                    $effective = app(HiringCampaignStateMachine::class)->effective($campaign);

                    return $this->statusPill($effective);
                })
                ->asHtml()
                ->searchable(fn (Builder $query, mixed $search, SearchScope $scope) => $this
                    ->applyStatusSearch($query, $search, $scope)),

            // How many people applied: the number people open this list to find.
            Column::make(__('recruitment-applicant.applicants'), 'applications_count')
                ->displayUsing(fn ($value): string => (int) $value === 0
                    ? '<span class="tabular-nums text-secondary-light">0</span>'
                    : '<span class="font-semibold tabular-nums text-neutral-900">'.(int) $value.'</span>')
                ->asHtml()
                ->sortable(fn (Builder $query, Direction $direction) => $query->orderBy('applications_count', $direction->value)),

            // Start and end as one window, with how long is left while it is still running.
            Column::make(__('recruitment-campaign.ends_at'), 'ends_at')
                ->displayUsing(fn ($value, HiringCampaign $campaign): string => $this->windowCell($campaign))
                ->asHtml()
                ->sortable(),

            SelectColumn::make(__('recruitment-campaign.created_by'), 'created_by_user_id')
                ->options($this->creatorOptions())
                ->displayUsing(fn ($value, HiringCampaign $campaign): string => $this->personCell($campaign->creator?->profile?->name
                    ?? $campaign->creator?->name))
                ->asHtml()
                ->searchable(),

            Column::make(__('recruitment-campaign.created_at'), 'created_at')
                ->displayUsing(fn ($value): string => $value === null ? self::DASH
                    : '<time datetime="'.e($value->toIso8601String()).'" class="block whitespace-nowrap tabular-nums">'
                        .'<span class="block text-secondary-dark">'.e($value->translatedFormat('j M Y')).'</span>'
                        .'<span class="block text-xs text-secondary-medium">'.e($value->format('H:i')).'</span></time>')
                ->asHtml()
                ->sortable(function (Builder $query, Direction $direction): void {
                    $query
                        ->orderBy('hiring_campaigns.created_at', $direction->value)
                        ->orderBy('hiring_campaigns.id', $direction->value);
                }),
        ];
    }

    /** Grey pill + stage dot: hollow = being prepared, ink = live, faded = over. */
    private function statusPill(int $status): string
    {
        $dot = match ($status) {
            HiringCampaignStatus::Open => 'bg-neutral-900',
            HiringCampaignStatus::Published => 'bg-neutral-500',
            HiringCampaignStatus::Draft => 'border border-neutral-500 bg-white',
            default => 'bg-neutral-300',
        };

        return self::PILL.'"><span aria-hidden="true" class="size-1.5 shrink-0 rounded-full '.$dot.'"></span>'
            .e(HiringCampaignStatus::getLocalizedName($status)['value']).'</span>';
    }

    /** Audience with an icon, so public / staff / invite-only read at a glance without colour. */
    private function audienceCell(string $mode): string
    {
        $icon = match ($mode) {
            HiringCampaign::PUBLIC_OPEN => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zM3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 010 18M12 3a15 15 0 000 18"/>',
            HiringCampaign::INTERNAL => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/>',
            default => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 7l9 6 9-6M3 7v10h18V7M3 7h18"/>',
        };

        return '<span class="inline-flex items-center gap-2 whitespace-nowrap text-secondary-dark">'
            .'<svg class="size-4 shrink-0 text-secondary-medium" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'.$icon.'</svg>'
            .e(__('recruitment-campaign.modes.'.$mode)).'</span>';
    }

    /**
     * "21 Oct → 29 Nov" on top; underneath, the time left while live. Under a week left
     * is bold ink so it stands out in the column without turning red.
     */
    private function windowCell(HiringCampaign $campaign): string
    {
        $start = $campaign->starts_at;
        $end = $campaign->ends_at;

        if ($start === null && $end === null) {
            return '<span class="whitespace-nowrap text-xs text-secondary-medium">'.e(__('recruitment-campaign.no_end_date')).'</span>';
        }

        $range = e($start?->translatedFormat('j M') ?? '…').' <span aria-hidden="true" class="text-secondary-light">→</span> '.e($end?->translatedFormat('j M Y') ?? '…');
        $effective = app(HiringCampaignStateMachine::class)->effective($campaign);
        $live = in_array($effective, [HiringCampaignStatus::Published, HiringCampaignStatus::Open], true);

        $note = '';
        if ($live && $end !== null && $end->isFuture()) {
            $soon = $end->lte(now()->addDays(7));
            $note = '<span class="block text-xs '.($soon ? 'font-semibold text-neutral-900' : 'text-secondary-medium').'">'
                .e($end->locale(app()->getLocale())->diffForHumans(['parts' => 1])).'</span>';
        }

        return '<span class="block whitespace-nowrap tabular-nums"><span class="block text-secondary-dark">'.$range.'</span>'.$note.'</span>';
    }

    private function personCell(?string $name): string
    {
        if ($name === null || $name === '') {
            return self::DASH;
        }

        $initials = collect(preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('') ?: '?';

        return '<span class="flex items-center gap-2 whitespace-nowrap">'
            .'<span aria-hidden="true" class="inline-flex size-7 shrink-0 select-none items-center justify-center rounded-full bg-neutral-100 text-xs font-semibold text-secondary-dark">'.e($initials).'</span>'
            .'<span class="text-secondary-dark"><bdi>'.e($name).'</bdi></span></span>';
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
