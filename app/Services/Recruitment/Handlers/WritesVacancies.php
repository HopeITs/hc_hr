<?php

namespace App\Services\Recruitment\Handlers;

use App\Enums\RecruitmentEventType;
use App\Models\RecruitmentRequest;
use App\Models\Vacancy;
use Illuminate\Support\Collection;

/**
 * Opening and closing seats, in one place.
 *
 * A request produces at most one vacancy: `created_from_request_id` is unique,
 * and `needed_count` is how the schema carries several seats on one row. So
 * widening a post by three opens one vacancy needing three people, not three
 * vacancies — and narrowing it again takes seats off that count rather than
 * deleting rows.
 *
 * Four of the six handlers touch vacancies, and the status column is a plain
 * string with nothing behind it. Spreading `status = 'closed'` across four
 * files is how one of them ends up writing `'close'` and quietly dropping out
 * of every query that filters on the other spelling.
 */
trait WritesVacancies
{
    /**
     * Open the request's vacancy, or return the one it already opened.
     *
     * `firstOrCreate` rather than `create` because applying can be retried
     * after a failure: the unique key means a second attempt would otherwise
     * fail on the row the first attempt already wrote.
     */
    protected function openVacancy(RecruitmentRequest $request, int $positionId, int $needed = 1): Vacancy
    {
        return Vacancy::query()->firstOrCreate(
            ['created_from_request_id' => $request->getKey()],
            [
                'position_id' => $positionId,
                'status' => Vacancy::OPEN,
                'needed_count' => max(1, $needed),
                'filled_count' => 0,
                'open_at' => now(),
            ],
        );
    }

    /** Close one vacancy outright, saying why. One already closed is left alone. */
    protected function closeVacancy(Vacancy $vacancy, string $reason): bool
    {
        if ($vacancy->closed_at !== null || $vacancy->status === Vacancy::CLOSED) {
            return false;
        }

        $vacancy->forceFill([
            'status' => Vacancy::CLOSED,
            'closed_at' => now(),
            'close_reason' => $reason,
        ])->save();

        return true;
    }

    /**
     * Take `$seats` unfilled seats off the position, and say what that cost.
     *
     * Emptiest first, newest first: a vacancy nobody has been hired against
     * goes before one that is part filled, and among equals the newest goes
     * first, so hiring already underway is the last thing disturbed. A vacancy
     * reduced to the people already in it closes if that is nobody, and
     * otherwise stays open at the smaller count.
     *
     * @return array<int, array{event_type: int, meta: array<string, mixed>}>
     */
    protected function reduceOpenSeats(int $positionId, int $seats, string $reason): array
    {
        $events = [];

        foreach ($this->openVacanciesForClosing($positionId) as $vacancy) {
            if ($seats <= 0) {
                break;
            }

            $unfilled = max(0, (int) $vacancy->needed_count - (int) $vacancy->filled_count);

            if ($unfilled === 0) {
                continue;
            }

            $taken = min($seats, $unfilled);
            $seats -= $taken;
            $remaining = (int) $vacancy->needed_count - $taken;

            if ($remaining <= 0) {
                if ($this->closeVacancy($vacancy, $reason)) {
                    $events[] = $this->vacancyClosed($vacancy, $reason);
                }

                continue;
            }

            $vacancy->forceFill(['needed_count' => $remaining])->save();

            $events[] = [
                'event_type' => RecruitmentEventType::VacancyClosed,
                'meta' => [
                    'vacancy_id' => $vacancy->getKey(),
                    'position_id' => $positionId,
                    'seats_removed' => $taken,
                    'needed_count' => $remaining,
                    'reason' => $reason,
                ],
            ];
        }

        return $events;
    }

    /**
     * The position's open vacancies, emptiest first and newest first.
     *
     * @return Collection<int, Vacancy>
     */
    protected function openVacanciesForClosing(int $positionId): Collection
    {
        return Vacancy::query()
            ->where('position_id', $positionId)
            ->where('status', Vacancy::OPEN)
            ->whereNull('closed_at')
            ->lockForUpdate()
            ->orderBy('filled_count')
            ->orderByDesc('id')
            ->get();
    }

    /** @return array{event_type: int, meta: array<string, mixed>} */
    protected function vacancyCreated(Vacancy $vacancy): array
    {
        return [
            'event_type' => RecruitmentEventType::VacancyCreated,
            'meta' => [
                'vacancy_id' => $vacancy->getKey(),
                'position_id' => (int) $vacancy->position_id,
                'needed_count' => (int) $vacancy->needed_count,
            ],
        ];
    }

    /** @return array{event_type: int, meta: array<string, mixed>} */
    protected function vacancyClosed(Vacancy $vacancy, string $reason): array
    {
        return [
            'event_type' => RecruitmentEventType::VacancyClosed,
            'meta' => [
                'vacancy_id' => $vacancy->getKey(),
                'position_id' => (int) $vacancy->position_id,
                'reason' => $reason,
            ],
        ];
    }
}
