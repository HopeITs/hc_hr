<?php

namespace App\Traits;

use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;

trait WithAlerts
{
    public function successToast($message = 'general.success-message', $position = 'top-start')
    {

        LivewireAlert::title(__($message))
            ->success()
            ->toast()
            ->position($position)
            ->show();

    }

    /**
     * Neutral counterpart to successToast(), for outcomes that are not
     * failures but did not change anything either — a save on an untouched
     * form should say so rather than claim an operation completed.
     */
    public function infoToast($message = 'general.no-changes-message', $position = 'top-start')
    {
        LivewireAlert::title(__($message))
            ->info()
            ->toast()
            ->position($position)
            ->show();
    }

    /** Display a failure in the same unobtrusive alert style as a success. */
    public function errorToast($message, $position = 'top-start')
    {
        LivewireAlert::title(__($message))
            ->error()
            ->toast()
            ->position($position)
            ->show();
    }
}
