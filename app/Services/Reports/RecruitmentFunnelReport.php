<?php

namespace App\Services\Reports;

use App\Enums\RecruitmentApplicationStatus;
use App\Enums\RecruitmentInterviewStatus;
use App\Enums\RecruitmentRequestStatus;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentHiringConversion;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentJobOffer;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentScopeResolver;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * From request to hire. Counts only what the viewer's recruitment scope already
 * lets them see (RecruitmentScopeResolver), and only aggregates: no applicant
 * is named. A stage counts applications that have *evidence* of reaching it
 * (an interview row, an offer sent, a completed conversion), not their current
 * status, because a status says where an application is, not where it has been.
 */
class RecruitmentFunnelReport
{
    public const PERIODS = [90, 365, 0];

    public function __construct(public readonly User $viewer, public readonly int $days = 365) {}

    public static function normaliseDays(int $days): int
    {
        return in_array($days, self::PERIODS, true) ? $days : 365;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $scope = app(RecruitmentScopeResolver::class);
        $days = self::normaliseDays($this->days);
        $from = $days > 0 ? now()->subDays($days)->startOfDay() : null;

        $requests = $scope->scopeRequests(RecruitmentRequest::query(), $this->viewer)
            ->whereNotNull('submitted_at')
            ->when($from, fn ($q) => $q->where('submitted_at', '>=', $from))
            ->get(['id', 'status', 'submitted_at', 'applied_at']);

        $applications = $scope->scopeApplications(RecruitmentApplication::query(), $this->viewer)
            ->when($from, fn ($q) => $q->where('submitted_at', '>=', $from))
            ->get(['id', 'vacancy_id', 'status', 'submitted_at']);

        $ids = $applications->pluck('id')->all();

        $interviews = $this->inChunks($ids, fn (array $chunk) => RecruitmentInterview::query()
            ->whereIn('application_id', $chunk)
            ->where('status', '!=', RecruitmentInterviewStatus::Cancelled)
            ->get(['application_id', 'scheduled_at']))->groupBy('application_id');

        $offers = $this->inChunks($ids, fn (array $chunk) => RecruitmentJobOffer::query()
            ->whereIn('application_id', $chunk)
            ->get(['id', 'application_id', 'status', 'sent_at', 'accepted_at', 'rejected_at']));

        $converted = $this->inChunks($offers->pluck('id')->all(), fn (array $chunk) => RecruitmentHiringConversion::query()
            ->whereIn('job_offer_id', $chunk)
            ->where('status', RecruitmentHiringConversion::STATUS_CONVERTED)
            ->get(['job_offer_id', 'converted_at']));

        $offerApplication = $offers->pluck('application_id', 'id');
        $hiredAt = $converted->mapWithKeys(fn ($c) => [$offerApplication[$c->job_offer_id] => $c->converted_at]);
        $sentOffers = $offers->whereNotNull('sent_at');
        $firstSent = $sentOffers->groupBy('application_id')->map(fn (Collection $o) => $o->min('sent_at'));
        $accepted = $offers->whereNotNull('accepted_at')->pluck('application_id')->unique();

        $total = $applications->count();
        $stages = collect([
            [__('Applications received'), $total],
            [__('Interviewed'), $interviews->count()],
            [__('Offer sent'), $firstSent->count()],
            [__('Offer accepted'), $accepted->count()],
            [__('Hired'), $hiredAt->count()],
        ])->values()->map(fn (array $s, int $i) => [
            'label' => $s[0],
            'count' => $s[1],
            'percent' => $total ? round($s[1] / $total * 100, 1) : 0.0,
        ]);
        // Conversion from the previous stage, for the line under each bar.
        $stages = $stages->map(fn (array $s, int $i) => $s + [
            'fromPrevious' => $i > 0 && $stages[$i - 1]['count'] > 0 ? round($s['count'] / $stages[$i - 1]['count'] * 100, 1) : null,
        ]);

        $byId = $applications->keyBy('id');

        return [
            'days' => $days,
            'requests' => [
                'submitted' => $requests->count(),
                'approved' => $requests->whereIn('status', [RecruitmentRequestStatus::Approved, RecruitmentRequestStatus::Applied, RecruitmentRequestStatus::FailedApplication])->count(),
                'rejected' => $requests->where('status', RecruitmentRequestStatus::Rejected)->count(),
                'cancelled' => $requests->where('status', RecruitmentRequestStatus::Cancelled)->count(),
                'inReview' => $requests->whereIn('status', [RecruitmentRequestStatus::Submitted, RecruitmentRequestStatus::UnderReview, RecruitmentRequestStatus::ChangesRequested, RecruitmentRequestStatus::Resubmitted])->count(),
                'daysToApply' => $this->averageDays($requests->whereNotNull('applied_at'), fn ($r) => [$r->submitted_at, $r->applied_at]),
            ],
            'stages' => $stages,
            'times' => [
                'toInterview' => $this->averageDays($interviews, fn (Collection $rows, $appId) => [$byId[$appId]->submitted_at ?? null, $rows->min('scheduled_at')]),
                'toOffer' => $this->averageDays($firstSent, fn ($sentAt, $appId) => [$byId[$appId]->submitted_at ?? null, $sentAt]),
                'toHire' => $this->averageDays($hiredAt, fn ($at, $appId) => [$byId[$appId]->submitted_at ?? null, $at]),
            ],
            'offerAcceptancePercent' => $firstSent->count() ? round($accepted->count() / $firstSent->count() * 100, 1) : null,
            'offersExpired' => $offers->where('status', 'expired')->count(),
            'byStatus' => $this->statuses($applications, $total),
            'byPosition' => $this->positions($applications, $interviews, $firstSent, $hiredAt),
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @param  Closure(array<int, int>): Collection  $query
     */
    private function inChunks(array $ids, Closure $query): Collection
    {
        return collect(array_chunk($ids, 1000))->flatMap(fn (array $chunk) => $query($chunk))->values();
    }

    /** Mean days between two moments, over the rows where both exist; null when there are none. */
    private function averageDays(Collection $rows, Closure $pair): ?float
    {
        $days = $rows->map(function ($row, $key) use ($pair) {
            [$from, $to] = $pair($row, $key);

            return $from && $to ? max(0, Carbon::parse($from)->floatDiffInDays(Carbon::parse($to))) : null;
        })->filter(fn ($d) => $d !== null);

        return $days->isEmpty() ? null : round($days->avg(), 1);
    }

    /** @return Collection<int, array{label: string, count: int, percent: float}> */
    private function statuses(Collection $applications, int $total): Collection
    {
        $labels = [
            RecruitmentApplicationStatus::Submitted => __('Submitted'),
            RecruitmentApplicationStatus::UnderReview => __('Under review'),
            RecruitmentApplicationStatus::Shortlisted => __('Shortlisted'),
            RecruitmentApplicationStatus::InterviewScheduled => __('Interview scheduled'),
            RecruitmentApplicationStatus::Interviewed => __('Interviewed'),
            RecruitmentApplicationStatus::Finalist => __('Finalist'),
            RecruitmentApplicationStatus::Selected => __('Selected'),
            RecruitmentApplicationStatus::OfferSent => __('Offer sent'),
            RecruitmentApplicationStatus::OfferAccepted => __('Offer accepted'),
            RecruitmentApplicationStatus::OfferRejected => __('Offer rejected'),
            RecruitmentApplicationStatus::Hired => __('Hired'),
            RecruitmentApplicationStatus::Rejected => __('Rejected'),
            RecruitmentApplicationStatus::Withdrawn => __('Withdrawn'),
            RecruitmentApplicationStatus::Archived => __('Archived'),
        ];

        return $applications->groupBy('status')
            ->map(fn (Collection $g, string $status) => [
                'label' => $labels[$status] ?? $status,
                'count' => $g->count(),
                'percent' => $total ? round($g->count() / $total * 100, 1) : 0.0,
            ])->sortByDesc('count')->values();
    }

    /** Top positions by applications: how far their applicants got. */
    private function positions(Collection $applications, Collection $interviews, Collection $firstSent, Collection $hiredAt): Collection
    {
        $vacancies = Vacancy::query()->with('position')->whereIn('id', $applications->pluck('vacancy_id')->unique())->get()->keyBy('id');

        return $applications->groupBy('vacancy_id')
            ->map(function (Collection $apps, $vacancyId) use ($vacancies, $interviews, $firstSent, $hiredAt) {
                $ids = $apps->pluck('id');

                return [
                    'label' => $vacancies[$vacancyId]?->position?->title ?: '-',
                    'applications' => $apps->count(),
                    'interviewed' => $ids->filter(fn ($id) => $interviews->has($id))->count(),
                    'offers' => $ids->filter(fn ($id) => $firstSent->has($id))->count(),
                    'hired' => $ids->filter(fn ($id) => $hiredAt->has($id))->count(),
                ];
            })
            ->groupBy('label')
            ->map(fn (Collection $rows, string $label) => [
                'label' => $label,
                'applications' => $rows->sum('applications'),
                'interviewed' => $rows->sum('interviewed'),
                'offers' => $rows->sum('offers'),
                'hired' => $rows->sum('hired'),
            ])
            ->sortByDesc('applications')->take(15)->values();
    }
}
