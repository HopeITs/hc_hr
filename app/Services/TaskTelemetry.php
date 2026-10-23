<?php

namespace App\Services;

use App\Models\TaskAttempt;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Records attempts at the four X2 tasks (X2-I), and nothing else.
 *
 * What is stored is fixed by DEC-10: a random attempt id, the task, a coarse
 * role ("hr", "manager", ...), the interface language, times and the outcome.
 * No user, employee, city, record id, form value or reason ever reaches this
 * class, so it cannot leak into the table.
 *
 * Every method is a no-op while config('telemetry.enabled') is false, and
 * telemetry never breaks the task it measures: a failed write is swallowed.
 */
class TaskTelemetry
{
    public const LOOKUP_KEY = 'task_telemetry.employee_lookup';

    /** Start an attempt; returns its id, or null when telemetry is off. */
    public function start(string $task, ?User $user = null): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        if (! in_array($task, config('telemetry.tasks', []), true)) {
            throw new InvalidArgumentException("Unknown telemetry task [{$task}].");
        }

        $id = (string) Str::uuid();

        $this->quietly(fn () => TaskAttempt::query()->create([
            'attempt_uuid' => $id,
            'task' => $task,
            'role_context' => $this->roleContext($user ?? auth()->user()),
            'locale' => app()->getLocale() === 'ar' ? 'ar' : 'en',
            'started_at' => now(),
        ]));

        return $id;
    }

    /**
     * End an attempt. Only the first outcome counts: a second call (a retried
     * request, a double click) changes nothing, so an attempt is never counted
     * twice.
     */
    public function finish(?string $attemptId, string $outcome): void
    {
        if ($attemptId === null || ! $this->enabled()) {
            return;
        }

        if (! in_array($outcome, config('telemetry.outcomes', []), true)) {
            throw new InvalidArgumentException("Unknown telemetry outcome [{$outcome}].");
        }

        $this->quietly(function () use ($attemptId, $outcome) {
            $attempt = TaskAttempt::query()->where('attempt_uuid', $attemptId)->first(['started_at']);

            if (! $attempt) {
                return;
            }

            $now = now();

            // Conditional update: the "outcome is null" check and the write are
            // one statement, so two simultaneous finishes cannot both land.
            TaskAttempt::query()
                ->where('attempt_uuid', $attemptId)
                ->whereNull('outcome')
                ->update([
                    'outcome' => $outcome,
                    'finished_at' => $now,
                    'duration_ms' => max(0, (int) $attempt->started_at->diffInMilliseconds($now)),
                ]);
        });
    }

    /** Mark that the attempt met at least one validation error (a yes/no flag, never a count). */
    public function flagValidationError(?string $attemptId): void
    {
        if ($attemptId === null || ! $this->enabled()) {
            return;
        }

        $this->quietly(fn () => TaskAttempt::query()
            ->where('attempt_uuid', $attemptId)
            ->whereNull('outcome')
            ->where('had_validation_error', false)
            ->update(['had_validation_error' => true]));
    }

    /**
     * Task B spans two pages: a search on the employee list, then opening a
     * profile. The open attempt's id is kept in the session (never in the
     * table) between them. A search while one is already open continues it.
     */
    public function startLookup(): void
    {
        if (! $this->enabled() || $this->openLookupId() !== null) {
            return;
        }

        $id = $this->start('employee_lookup');

        if ($id !== null) {
            session()->put(self::LOOKUP_KEY, ['id' => $id, 'at' => now()->getTimestamp()]);
        }
    }

    /** Opening a profile completes the open lookup, if there is one. */
    public function completeLookup(): void
    {
        $id = $this->openLookupId();
        session()->forget(self::LOOKUP_KEY);

        $this->finish($id, 'completed');
    }

    public function enabled(): bool
    {
        return (bool) config('telemetry.enabled', false);
    }

    /** The active role, coarsened so it never names a city: HR-ALP and HR-SYR are both "hr". */
    public function roleContext(?User $user): string
    {
        if ($user === null) {
            return 'guest';
        }

        $role = $user->myRole();

        return match (true) {
            $role === null => 'none',
            str_starts_with($role, 'HR-') => 'hr',
            in_array($role, ['employee', 'manager', 'general-manager'], true) => $role,
            $role === 'super-admin' => 'admin',
            default => 'other',
        };
    }

    /**
     * The session's lookup, unless it is older than the abandonment window:
     * that one is left unfinished (the report counts it as abandoned) and a
     * new search starts a new attempt.
     */
    private function openLookupId(): ?string
    {
        $open = session(self::LOOKUP_KEY);

        if (! is_array($open) || ! is_string($open['id'] ?? null)) {
            return null;
        }

        $expired = now()->getTimestamp() - (int) ($open['at'] ?? 0) > 60 * (int) config('telemetry.abandon_after_minutes', 30);

        return $expired ? null : $open['id'];
    }

    private function quietly(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
