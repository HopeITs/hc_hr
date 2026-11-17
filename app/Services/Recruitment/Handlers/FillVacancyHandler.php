<?php

namespace App\Services\Recruitment\Handlers;

use App\Enums\RecruitmentRequestType;
use App\Models\Position;
use App\Models\RecruitmentRequest;
use App\Models\Vacancy;
use Illuminate\Validation\ValidationException;

/**
 * Hiring onto a seat that the establishment already pays for.
 *
 * This is the only type that changes no counter at all: `max_emp` is whatever
 * it was, because the post was approved long ago and nobody is being added to
 * the organisation chart. All that happens is that a seat becomes open for
 * applications and the request is tied to it.
 */
class FillVacancyHandler implements AppliesRecruitmentRequest
{
    use WritesVacancies;

    public function type(): int
    {
        return RecruitmentRequestType::FillVacancy;
    }

    public function apply(RecruitmentRequest $request, array $snapshot): array
    {
        $position = Position::query()
            ->whereKey($request->target_position_id)
            ->lockForUpdate()
            ->firstOrFail();

        // The real condition is a paid-for seat standing empty, not the
        // existence of a vacancy row (D54). A row may never have been made,
        // because nothing created vacancies before this service existed.
        if ((int) $position->exist_emp >= (int) $position->max_emp) {
            throw ValidationException::withMessages([
                'request' => __('recruitment.no_headcount_room'),
            ]);
        }

        $vacancy = $this->chosenVacancy($request, $snapshot);
        $events = [];

        if ($vacancy === null) {
            $vacancy = $this->openVacancy(
                $request,
                (int) $position->getKey(),
                max(1, (int) ($snapshot['volunteers_required'] ?? 1)),
            );
            $events[] = $this->vacancyCreated($vacancy);
        }

        // Tie the request to the seat it will be hired against, so the
        // campaign built later starts from the request rather than guessing.
        $request->forceFill(['target_vacancy_id' => $vacancy->getKey()])->save();

        return $events;
    }

    /**
     * The seat the requester picked, or the single obvious one, or none.
     *
     * A named seat is re-checked under lock rather than trusted from the
     * snapshot: it may have filled or closed during the approvals, and RV-6
     * only ran at the final approval, which is not the same moment as this.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function chosenVacancy(RecruitmentRequest $request, array $snapshot): ?Vacancy
    {
        $named = $snapshot['vacancy_id'] ?? $request->target_vacancy_id;

        if ($named !== null) {
            $vacancy = Vacancy::query()->available()
                ->whereKey($named)
                ->where('position_id', $request->target_position_id)
                ->lockForUpdate()
                ->first();

            if ($vacancy === null) {
                throw ValidationException::withMessages([
                    'request' => __('recruitment.vacancy_not_available'),
                ]);
            }

            return $vacancy;
        }

        $available = Vacancy::query()->available()
            ->where('position_id', $request->target_position_id)
            ->lockForUpdate()
            ->get();

        // More than one and nobody chose: opening a fresh seat beside them
        // would add a seat the establishment never approved, so refuse and
        // let a human say which one.
        if ($available->count() > 1) {
            throw ValidationException::withMessages([
                'request' => __('recruitment.multiple_vacancies_available'),
            ]);
        }

        return $available->first();
    }
}
