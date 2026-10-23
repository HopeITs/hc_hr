<?php

namespace App\Support;

use App\Models\TaskAttempt;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Aggregates X2 task attempts (X2-I) without double counting:
 *
 *   attempts = completed + cancelled + abandoned
 *
 * An attempt with no outcome counts as abandoned once it is older than
 * config('telemetry.abandon_after_minutes'); a younger one is still in
 * progress and is left out of every denominator. Each task gets one row per
 * role and language plus an "all" row; a row with fewer attempts than
 * config('telemetry.min_cell') shows "<N" instead of numbers.
 */
class TaskAttemptSummary
{
    /** @return list<array<string, mixed>> */
    public function summarise(CarbonInterface $from, CarbonInterface $to): array
    {
        $abandonBefore = now()->subMinutes((int) config('telemetry.abandon_after_minutes', 30));

        $attempts = TaskAttempt::query()
            ->whereBetween('started_at', [$from, $to])
            ->get()
            ->filter(fn (TaskAttempt $a) => $a->outcome !== null || $a->started_at->lt($abandonBefore))
            ->map(fn (TaskAttempt $a) => [
                'task' => $a->task,
                'role_context' => $a->role_context,
                'locale' => $a->locale,
                'result' => $a->outcome ?? 'abandoned',
                'duration_ms' => $a->duration_ms,
                'had_validation_error' => $a->had_validation_error,
            ]);

        $rows = [];

        foreach (config('telemetry.tasks', []) as $task) {
            $forTask = $attempts->where('task', $task);

            $rows[] = $this->row($task, 'all', 'all', $forTask);

            foreach ($forTask->groupBy(fn ($a) => $a['role_context'].'|'.$a['locale'])->sortKeys() as $key => $group) {
                [$role, $locale] = explode('|', $key);
                $rows[] = $this->row($task, $role, $locale, $group);
            }
        }

        return $rows;
    }

    /** @param Collection<int, array<string, mixed>> $group */
    private function row(string $task, string $role, string $locale, Collection $group): array
    {
        $n = $group->count();
        $completed = $group->where('result', 'completed');
        $cancelled = $group->where('result', 'cancelled')->count();
        $abandoned = $group->where('result', 'abandoned')->count();
        $withError = $group->where('had_validation_error', true)->count();
        $min = (int) config('telemetry.min_cell', 5);
        $hidden = $n > 0 && $n < $min;

        $median = $completed->pluck('duration_ms')->filter(fn ($ms) => $ms !== null)->median();

        $show = fn (int|string $value) => $hidden ? "<{$min}" : $value;
        $rate = fn (int $part) => $n === 0 ? '—' : "{$part}/{$n} (".round(100 * $part / $n).'%)';

        return [
            'task' => $task,
            'role_context' => $role,
            'locale' => $locale,
            'suppressed' => $hidden,
            // Raw values for checks; the formatted ones below are what people see.
            'n' => $n,
            'n_completed' => $completed->count(),
            'n_cancelled' => $cancelled,
            'n_abandoned' => $abandoned,
            'attempts' => $show($n),
            'completed' => $show($completed->count()),
            'cancelled' => $show($cancelled),
            'abandoned' => $show($abandoned),
            'completion_rate' => $show($rate($completed->count())),
            'validation_error_rate' => $show($rate($withError)),
            'median_duration' => $hidden || $median === null ? ($hidden ? "<{$min}" : '—') : round($median / 1000, 1).' s',
        ];
    }
}
