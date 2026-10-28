<?php

namespace App\Livewire\Feedback;

use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\WithFeedback;
use App\Services\InAppFeedbackRecorder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Send feedback to the IT team" (D3, DEC-08). Mounted once in the app layout
 * and opened from the user menu, so the page underneath is never left or
 * reloaded: closing, sending and failing all happen inside this dialog.
 *
 * Text only. There is no WithFileUploads here, so Livewire has no upload
 * endpoint for this component and no property can hold a file.
 *
 * What is stored besides the text is derived on the server when it is sent
 * (InAppFeedbackRecorder): the language of the request and the active role.
 * The page is the route name the layout rendered this component for, locked
 * against the browser and re-checked against the route table on submit.
 */
class FeedbackForm extends Component
{
    use GuardsModalClose;
    use WithFeedback;

    public bool $showFeedback = false;

    public string $message = '';

    public string $category = '';

    /** Route name of the page this layout was rendered for; never a URL. */
    #[Locked]
    public ?string $pageRoute = null;

    /** The active role the disclosure showed when the dialog opened. */
    #[Locked]
    public ?string $disclosedRole = null;

    public function mount(InAppFeedbackRecorder $recorder, ?string $pageRoute = null): void
    {
        $this->pageRoute = $recorder->safeRouteName($pageRoute);
    }

    #[On('open-feedback')]
    public function open(): void
    {
        abort_unless(auth()->check(), 403);

        if ($this->showFeedback) {
            return;
        }

        $this->reset('message', 'category');
        $this->resetValidation();
        $this->disclosedRole = auth()->user()->myRole();
        $this->markClean('showFeedback');
        $this->showFeedback = true;
    }

    public function submit(InAppFeedbackRecorder $recorder): void
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $this->message = trim($this->message);

        $this->validate([
            'message' => ['required', 'string', 'min:3', 'max:'.(int) config('feedback.max_length', 2000)],
            'category' => ['nullable', 'string', Rule::in(config('feedback.categories', []))],
        ], [], [
            'message' => __('feedback.form.message'),
            'category' => __('feedback.form.category'),
        ]);

        // The role was switched (another tab) since the disclosure was shown:
        // show the new one and let the user send again. The text is kept.
        if ($user->myRole() !== $this->disclosedRole) {
            $this->disclosedRole = $user->myRole();
            $this->addError('message', __('feedback.form.role-changed'));

            return;
        }

        if ($recorder->tooManyAttempts($user)) {
            $minutes = max(1, (int) ceil($recorder->availableInSeconds($user) / 60));
            $this->addError('message', trans_choice('feedback.form.rate-limited', $minutes, ['minutes' => $minutes]));
            $this->toast(trans_choice('feedback.form.rate-limited', $minutes, ['minutes' => $minutes]), 'error');

            return;
        }

        $recorder->record($user, $this->message, $this->category === '' ? null : $this->category, $this->pageRoute);

        $this->closeGuardedDialog('showFeedback');
        unset($this->closeBaselines['showFeedback']);

        $this->toast(__('feedback.form.sent'));
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.feedback.feedback-form', [
            'roleLabel' => $user ? (role_label($user->myRole()) ?: '—') : '—',
            'localeLabel' => app()->getLocale() === 'ar' ? 'العربية' : 'English',
        ]);
    }

    protected function guardedDialogs(): array
    {
        return ['showFeedback'];
    }

    protected function guardedEntries(string $dialog): array
    {
        return ['message' => $this->message, 'category' => $this->category];
    }

    protected function closeGuardedDialog(string $dialog): void
    {
        $this->showFeedback = false;
        $this->reset('message', 'category');
        $this->resetValidation();
    }
}
