<?php

namespace App\Livewire\Concerns;

use App\Livewire\Features\SupportToasts;
use InvalidArgumentException;

/**
 * The one way a Livewire component reports an outcome or asks before acting.
 *
 * Both render through components mounted once in the app layout — <x-toasts/>
 * and <x-confirm-dialog/> — so every page looks and announces the same way.
 *
 * Both methods are protected on purpose: every public component method can be
 * called from the browser, and neither of these should be.
 */
trait WithFeedback
{
    /**
     * Show a toast. Raised before a redirect, it is shown on the next page.
     *
     * @param  string|null  $undo  public method on this component that reverses the action;
     *                             adds an "Undo" button (dropped if the request redirects)
     */
    protected function toast(
        ?string $message = null,
        string $type = 'success',
        ?string $undo = null,
        mixed $undoPayload = null,
    ): void {
        if (! in_array($type, ['success', 'error', 'info'], true)) {
            throw new InvalidArgumentException("Unknown toast type [{$type}].");
        }

        SupportToasts::queue($this, [
            'type' => $type,
            'message' => $message ?? __('general.success-message'),
            'undo' => $undo === null ? null : [
                'component' => $this->getId(),
                'method' => $undo,
                'payload' => $undoPayload,
            ],
        ]);
    }

    /**
     * Ask before running $then. On confirm, $then is called on this component
     * with $payload as its single argument (no argument when $payload is null).
     *
     * The defaults are the delete wording, since that is what most callers ask.
     * Pass danger: false for actions that are not destructive.
     *
     * $html replaces $message for confirmations that need markup. It is
     * inserted as HTML, so it must come from a Blade view (which escapes),
     * never from raw input.
     *
     * $cancelLabel renames the dismiss button (default Cancel); the question
     * "Discard your changes?" needs it to read "Keep editing".
     */
    protected function confirm(
        string $then,
        mixed $payload = null,
        ?string $title = null,
        ?string $message = null,
        ?string $confirmLabel = null,
        bool $danger = true,
        ?string $html = null,
        ?string $cancelLabel = null,
    ): void {
        // `componentId`, not `component`: Livewire reads a `component` param as
        // "deliver this event to that component" and would never reach the window.
        $this->dispatch('confirm-dialog',
            componentId: $this->getId(),
            method: $then,
            payload: $payload,
            title: $title ?? __('general.delete-item'),
            message: $message ?? ($html === null ? __('general.confirm-delete-item') : null),
            html: $html,
            confirmLabel: $confirmLabel ?? __('general.delete'),
            danger: $danger,
            cancelLabel: $cancelLabel,
        );
    }
}
