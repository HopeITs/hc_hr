<?php

namespace App\Livewire\Recruitment\Offers;

use App\Enums\SalaryPer;
use App\Models\RecruitmentHiringConversion;
use App\Models\RecruitmentJobOffer;
use App\Services\RecruitmentHiringConversionService as Conversions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Phase 2 Task 10: hire the candidate whose offer was accepted — create the
 * employee (or link the one they already are) and the contract, from the
 * accepted version of the offer.
 *
 * The contract terms are shown read-only: they are the accepted snapshot and
 * nothing here can change them. What HR fills in is who the employee is — the
 * same fields as the employee form — and, when the pay is per session, the
 * session length the offer does not carry.
 *
 * What would stop the hire (vacancy closed or full, position full or
 * inactive…) is listed before anything is typed; the service checks it again
 * on the locked rows when HR confirms.
 *
 * Like the Leaves page, the hire is confirmed in a LivewireAlert question
 * first (confirmHire), and its result is a LivewireAlert that stays on this
 * page and points to what comes next: the employee page, where HR gives
 * them a login account, or the contract.
 */
class Convert extends Component
{
    #[Locked]
    public int $offerId;

    /** 'create' | 'link' */
    public string $mode = '';

    public string $employeeId = '';

    /** @var array<string, mixed> the new employee; see Conversions::prefill() */
    public array $employee = [];

    public bool $confirmNotDuplicate = false;

    public string $sessionDuration = '';

    public function mount(int $offerId, Conversions $conversions): void
    {
        $this->offerId = $offerId;
        $conversion = $this->resolveConversion();

        if ($conversion->isConverted()) {
            return;
        }

        Gate::authorize('convert', $conversion);

        $this->employee = $conversions->prefill($conversion);

        // A member of staff who signed in to apply is that employee: linking
        // is preselected. No other match is ever preselected.
        $internal = $conversions->matches($conversion)
            ->first(fn (array $match): bool => in_array(Conversions::MATCH_INTERNAL, $match['reasons'], true));

        $this->mode = $internal ? Conversions::MODE_LINK : Conversions::MODE_CREATE;
        $this->employeeId = $internal ? (string) $internal['employee']->getKey() : '';
    }

    /**
     * Asks before hiring, the way the Leaves page asks before saving; the
     * confirm button runs save(). Nothing is checked or written here: save()
     * does all of it, on the locked rows.
     */
    public function confirmHire(Conversions $conversions): void
    {
        $conversion = $this->resolveConversion()->load(['jobOffer.position', 'jobOffer.application.applicant']);
        Gate::authorize('convert', $conversion);
        $this->resetErrorBag();

        // A name only for an employee the candidate may be linked to: an id
        // typed into the request names nobody.
        $linked = $this->mode === Conversions::MODE_LINK
            ? $conversions->matches($conversion, $this->employee['nationalNumber'] ?? null)->first(
                fn (array $match): bool => $match['kind'] === Conversions::MATCH_LINKABLE && (string) $match['employee']->getKey() === $this->employeeId,
            )
            : null;
        $position = (string) $conversion->jobOffer?->position?->title;

        LivewireAlert::title(__('recruitment-applicant.conversions.confirm_title', ['name' => (string) $conversion->jobOffer?->application?->applicant?->full_name]))
            ->timer(null)
            ->question()
            ->text($linked
                ? __('recruitment-applicant.conversions.confirm_text_link', ['employee' => $linked['employee']->name, 'position' => $position])
                : __('recruitment-applicant.conversions.confirm_text_create', ['position' => $position]))
            ->withConfirmButton(__('recruitment-applicant.conversions.confirm'))
            ->onConfirm('save')
            ->withCancelButton(__('recruitment-applicant.cancel'))
            ->show();
    }

    public function save(Conversions $conversions)
    {
        $conversion = $this->resolveConversion();
        Gate::authorize('convert', $conversion);
        $this->resetErrorBag();

        try {
            $conversions->convert(auth()->user(), $conversion, [
                'mode' => $this->mode,
                'employeeId' => $this->employeeId,
                'employee' => $this->employee,
                'confirmNotDuplicate' => $this->confirmNotDuplicate,
                'sessionDuration' => $this->sessionDuration,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Already rolled back and recorded on the conversion as a failed
            // attempt; HR gets a plain message rather than an error page.
            report($exception);
            $this->addError('conversion', __('recruitment-applicant.conversions.errors.unexpected'));

            return null;
        }

        // Stay here — the page now shows the hire — and say what comes next:
        // a login account is made on the employee page (PersonalInfoForm).
        $conversion = $this->resolveConversion()->load(['employee.account']);

        LivewireAlert::title(__('recruitment-applicant.conversions.converted'))
            ->success()
            ->timer(null)
            ->text(__('recruitment-applicant.conversions.converted_next', ['name' => (string) $conversion->employee?->name, 'contract' => $conversion->contract_id])
                .' '.($conversion->employee?->account
                    ? __('recruitment-applicant.conversions.has_account')
                    : __('recruitment-applicant.conversions.no_account', ['label' => __('general.create_login_account')])))
            ->withConfirmButton(__('recruitment-applicant.conversions.open_employee_edit'))
            ->onConfirm('openEmployee')
            ->withDenyButton(__('recruitment-applicant.conversions.open_contract_page'))
            ->onDeny('openContract')
            ->withCancelButton(__('recruitment-applicant.conversions.later'))
            ->show();

        return null;
    }

    /** The post-hire message's buttons. Navigation only: those pages check their own permissions. */
    public function openEmployee()
    {
        $conversion = $this->resolveConversion();
        abort_unless($conversion->isConverted() && $conversion->employee_id !== null, 404);

        return $this->redirectRoute('employees.edit', ['employee' => $conversion->employee_id], navigate: true);
    }

    public function openContract()
    {
        $conversion = $this->resolveConversion();
        abort_unless($conversion->isConverted() && $conversion->contract_id !== null, 404);

        return $this->redirectRoute('contracts.edit', ['contract' => $conversion->contract_id], navigate: true);
    }

    public function render(Conversions $conversions)
    {
        $conversion = $this->resolveConversion()->load([
            'jobOffer.application.applicant',
            'jobOffer.position',
            'jobOffer.vacancy',
            'jobOfferVersion.contractType',
            'employee.account',
            'contract',
            'convertedBy.profile',
        ]);
        $offer = $conversion->jobOffer;
        $converted = $conversion->isConverted();

        return view('livewire.recruitment.offers.convert', [
            'conversion' => $conversion,
            'offer' => $offer,
            'version' => $conversion->jobOfferVersion,
            'converted' => $converted,
            'canSeeSalary' => Gate::allows('viewSalary', $offer),
            'blockers' => $converted ? [] : $conversions->blockers($conversion),
            'matches' => $converted ? collect() : $conversions->matches($conversion, $this->employee['nationalNumber'] ?? null),
            'needsSessionDuration' => (int) $conversion->jobOfferVersion?->salary_per === SalaryPer::Session,
        ]);
    }

    /** Out of reach is a 404, like everywhere else on these pages. */
    private function resolveConversion(): RecruitmentHiringConversion
    {
        $offer = RecruitmentJobOffer::query()->visibleTo(auth()->user())->findOrFail($this->offerId);
        Gate::authorize('view', $offer);

        return RecruitmentHiringConversion::query()->where('job_offer_id', $offer->getKey())->firstOrFail();
    }
}
