<?php

namespace App\Traits;

use App\Livewire\Concerns\WithFeedback;

/**
 * Keeps recruitment's existing toast calls on the shared application feedback UI.
 */
trait WithAlerts
{
    use WithFeedback;

    public function successToast(string $message = 'general.success-message', string $position = 'top-start'): void
    {
        $this->toast(__($message));
    }

    public function infoToast(string $message = 'general.no-changes-message', string $position = 'top-start'): void
    {
        $this->toast(__($message), 'info');
    }

    public function errorToast(string $message, string $position = 'top-start'): void
    {
        $this->toast(__($message), 'error');
    }
}
