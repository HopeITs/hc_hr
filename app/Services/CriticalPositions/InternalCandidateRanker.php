<?php

namespace App\Services\CriticalPositions;

use App\Contracts\CandidateSource;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Ranks active volunteers for a critical position with deterministic,
 * explainable rules (no AI, nothing leaves the system):
 *
 *   job level    25  same grade 25, one grade apart 15, two 5
 *   title        15  overlap between the position they hold and the target's title
 *   background   25  share of the target's qualification words found in their
 *                    education, courses and experience
 *   service      15  3 points per year in the organization, capped at 5 years
 *   department   10  same department as the target
 *   city         10  same city as the target (otherwise a transfer is needed)
 *
 * Left out: anyone already holding the target, anyone holding another critical
 * position, anyone outside the viewer's city scope. The latest evaluation is not a
 * factor yet: evaluations carry no rating (see config/critical-positions.php).
 */
final class InternalCandidateRanker implements CandidateSource
{
    public function suggest(Position $position, User $viewer, int $limit): array
    {
        $position->loadMissing(['jobLevel', 'city', 'center']);
        $cityIds = $viewer->activeCityIds();
        $targetCity = $position->city_id ?? $position->center?->city_id;
        $keywords = $this->words((string) $position->qualifications);
        $titleWords = $this->words($position->title_en.' '.$position->title_ar);

        $busyEmployeeIds = Contract::query()->activeNow()
            ->whereHas('position', fn ($q) => $q->where('is_critical', true)->orWhereKey($position->getKey()))
            ->pluck('employee_id')
            ->all();

        $contracts = Contract::query()
            ->activeNow()
            ->whereNotIn('employee_id', $busyEmployeeIds)
            ->whereHas('employee', fn ($q) => $q->where('is_active', true))
            ->whereHas('position', fn ($q) => $cityIds === null ? $q : $q->inCity($cityIds))
            ->with(['position.jobLevel', 'position.city', 'position.center', 'employee.educations', 'employee.courses', 'employee.experiences'])
            ->get()
            ->unique('employee_id');

        $firstStarts = Contract::query()
            ->whereIn('employee_id', $contracts->pluck('employee_id'))
            ->selectRaw('employee_id, MIN(start_date) as first_start')
            ->groupBy('employee_id')
            ->pluck('first_start', 'employee_id');

        $minimum = (int) config('critical-positions.suggestions.minimum_score', 20);

        return $contracts
            ->map(fn (Contract $contract) => $this->score($contract, $position, $targetCity, $keywords, $titleWords, $firstStarts[$contract->employee_id] ?? null))
            ->filter(fn (CandidateSuggestion $s) => $s->score >= $minimum)
            ->sortByDesc(fn (CandidateSuggestion $s) => $s->score)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $titleWords
     */
    private function score(Contract $contract, Position $target, ?int $targetCity, array $keywords, array $titleWords, ?string $firstStart): CandidateSuggestion
    {
        /** @var Employee $employee */
        $employee = $contract->employee;
        $held = $contract->position;
        $score = 0;
        $reasons = [];

        $targetGrade = $target->jobLevel?->grade;
        $heldGrade = $held->jobLevel?->grade;

        if ($targetGrade !== null && $heldGrade !== null) {
            $points = [0 => 25, 1 => 15, 2 => 5][abs($targetGrade - $heldGrade)] ?? 0;

            if ($points > 0) {
                $score += $points;
                $reasons[] = ['key' => 'critical-positions.reasons.level', 'params' => ['level' => $held->jobLevel->name, 'points' => $points]];
            }
        }

        $shared = array_values(array_intersect($titleWords, $this->words($held->title_en.' '.$held->title_ar)));

        if ($shared !== []) {
            $points = count($shared) >= 2 ? 15 : 8;
            $score += $points;
            $reasons[] = ['key' => 'critical-positions.reasons.title', 'params' => ['title' => $held->title, 'points' => $points]];
        }

        if ($keywords !== []) {
            $background = $this->words(
                $employee->educations->map(fn ($e) => $e->name.' '.$e->specialization)->implode(' ').' '
                .$employee->courses->pluck('title')->implode(' ').' '
                .$employee->experiences->pluck('job_title')->implode(' '),
            );
            $matched = array_values(array_intersect($keywords, $background));
            $points = (int) round(25 * count($matched) / count($keywords));

            if ($points > 0) {
                $score += $points;
                $reasons[] = ['key' => 'critical-positions.reasons.background', 'params' => ['matched' => implode(', ', array_slice($matched, 0, 5)), 'points' => $points]];
            }
        }

        if ($firstStart !== null) {
            $years = (int) floor(CarbonImmutable::parse($firstStart)->diffInYears(now()));
            $points = min(5, $years) * 3;

            if ($points > 0) {
                $score += $points;
                $reasons[] = ['key' => 'critical-positions.reasons.service', 'params' => ['years' => $years, 'points' => $points]];
            }
        }

        if ($target->department_id !== null && $held->department_id === $target->department_id) {
            $score += 10;
            $reasons[] = ['key' => 'critical-positions.reasons.department', 'params' => ['points' => 10]];
        }

        $heldCity = $held->city_id ?? $held->center?->city_id;

        if ($targetCity !== null && $heldCity === $targetCity) {
            $score += 10;
            $reasons[] = ['key' => 'critical-positions.reasons.same-city', 'params' => ['points' => 10]];
        } elseif ($targetCity !== null && $heldCity !== null) {
            $reasons[] = ['key' => 'critical-positions.reasons.transfer', 'params' => []];
        }

        return new CandidateSuggestion(
            source: CandidateSuggestion::INTERNAL,
            id: $employee->id,
            name: $employee->nameForLocale(app()->getLocale()),
            score: $score,
            reasons: $reasons,
            url: route('employees.show', $employee->username),
            detail: $held->title,
        );
    }

    /** @return list<string> lower-cased words of three letters or more, without filler */
    private function words(string $text): array
    {
        $stop = ['the', 'and', 'for', 'with', 'من', 'في', 'على', 'الى', 'إلى', 'عن', 'مع', 'أو', 'او'];
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            fn (string $w) => mb_strlen($w) >= 3 && ! in_array($w, $stop, true),
        )));
    }
}
