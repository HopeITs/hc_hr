<?php

namespace App\Livewire\Concerns;

use App\Support\SafeReturn;
use Livewire\Attributes\Locked;

/**
 * The page a create screen was opened from (?from=…, set by the header's New
 * button, C2), checked by SafeReturn on the page request and kept for the
 * life of the component. Locked: the browser cannot point it elsewhere later.
 * Null when there is no valid origin; the screen then keeps its usual
 * Cancel destination.
 */
trait RemembersReturnTo
{
    #[Locked]
    public ?string $returnTo = null;

    public function mountRemembersReturnTo(): void
    {
        $user = auth()->user();

        $this->returnTo = $user ? SafeReturn::resolve(request()->query('from'), $user) : null;
    }
}
