<?php

namespace App\Livewire\Recruitment\Approvals;

use App\Enums\RecruitmentRequestType;
use App\Enums\WorkflowStepKey;
use App\Models\City;
use App\Models\RecruitmentWorkflowInstanceStep;
use App\Services\RecruitmentApproverEligibility;
use App\Services\RecruitmentPendingApprovalQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Enums\Direction;
use RamonRietdijk\LivewireTables\Filters\SelectFilter;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

/**
 * The approver's queue, in the same table as every other list.
 *
 * Rows come only from RecruitmentPendingApprovalQuery — the current,
 * undecided step assigned to this exact user — so search, sorting and
 * filters can narrow the queue but never widen it. Oldest first by default:
 * a queue is worked from the front.
 *
 * "Waiting" is measured from when the step became this user's, not from when
 * the request started: a request that spent a week with the manager has been
 * waiting on the finance approver only since the manager signed.
 */
class PendingApprovalTable extends LivewireTable
{
    /** From this many days a wait is marked as long. */
    public const LONG_WAIT_DAYS = 3;

    /** A wait this long fills the meter. */
    private const METER_FULL_DAYS = 7;

    public string $sortColumn = 'waiting';

    public string $sortDirection = 'asc';

    protected string $model = RecruitmentWorkflowInstanceStep::class;

    public function boot(): void
    {
        abort_unless(
            app(RecruitmentApproverEligibility::class)->mayReceiveApprovals(auth()->user()),
            403,
        );
    }

    protected function query(): Builder
    {
        return app(RecruitmentPendingApprovalQuery::class)
            ->forUser(auth()->user())
            // The table owns the order; the query's own would always win.
            ->reorder()
            ->addSelect(['waiting_since' => app(RecruitmentPendingApprovalQuery::class)->waitingSince()])
            ->with(['workflowInstance.request.targetPosition.center']);
    }

    protected function link(Model $model): ?string
    {
        return route('recruitment.requests.show', ['requestId' => $model->workflowInstance->request_id]);
    }

    /** @return array<int, Column> */
    protected function columns(): array
    {
        return [
            Column::make(__('recruitment-workflow.request'), function (RecruitmentWorkflowInstanceStep $step): string {
                $request = $step->workflowInstance->request;
                $position = $request->targetPosition;

                return '<span class="block min-w-48">'
                    .'<span class="block break-words font-semibold text-neutral-900"><bdi>'.e($position?->title ?? $request->request_number).'</bdi></span>'
                    .'<span class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs text-secondary-medium">'
                    .'<span title="'.e($request->request_number).'" class="max-w-40 truncate tabular-nums"><bdi>'.e($request->request_number).'</bdi></span>'
                    .($position?->center?->name ? '<span aria-hidden="true">·</span><span>'.e($position->center->name).'</span>' : '')
                    .'</span></span>';
            }, 'request')
                ->asHtml()
                ->sortable(fn (Builder $query, Direction $direction) => $query->orderBy('pending_requests.request_number', $direction->value))
                ->searchable(fn (Builder $query, mixed $search) => is_string($search)
                    ? $query->whereLike('pending_requests.request_number', '%'.$search.'%')
                    : null),

            Column::make(__('recruitment-workflow.request_type'), fn (RecruitmentWorkflowInstanceStep $step): string => '<span class="inline-flex whitespace-nowrap rounded-control bg-neutral-100 px-2 py-0.5 text-xs font-semibold text-secondary-dark">'
                .e(RecruitmentRequestType::getLocalizedName((int) $step->workflowInstance->request->request_type)['value']).'</span>', 'type')
                ->asHtml(),

            Column::make(__('recruitment-workflow.requester'), function (RecruitmentWorkflowInstanceStep $step): string {
                $request = $step->workflowInstance->request;
                $name = $request->requester?->profile?->name ?? $request->requester?->name ?? '—';
                $city = $request->scopeCity?->name;

                $initials = collect(preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [])
                    ->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('') ?: '?';

                return '<span class="flex items-center gap-2.5 whitespace-nowrap">'
                    .'<span aria-hidden="true" class="inline-flex size-8 shrink-0 select-none items-center justify-center rounded-full bg-neutral-100 text-xs font-semibold text-secondary-dark">'.e($initials).'</span>'
                    .'<span><span class="block text-neutral-900"><bdi>'.e($name).'</bdi></span>'
                    .($city ? '<span class="block text-xs text-secondary-medium">'.e($city).'</span>' : '')
                    .'</span></span>';
            }, 'requester')
                ->asHtml()
                ->searchable(fn (Builder $query, mixed $search) => is_string($search)
                    ? $query->whereHas('workflowInstance.request.requester.profile', fn (Builder $people) => $people
                        ->where(fn (Builder $names) => $names
                            ->whereLike('first_name_en', '%'.$search.'%')
                            ->orWhereLike('last_name_en', '%'.$search.'%')
                            ->orWhereLike('first_name_ar', '%'.$search.'%')
                            ->orWhereLike('last_name_ar', '%'.$search.'%')))
                    : null),

            Column::make(__('recruitment-workflow.your_step'), function (RecruitmentWorkflowInstanceStep $step): string {
                return '<span class="inline-flex items-center gap-1.5 whitespace-nowrap text-small text-secondary-dark">'
                    .'<svg class="size-4 shrink-0 text-secondary-medium" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M12 21a9 9 0 100-18 9 9 0 000 18z"/></svg>'
                    .e(WorkflowStepKey::getLocalizedName((int) $step->workflowStep->step_key)['value']).'</span>';
            }, 'step')->asHtml(),

            Column::make(__('recruitment-workflow.waiting'), function (RecruitmentWorkflowInstanceStep $step): string {
                if ($step->waiting_since === null) {
                    return '<span class="text-secondary-light">—</span>';
                }

                $since = CarbonImmutable::parse($step->waiting_since);
                $days = (int) $since->diffInDays(now());
                $long = $days >= self::LONG_WAIT_DAYS;
                $label = $days === 0
                    ? __('recruitment-workflow.waiting_today')
                    : trans_choice('recruitment-workflow.waiting_days', $days, ['count' => $days]);

                $fill = min(100, (int) round(max($days, 0.5) / self::METER_FULL_DAYS * 100));

                return '<span class="block min-w-28">'
                    .'<span class="flex items-center gap-1.5 whitespace-nowrap '.($long ? 'font-semibold text-primary' : 'text-neutral-900').'">'.e($label).'</span>'
                    .'<span aria-hidden="true" class="mt-1 block h-1 w-24 overflow-hidden rounded-full bg-neutral-200"><span class="block h-full rounded-full '.($long ? 'bg-primary' : 'bg-neutral-500').'" style="width:'.$fill.'%"></span></span>'
                    .'<time datetime="'.e($since->toIso8601String()).'" class="mt-1 block text-xs tabular-nums text-secondary-medium">'.e(__('recruitment-workflow.since', ['date' => $since->translatedFormat('j M, H:i')])).'</time>'
                    .'</span>';
            }, 'waiting')
                ->asHtml()
                ->sortable(fn (Builder $query, Direction $direction) => $query->orderBy('waiting_since', $direction->value)),

            Column::make(__('recruitment-workflow.review'), fn (RecruitmentWorkflowInstanceStep $step): string => '<a href="'.e($this->link($step)).'" x-on:click.stop'
                .' aria-label="'.e(__('recruitment-workflow.review_request', ['number' => $step->workflowInstance->request->request_number])).'"'
                .' class="inline-flex min-h-11 sm:min-h-10 items-center gap-1.5 whitespace-nowrap rounded-control border border-neutral-900 bg-neutral-900 px-3 text-sm font-semibold text-white shadow-e1 transition duration-150 hover:opacity-90 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">'
                .e(__('recruitment-workflow.review')).'</a>', 'action')
                ->asHtml(),
        ];
    }

    /** @return array<int, SelectFilter> */
    protected function filters(): array
    {
        return [
            SelectFilter::make(__('recruitment-workflow.request_type'), fn (Builder $query, $value) => $this->narrow($query, 'pending_requests.request_type', $value), 'type')
                ->options(collect(RecruitmentRequestType::getValues())
                    ->mapWithKeys(fn ($type): array => [$type => RecruitmentRequestType::getLocalizedName((int) $type)['value']])
                    ->all()),

            SelectFilter::make(__('recruitment-workflow.your_step'), fn (Builder $query, $value) => $this->narrow($query, 'pending_definitions.step_key', $value), 'step')
                ->options(collect(WorkflowStepKey::getValues())
                    ->mapWithKeys(fn ($key): array => [$key => WorkflowStepKey::getLocalizedName((int) $key)['value']])
                    ->all()),

            // Only the governorates something is actually waiting in.
            SelectFilter::make(__('recruitment-workflow.governorate'), fn (Builder $query, $value) => $this->narrow($query, 'pending_requests.scope_city_id', $value), 'city')
                ->options(City::query()
                    ->whereIn('id', app(RecruitmentPendingApprovalQuery::class)->forUser(auth()->user())
                        ->reorder()->setEagerLoads([])->select('pending_requests.scope_city_id'))
                    ->get()
                    ->mapWithKeys(fn (City $city): array => [$city->id => $city->name])
                    ->all()),
        ];
    }

    /** An unset filter arrives as null or an empty value and must not narrow. */
    private function narrow(Builder $query, string $column, mixed $value): void
    {
        $values = array_values(array_filter((array) $value, fn ($item): bool => $item !== null && $item !== ''));

        if ($values !== []) {
            $query->whereIn($column, $values);
        }
    }
}
