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
                $detail = collect([$position?->title, $position?->center?->name])->filter()->implode(' · ');

                return '<span class="block font-semibold text-neutral-900">'.e($request->request_number).'</span>'
                    .($detail !== '' ? '<span class="block text-xs text-secondary-medium">'.e($detail).'</span>' : '');
            }, 'request')
                ->asHtml()
                ->sortable(fn (Builder $query, Direction $direction) => $query->orderBy('pending_requests.request_number', $direction->value))
                ->searchable(fn (Builder $query, mixed $search) => is_string($search)
                    ? $query->whereLike('pending_requests.request_number', '%'.$search.'%')
                    : null),

            Column::make(__('recruitment-workflow.request_type'), fn (RecruitmentWorkflowInstanceStep $step): string => RecruitmentRequestType::getLocalizedName((int) $step->workflowInstance->request->request_type)['value'], 'type'),

            Column::make(__('recruitment-workflow.requester'), function (RecruitmentWorkflowInstanceStep $step): string {
                $request = $step->workflowInstance->request;
                $name = $request->requester?->profile?->name ?? $request->requester?->name ?? '—';
                $city = $request->scopeCity?->name;

                return '<span class="block text-neutral-900">'.e($name).'</span>'
                    .($city ? '<span class="block text-xs text-secondary-medium">'.e($city).'</span>' : '');
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
                return '<span class="inline-block whitespace-nowrap rounded-control border border-primary px-2 py-0.5 text-xs font-semibold text-primary">'
                    .e(WorkflowStepKey::getLocalizedName((int) $step->workflowStep->step_key)['value']).'</span>';
            }, 'step')->asHtml(),

            Column::make(__('recruitment-workflow.waiting'), function (RecruitmentWorkflowInstanceStep $step): string {
                if ($step->waiting_since === null) {
                    return '—';
                }

                $since = CarbonImmutable::parse($step->waiting_since);
                $days = (int) $since->diffInDays(now());
                $long = $days >= self::LONG_WAIT_DAYS;
                $label = $days === 0
                    ? __('recruitment-workflow.waiting_today')
                    : trans_choice('recruitment-workflow.waiting_days', $days, ['count' => $days]);

                return '<span class="flex items-center gap-1.5 whitespace-nowrap '.($long ? 'font-semibold text-primary' : 'text-neutral-900').'">'
                    .($long ? '<span class="size-1.5 rounded-full bg-primary" aria-hidden="true"></span>' : '')
                    .e($label).'</span>'
                    .'<span class="block text-xs text-secondary-medium">'.e($since->format('Y-m-d H:i')).'</span>';
            }, 'waiting')
                ->asHtml()
                ->sortable(fn (Builder $query, Direction $direction) => $query->orderBy('waiting_since', $direction->value)),
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
