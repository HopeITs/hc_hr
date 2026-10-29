<?php

namespace App\Support;

use App\Livewire\Contract\ContractCreate;
use App\Livewire\Contract\ContractRenewModal;
use App\Livewire\Employee\Leave\AllLeaveRequestModule;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;

/**
 * X1-I: which writes can answer "was operation X applied?".
 *
 * When such a write times out at network level the browser cannot know if it
 * ran. For the actions listed here resources/js/feedback.js shows the honest
 * "result unknown" state and its button calls the component's
 * `reconcileOperation()`: a lookup by the operation key (the submission token
 * or the request's own state), never a second execution. Every other action
 * keeps the generic "Try again".
 *
 * A component listed here MUST define `reconcileOperation()`.
 */
final class UnknownOutcome
{
    /** @var array<class-string, list<string>> */
    public const WRITES = [
        MyLeaveRequestModule::class => ['processSave'],
        AllLeaveRequestModule::class => ['submitDecision'],
        ContractCreate::class => ['submit'],
        ContractRenewModal::class => ['renewAsIs'],
    ];

    /**
     * Livewire component name => action names, as the browser sees them.
     *
     * @return array<string, list<string>>
     */
    public static function registry(): array
    {
        $factory = app('livewire.factory');
        $registry = [];

        foreach (self::WRITES as $class => $actions) {
            $registry[$factory->resolveComponentName($class)] = $actions;
        }

        return $registry;
    }
}
