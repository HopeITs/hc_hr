<?php

namespace App\Livewire\Contract;

use App\Enums\ContractType as ContractTypeEnum;
use App\Livewire\Concerns\RemembersReturnTo;
use App\Livewire\Concerns\TracksTaskAttempt;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Contract\Concerns\DrivesContractComposer;
use App\Livewire\Contract\Concerns\ResolvesContractLocationOptions;
use App\Livewire\Forms\ContractForm;
use App\Livewire\Forms\LeaveBalanceForm;
use App\Models\Center;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\NewEmployeeWelcome;
use App\Services\EmployeeActivation;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * Single-page contract composer.
 *
 * Alpine owns the form while it is being filled in; this class does exactly
 * three things over the wire:
 *
 *   1. locationOptions()  — one renderless request per city change (inherited)
 *   2. leavePreview()     — one renderless request when the entitlement inputs
 *                           are complete, so the leave figure is calculated by
 *                           LeaveBalanceService rather than re-implemented in JS
 *   3. submit()           — one request at the end, where the server re-validates
 *                           everything from scratch
 *
 * Termination fields are deliberately absent: a contract cannot already be
 * terminated at the moment it is created. They remain available in ContractEdit.
 */
class ContractCreate extends Component
{
    use DrivesContractComposer, ResolvesContractLocationOptions {
        DrivesContractComposer::applyComposerTermination as private baseComposerTermination;
    }
    use RemembersReturnTo;
    use TracksTaskAttempt;
    use WithFeedback;

    public ContractForm $form;

    public LeaveBalanceForm $balanceForm;

    public ?int $city_id = null;

    public ?int $program_id = null;

    public ?int $center_id = null;

    /**
     * The contract being renewed, when this page was opened as a renewal.
     * Locked: it decides the term anchor and the heading, and mount() is the
     * only place allowed to establish it (after the renew gate has passed).
     */
    #[Locked]
    public ?Contract $renewalSource = null;

    /**
     * One per opened composer (B2). A double-sent or retried submit carries
     * the same token, and finds the contract the first one created instead
     * of writing a second. Locked: the browser can neither change nor clear it.
     */
    #[Locked]
    public string $submissionToken = '';

    // ────────────────────────────────────────────────
    // Lifecycle
    // ────────────────────────────────────────────────

    /**
     * Serves both `/contracts/create` and `/contracts/{contract}/renew`. With a
     * source contract the composer opens fully prefilled from it; without one
     * it behaves exactly as it always has.
     */
    public function mount(?Contract $contract = null): void
    {
        $this->form->setDefaults();
        $this->submissionToken = (string) Str::uuid();

        if ($contract?->exists) {
            $this->initializeRenewal($contract);
        } else {
            // X2-I task D: a new contract (renewals are a different task).
            $this->startTaskAttempt('contract_create');
        }
    }

    // ────────────────────────────────────────────────
    // Submission
    // ────────────────────────────────────────────────

    /**
     * Duplicate protection (B2). A double click, a request retried after a
     * timeout, or a response lost on the way back must create one contract:
     * under a lock on this composer's token, a token already used goes to the
     * contract it created. The disabled button in the browser is only feedback.
     */
    public function submit(array $payload): mixed
    {
        $key = $this->submissionKey();

        try {
            return Cache::lock($key.':lock', 30)->block(10, fn () => $this->submitOnce($payload, $key));
        } catch (LockTimeoutException) {
            $this->toast(__('general.composer.submit-in-progress'), type: 'info');

            return null;
        }
    }

    /**
     * X1-I: the browser lost the answer to submit() and asks whether the
     * operation was applied. A lookup by this composer's token, never a
     * second write; when nothing was created every entry stays on the page.
     */
    public function reconcileOperation(): mixed
    {
        abort_unless(auth()->user()?->hasActivePermission($this->locationOptionsPermission()), 403);

        $created = $this->createdBy($this->submissionKey());

        if ($created === null) {
            $this->toast(__('general.unknown-result.not-applied'), type: 'info');

            return null;
        }

        $this->toast(__('general.unknown-result.applied'));

        return $this->redirectRoute('contracts.show', $created->id, navigate: true);
    }

    /**
     * Does $employeeId's most recently created contract sit at type = Draft?
     * If so, the composer auto-fills that draft's position/program/center and
     * remembers its id, so submit() upgrades that same row in place instead
     * of inserting a second contract for someone who already has a
     * placeholder one (see EmployeeCreate's draft-position step).
     *
     * "Most recent" is by id, not start_date: a draft's start_date is null,
     * which would sort it as the *oldest* contract under any date-based
     * ordering and hide it behind real contract history that has none.
     */
    #[Renderless]
    public function draftContractFor(int $employeeId): ?array
    {
        $user = auth()->user();

        abort_unless($user, 403);
        abort_unless($user->hasActivePermission($this->locationOptionsPermission()), 403);

        $activeCityIds = $user->activeCityIds();

        $employeeVisible = Employee::query()
            ->whereKey($employeeId)
            ->when($activeCityIds !== null, fn ($query) => $query->whereIn('city_id', $activeCityIds))
            ->exists();

        if (! $employeeVisible) {
            return null;
        }

        $latest = Contract::query()
            ->where('employee_id', $employeeId)
            ->orderByDesc('id')
            ->first();

        if (! $latest || (int) $latest->type !== ContractTypeEnum::Draft) {
            return null;
        }

        $position = $latest->position;

        // Same rule ResolvesContractLocationOptions::initLocationContext() uses
        // for a renewal/edit: a center's own city is authoritative over the
        // position's plain city_id (Position::inCity() agrees).
        $effectiveCityId = $position?->center_id
            ? Center::whereKey($position->center_id)->value('city_id')
            : $position?->city_id;

        return [
            'draftContractId' => $latest->id,
            'positionId' => $latest->position_id,
            'programId' => $position?->program_id,
            'centerId' => $position?->center_id,
            'cityId' => $effectiveCityId,
        ];
    }

    // ────────────────────────────────────────────────
    // Rendering
    // ────────────────────────────────────────────────

    public function render(): View
    {
        $locale = app()->getLocale();
        $nameColumn = "name_{$locale}";

        $cities = $this->getCities($nameColumn);

        return view('livewire.contract.contract-create', [
            'cities' => $cities,
            'lockedCity' => $cities->count() === 1 ? $cities->first() : null,
            'types' => $this->contractTypeCards($nameColumn),
            // A plain create opens blank; a renewal opens on its predecessor.
            'seed' => $this->isRenewal() ? $this->composerSeed() : null,
            'errorSections' => $this->errorSections(),
            'canManageAutoRenew' => $this->canSetAutoRenew(),
        ]);
    }

    /**
     * G-B-10 (answer 2): HR sees the auto-renew switch while creating, already
     * on, and may turn it off. Other roles do not see it, and the contract
     * gets ContractObserver's default; a trial is always off.
     */
    protected function applyComposerTermination(array $payload): void
    {
        $this->baseComposerTermination($payload);

        if ($this->canSetAutoRenew() && array_key_exists('autoRenew', $payload)) {
            $this->form->auto_renew = (bool) $payload['autoRenew']
                && ! Contract::isTrialType($this->form->contract_type_id);
        }
    }

    protected function locationOptionsPermission(): string
    {
        return 'contracts.create';
    }

    protected function locationOptionsEmployeeId(): ?int
    {
        // A renewal keeps its employee even when that employee has since been
        // deactivated, so the dropdown must still be able to show them.
        return $this->renewalSource?->employee_id;
    }

    protected function contractTermAnchor(): ?string
    {
        return $this->isRenewal() ? $this->form->start_date : null;
    }

    private function submissionKey(): string
    {
        return 'contract-create-submission:'.auth()->id().':'.sha1($this->submissionToken);
    }

    private function submitOnce(array $payload, string $key): mixed
    {
        if ($created = $this->createdBy($key)) {
            return $this->redirectRoute('contracts.show', $created->id, navigate: true);
        }

        // A renewal may sit open for a while. Re-read the source and re-check
        // the gate, so a contract terminated or moved between opening the
        // composer and saving it cannot still be carried forward.
        if ($this->renewalSource) {
            $source = $this->renewalSource->fresh();

            // Deleted out from under the composer: there is nothing left to
            // renew, and re-checking the gate against the stale copy would be
            // a lie.
            abort_if($source === null, 403);

            Gate::authorize('renew', $source);
        }

        $this->applyComposerPayload($payload);

        try {
            $this->form->validateIdentity();
            $this->form->validateTerms();
            $this->form->validatePay();
        } catch (ValidationException $exception) {
            // Reopen the offending section before Livewire renders the errors.
            $this->dispatch('composer-invalid', sections: $this->sectionsFor(array_keys($exception->errors())));

            throw $exception;
        }

        if (! $this->validateCityConsistency()) {
            $this->dispatch('composer-invalid', sections: $this->sectionsFor($this->getErrorBag()->keys()));

            return null;
        }

        // Everything above was validated in this same request, so persist
        // without running the identical queries a second time.
        if ($draft = $this->resolveDraftUpgrade($payload)) {
            // Same row, filled in — not a new contract. update() skips
            // re-validating (already done above) and ContractObserver
            // recognises the type change away from Draft as the position's
            // true first assignment (see ContractObserver::updating()).
            $this->form->update($draft);
            $contract = $draft;
        } else {
            $contract = $this->form->create();
        }

        $this->balanceForm->store($contract->id);

        Cache::put($key, $contract->id, now()->addDay());

        // B5: the first real contract activates a volunteer awaiting it and
        // gives them their login account (DEC-04).
        $activation = app(EmployeeActivation::class)->activateForContract($contract);

        $this->notifyIfFirstContract($contract);

        $this->dispatch('notification-created');

        $this->finishTaskAttempt();
        $this->toast();

        if ($activation === EmployeeActivation::RESULT_ACCOUNT_CREATED) {
            $this->toast(__('employees.onboarding.account-created'), type: 'info');
        } elseif ($activation === EmployeeActivation::RESULT_EMAIL_TAKEN) {
            $this->toast(__('employees.onboarding.account-email-taken'), type: 'error');
        }

        return $this->redirectRoute('contracts.show', $contract->id, navigate: true);
    }

    // ────────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────────

    /** The same rule ContractForm applies when it saves (canPersistAutoRenew). */
    private function canSetAutoRenew(): bool
    {
        $user = auth()->user();

        if ($this->isRenewal()) {
            return $user->can('manageAutoRenew', $this->renewalSource);
        }

        return $user->activeRoleIs('HR') && $user->hasActivePermission('contracts.create');
    }

    private function isRenewal(): bool
    {
        return $this->renewalSource !== null;
    }

    /**
     * The draft contract $payload claims to be upgrading, re-verified from
     * scratch rather than trusted: it must still exist, belong to the exact
     * employee this submission is for, and still be type = Draft. A client
     * cannot make an arbitrary contract get overwritten by naming its id —
     * the same defense-in-depth `positionCapacityRule()`/
     * `validateCityConsistency()` already apply to renewal_source_id.
     */
    private function resolveDraftUpgrade(array $payload): ?Contract
    {
        $draftContractId = $this->selectionId($payload, 'draftContractId');

        if (! $draftContractId) {
            return null;
        }

        return Contract::query()
            ->whereKey($draftContractId)
            ->where('employee_id', $this->form->employee_id)
            ->where('type', ContractTypeEnum::Draft)
            ->first();
    }

    // ────────────────────────────────────────────────
    // Private — renewal
    // ────────────────────────────────────────────────

    private function initializeRenewal(Contract $contract): void
    {
        Gate::authorize('renew', $contract);

        // employee.media so the banner's avatar_url resolves from the loaded
        // relation rather than firing a media lookup of its own.
        $this->renewalSource = $contract->loadMissing(['position', 'employee.media']);

        [$start, $end] = $this->renewalWindowFromRequest($contract);

        $this->form->fillFromRenewal($contract, $start, $end);
        $this->initLocationContext($contract);
    }

    /**
     * The dates chosen in the renew modal, passed through the query string.
     *
     * They come from the browser and are re-validated here; anything missing or
     * malformed falls back to the contract's own default renewal window rather
     * than failing the page.
     *
     * @return array{0: string, 1: string}
     */
    private function renewalWindowFromRequest(Contract $contract): array
    {
        $requested = validator(request()->only(['start', 'end']), [
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        if ($requested->fails()) {
            return $contract->renewalWindow();
        }

        $validated = $requested->validated();

        return [$validated['start'], $validated['end']];
    }

    /**
     * The contract an earlier send of this composer created, if it is still
     * there and still this user's to see. A token is never a way to reach
     * someone else's contract.
     */
    private function createdBy(string $key): ?Contract
    {
        $id = Cache::get($key);

        if (! $id) {
            return null;
        }

        $contract = Contract::query()->find($id);

        return $contract && auth()->user()->can('view', $contract) ? $contract : null;
    }

    private function notifyIfFirstContract(Contract $contract): void
    {
        $employee = $contract->employee;

        if ($employee->allContracts()->count() !== 1) {
            return;
        }

        // Queued notifications to active colleagues in the employee's city.
        User::sendBroadcast(new NewEmployeeWelcome($employee), $employee->city_id);
    }
}
