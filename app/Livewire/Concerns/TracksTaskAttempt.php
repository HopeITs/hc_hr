<?php

namespace App\Livewire\Concerns;

use App\Services\TaskTelemetry;
use Livewire\Attributes\Locked;

/**
 * X2-I: ties one X2 task attempt to the Livewire component it happens in.
 *
 * The id is #[Locked], so the browser can read it but not change it. Starting
 * and finishing are protected. The only public method is the dehydrate hook,
 * which Livewire refuses to call from the browser (dehydrate* is a protected
 * lifecycle name), so the trait adds no new action.
 */
trait TracksTaskAttempt
{
    #[Locked]
    public ?string $taskAttemptId = null;

    /** After every request: an open attempt that is showing validation errors is flagged once. */
    public function dehydrateTracksTaskAttempt(): void
    {
        if ($this->taskAttemptId !== null && $this->getErrorBag()->isNotEmpty()) {
            app(TaskTelemetry::class)->flagValidationError($this->taskAttemptId);
        }
    }

    /**
     * Opening the task again while an attempt is still open means the earlier
     * one was given up: it is closed as cancelled, never left to double count.
     */
    protected function startTaskAttempt(string $task): void
    {
        $telemetry = app(TaskTelemetry::class);

        $telemetry->finish($this->taskAttemptId, 'cancelled');
        $this->taskAttemptId = $telemetry->start($task);
    }

    protected function finishTaskAttempt(string $outcome = 'completed'): void
    {
        app(TaskTelemetry::class)->finish($this->taskAttemptId, $outcome);
        $this->taskAttemptId = null;
    }
}
