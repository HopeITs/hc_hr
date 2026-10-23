<?php

namespace App\Livewire\Concerns;

use Throwable;

/**
 * Turns a failed data load into a recoverable state instead of a 500.
 *
 *   $rows = $this->guardedLoad(fn () => $this->query()->get(), fallback: collect());
 *
 *   @if ($loadFailed) <x-ui.load-error /> @endif
 *
 * The exception is reported (logs/monitoring) and never reaches the UI.
 * $loadFailed is public so the view and tests can read it; it is only a
 * display flag, so a forged value can do nothing but show or hide the notice.
 */
trait HandlesLoadFailure
{
    public bool $loadFailed = false;

    /** Wired to the Retry button; the next render re-runs the guarded load. */
    public function retryLoad(): void
    {
        $this->loadFailed = false;
    }

    /**
     * Run a loader; on any Throwable report it, set $loadFailed and return $fallback.
     * A successful run clears the flag.
     */
    protected function guardedLoad(callable $loader, mixed $fallback = null): mixed
    {
        try {
            $result = $loader();
            $this->loadFailed = false;

            return $result;
        } catch (Throwable $e) {
            report($e);
            $this->loadFailed = true;

            return $fallback;
        }
    }
}
