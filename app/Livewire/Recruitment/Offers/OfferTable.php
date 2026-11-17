<?php

namespace App\Livewire\Recruitment\Offers;

use App\Enums\RecruitmentJobOfferStatus;
use App\Models\RecruitmentJobOffer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Filters\SelectFilter;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

/**
 * Phase 2 Task 9: the job offers in the user's recruitment scope.
 *
 * No salary column, on purpose: pay figures are shown on the offer itself,
 * and only to users who may see them. Every row comes through
 * RecruitmentJobOffer::visibleTo(); filters only narrow it.
 */
class OfferTable extends LivewireTable
{
    public string $sortColumn = 'updated_at';

    public string $sortDirection = 'desc';

    protected string $model = RecruitmentJobOffer::class;

    public function boot(): void
    {
        Gate::authorize('viewAny', RecruitmentJobOffer::class);
    }

    protected function query(): Builder
    {
        Gate::authorize('viewAny', RecruitmentJobOffer::class);

        return RecruitmentJobOffer::query()
            ->visibleTo(auth()->user())
            ->with(['application.applicant', 'position', 'currentVersion']);
    }

    protected function link(Model $model): ?string
    {
        return route('recruitment.offers.show', ['offerId' => $model->getKey()]);
    }

    protected function columns(): array
    {
        return [
            Column::make(__('recruitment-applicant.name'), 'application.applicant.full_name')->sortable()->searchable(),
            Column::make(__('recruitment-applicant.campaign'), 'position.'.transColumn('title'))->searchable(),
            Column::make(__('recruitment-applicant.status'), function (RecruitmentJobOffer $offer): string {
                return '<span class="rounded-control px-2 py-0.5 text-xs font-semibold '.RecruitmentJobOfferStatus::classes($offer->status).'">'
                    .e(__('recruitment-applicant.offers.statuses.'.$offer->status)).'</span>';
            })->asHtml(),
            Column::make(__('recruitment-applicant.offers.version'), fn (RecruitmentJobOffer $offer) => 'v'.$offer->currentVersion?->version_no),
            Column::make(__('recruitment-applicant.offers.start_date'), fn (RecruitmentJobOffer $offer) => $offer->currentVersion?->start_date?->format('Y-m-d') ?? '—'),
            Column::make(__('recruitment-applicant.offers.sent_at'), 'sent_at')
                ->displayUsing(fn ($value) => $value?->format('Y-m-d') ?? '—')->sortable(),
            Column::make(__('recruitment-applicant.offers.expires_at'), 'expires_at')
                ->displayUsing(fn ($value) => $value?->format('Y-m-d') ?? '—')->sortable(),
        ];
    }

    protected function filters(): array
    {
        return [
            SelectFilter::make(__('recruitment-applicant.status'), 'status')->options(
                collect(RecruitmentJobOfferStatus::values())
                    ->mapWithKeys(fn (string $status) => [$status => __('recruitment-applicant.offers.statuses.'.$status)])
                    ->all(),
            ),
        ];
    }
}
