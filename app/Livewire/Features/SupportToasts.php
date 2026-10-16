<?php

namespace App\Livewire\Features;

use Livewire\Component;
use Livewire\ComponentHook;

use function Livewire\store;

/**
 * Delivers the toasts a component raised during this request.
 *
 * A toast cannot simply be dispatched at the moment it is raised, because most
 * success messages are followed by a redirect (create a contract, land on the
 * contract). A dispatched event would paint on the page being left and be gone
 * a frame later. So toasts are held until dehydrate, when the outcome of the
 * request is known:
 *
 *   - no redirect: each toast is sent as a browser event, exactly as
 *     $this->dispatch('toast', ...) would have sent it;
 *   - redirect: the toasts are flashed to the session, and <x-toasts/> on the
 *     next page renders them.
 *
 * Ordering matters, and is why this is registered while the app is booting
 * (AppServiceProvider): Livewire attaches every hook it knows about in its own
 * boot, so this one lands ahead of Livewire's event hook. Its dehydrate then
 * runs first, and a toast handed to dispatch() here is sent by that event hook
 * like any other event. The redirect is already known by then: Livewire
 * records it the moment the action calls redirect().
 */
class SupportToasts extends ComponentHook
{
    public const SESSION_KEY = 'toasts';

    /**
     * @param  array{type: string, message: string, undo: array<string, mixed>|null}  $toast
     */
    public static function queue(Component $component, array $toast): void
    {
        store($component)->push('toasts', $toast);
    }

    public function dehydrate(): void
    {
        $toasts = $this->storeGet('toasts', []);

        if ($toasts === []) {
            return;
        }

        if ($this->storeGet('redirect')) {
            // Undo calls back into this component instance, which does not
            // exist on the page the redirect lands on.
            $carried = array_map(fn (array $toast) => [...$toast, 'undo' => null], $toasts);

            session()->flash(self::SESSION_KEY, [...session(self::SESSION_KEY, []), ...$carried]);

            return;
        }

        foreach ($toasts as $toast) {
            $this->component->dispatch('toast', ...$toast);
        }
    }
}
