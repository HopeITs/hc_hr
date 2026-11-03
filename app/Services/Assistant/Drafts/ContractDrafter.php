<?php

namespace App\Services\Assistant\Drafts;

use App\Livewire\Contract\ContractCreate;
use App\Livewire\Forms\ContractForm;
use App\Livewire\Forms\LeaveBalanceForm;
use App\Models\Contract;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

use function Livewire\invade;
use function Livewire\store;

/**
 * The create-contract screen (ContractCreate) driven without the page.
 *
 * Nothing here decides validity. check() runs exactly what the screen's
 * submit runs before it writes anything — applyComposerPayload(), the form's
 * validateIdentity / validateTerms / validatePay, then validateCityConsistency()
 * (Position::inCity, the employee's city, canAccessCity) — and stops there.
 * store() calls the screen's own submit(), so a contract is created with every
 * side effect the page has: the capacity check and counter in ContractObserver,
 * the encrypted salary, the activity log, leave balances, activation of a
 * volunteer awaiting a first contract, the welcome broadcast, and the
 * screen's per-token duplicate protection.
 *
 * The screen reads the user from auth(), so both run as $user.
 */
class ContractDrafter
{
    /**
     * Validate a composer payload exactly as the screen does. Writes nothing.
     *
     * @param  array<string, mixed>  $payload  the screen's own submit() payload (cityId, employeeId, ...)
     * @return array<string, mixed> the payload, with the employee's draft contract to upgrade, if any
     *
     * @throws ValidationException
     */
    public function check(User $user, array $payload): array
    {
        return $this->asUser($user, function () use ($payload): array {
            $screen = $this->screen();
            $payload = $this->withDraftUpgrade($screen, $payload);

            invade($screen)->applyComposerPayload($payload);

            $screen->form->validateIdentity();
            $screen->form->validateTerms();
            $screen->form->validatePay();

            if (! invade($screen)->validateCityConsistency()) {
                throw $this->failure($screen);
            }

            return $payload;
        });
    }

    /**
     * Create the contract through the screen's submit(). A $token already
     * used by this user returns the contract it created (the screen's own
     * duplicate protection, keyed on its submission token).
     *
     * @throws ValidationException
     */
    public function store(User $user, array $payload, string $token): Contract
    {
        return $this->asUser($user, function () use ($payload, $token): Contract {
            $screen = $this->screen();
            $screen->submissionToken = $token;

            // Looked up again, never taken from the draft: the placeholder may
            // have been upgraded or removed since.
            $payload = $this->withDraftUpgrade($screen, $payload);

            // Throws the form's ValidationException (and the observer's, for a
            // position that filled up meanwhile) as it is.
            $screen->submit($payload);

            $id = Cache::get(invade($screen)->submissionKey());
            $contract = $id ? Contract::query()->find($id) : null;

            if ($contract === null || store($screen)->get('redirect') === null) {
                // A refused city/employee/position pairing (error bag), or
                // another confirm of the same draft still holding the lock.
                throw $this->failure($screen, __('general.composer.submit-in-progress'));
            }

            return $contract;
        });
    }

    /**
     * The screen as it is when opened: defaults set, nothing tracked.
     * mount() is not run, so no task-attempt telemetry is started.
     */
    private function screen(): ContractCreate
    {
        /** @var ContractCreate $screen */
        $screen = app('livewire')->new(ContractCreate::class);

        $screen->form = new ContractForm($screen, 'form');
        $screen->balanceForm = new LeaveBalanceForm($screen, 'balanceForm');
        $screen->form->setDefaults();

        return $screen;
    }

    /**
     * What the screen does when an employee is picked: a placeholder (Draft)
     * contract of theirs is upgraded in place instead of a second row added.
     */
    private function withDraftUpgrade(ContractCreate $screen, array $payload): array
    {
        $employeeId = filter_var($payload['employeeId'] ?? null, FILTER_VALIDATE_INT);
        $draft = $employeeId ? $screen->draftContractFor($employeeId) : null;

        return [...$payload, 'draftContractId' => $draft['draftContractId'] ?? null];
    }

    private function failure(ContractCreate $screen, ?string $fallback = null): ValidationException
    {
        $errors = $screen->getErrorBag()->toArray();

        return ValidationException::withMessages($errors !== [] ? $errors : ['draft' => $fallback ?? __('assistant.draft.forbidden')]);
    }

    private function asUser(User $user, Closure $callback): mixed
    {
        $guard = auth()->guard();
        $previous = $guard->user();

        if ($previous?->is($user)) {
            return $callback();
        }

        $guard->setUser($user);

        try {
            return $callback();
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}
