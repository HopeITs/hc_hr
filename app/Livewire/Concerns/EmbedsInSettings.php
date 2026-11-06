<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Locked;

/**
 * A Project Basics page that can also sit as a tab of Basic Settings
 * (App\Livewire\Basics\BasicSettings). Embedded, the module keeps its table,
 * buttons and dialogs but leaves out the page title and breadcrumbs, which the
 * Basic Settings page already shows. Cosmetic only, hence nothing to authorize.
 */
trait EmbedsInSettings
{
    #[Locked]
    public bool $inSettings = false;
}
