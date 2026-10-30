<?php

namespace App\Livewire\Assistant;

use App\Livewire\Concerns\WithFeedback;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\GeminiException;
use App\Services\Assistant\LeaveRequestSubmitter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The AI assistant, in two places: the full page (route `assistant`) and the
 * small panel every authenticated page opens from the floating button
 * (<x-assistant.widget>). Both are this component, so both behave the same.
 *
 * The conversation lives in the session, not in component state: the panel
 * and the page read the same list, so "expand" (and back) continues it, and
 * nothing the browser sends can change an earlier turn. Every signed-in user
 * may ask; what the assistant may read is decided by the Assistant service.
 *
 * Leave drafts: an answer may carry a leave proposal. It is kept in the
 * session next to that answer under a random id; the browser only ever sends
 * that id back, and LeaveRequestSubmitter re-checks and re-validates it.
 */
class Chat extends Component
{
    use WithFeedback;

    /** Turns kept in the conversation; the service sends fewer of them to Gemini. */
    public const MAX_MESSAGES = 40;

    /** 'page' (the full page) or 'panel' (the floating widget). */
    #[Locked]
    public string $mode = 'page';

    public string $question = '';

    /** A friendly line when a question could not be answered (never exception text). */
    public ?string $notice = null;

    public function mount(Assistant $assistant, string $mode = 'page'): void
    {
        abort_unless($assistant->isAvailable(), 404);

        $this->mode = $mode === 'panel' ? 'panel' : 'page';
    }

    public function send(Assistant $assistant): void
    {
        abort_unless($assistant->isAvailable(), 404);

        $this->notice = null;
        $this->question = trim($this->question);

        $this->validate(
            ['question' => ['required', 'string', 'max:1000']],
            attributes: ['question' => __('assistant.question')],
        );

        $key = 'assistant:'.auth()->id();
        $limit = max(1, (int) config('assistant.hourly_limit', 30));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $this->notice = __('assistant.errors.limit', [
                'minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60)),
            ]);

            return;
        }

        RateLimiter::hit($key, 3600);

        $question = $this->question;
        $messages = $this->messages();

        try {
            $reply = $assistant->reply(auth()->user(), $this->history($messages), $question);
        } catch (GeminiException $e) {
            report($e);
            $this->notice = $e->isRateLimited() ? __('assistant.errors.busy') : __('assistant.errors.failed');

            // The question stays in the box so it can be sent again.
            return;
        } catch (Throwable $e) {
            report($e);
            $this->notice = __('assistant.errors.failed');

            return;
        }

        $answer = ['role' => 'assistant', 'text' => $reply->text];

        if (is_array($reply->leaveProposal) && $reply->leaveProposal !== []) {
            $answer['proposal'] = [
                'id' => Str::random(20),
                'state' => 'pending',
                'data' => $reply->leaveProposal,
            ];
        }

        // Read again: the other copy (panel or page) may have written meanwhile.
        $messages = $this->messages();
        $messages[] = ['role' => 'user', 'text' => $question];
        $messages[] = $answer;
        $this->store($messages);

        $this->question = '';
    }

    /**
     * Submits the leave request drafted under one answer. Only the id comes
     * from the browser: the proposal itself is the one kept in the session,
     * and it can be confirmed once.
     */
    public function confirmLeave(string $id, LeaveRequestSubmitter $submitter): void
    {
        $this->notice = null;

        $index = $this->findProposal($id);

        if ($index === null) {
            return;
        }

        // Atomic, so a double click or the panel and the page together cannot submit twice.
        if (! Cache::add($this->claimKey($id), true, now()->addDay())) {
            return;
        }

        $messages = $this->messages();
        $proposal = $messages[$index]['proposal']['data'];

        try {
            // The draft's id doubles as the form's save token: a repeat returns the same request.
            $submitter->submit(auth()->user(), $proposal, $id);
        } catch (ValidationException $e) {
            $this->updateProposal($id, 'failed', [
                'role' => 'assistant',
                'text' => collect($e->errors())->flatten()->map(fn ($m) => '- '.$m)
                    ->prepend(__('assistant.leave.failed'))->implode("\n"),
                'kind' => 'error',
            ]);

            return;
        } catch (Throwable $e) {
            report($e);
            Cache::forget($this->claimKey($id));
            $this->notice = __('assistant.errors.submit');

            return;
        }

        $this->updateProposal($id, 'submitted', [
            'role' => 'assistant',
            'text' => __('assistant.leave.submitted'),
            'kind' => 'submitted',
        ]);

        $this->toast(__('assistant.leave.submitted'));
    }

    /** Discards the draft under one answer; nothing is sent anywhere. */
    public function cancelLeave(string $id): void
    {
        if ($this->findProposal($id) === null || Cache::has($this->claimKey($id))) {
            return;
        }

        $this->updateProposal($id, 'cancelled', [
            'role' => 'assistant',
            'text' => __('assistant.leave.cancelled'),
            'kind' => 'cancelled',
        ]);
    }

    public function clear(): void
    {
        session()->forget(self::sessionKey());
        $this->reset('question', 'notice');
        $this->resetValidation();
    }

    /** Shown in the panel while the lazy component loads. */
    public function placeholder(): string
    {
        return '<div class="flex flex-1 items-center justify-center gap-2 p-6 text-small text-ink-2" role="status">'
            .'<span class="hc-spin" aria-hidden="true"></span>'.e(__('assistant.widget.loading')).'</div>';
    }

    public function render(): View
    {
        return view('livewire.assistant.chat', [
            'messages' => $this->messages(),
            'suggestions' => array_values((array) __('assistant.suggestions')),
        ])->title(__('assistant.title'));
    }

    /** The session key of the signed-in user's conversation. */
    public static function sessionKey(): string
    {
        return 'assistant.conversation.'.auth()->id();
    }

    /**
     * @return list<array{role: 'user'|'assistant', text: string, kind?: string, proposal?: array{id: string, state: string, data: array<string, mixed>}}>
     */
    protected function messages(): array
    {
        $messages = session(self::sessionKey(), []);

        return is_array($messages) ? array_values($messages) : [];
    }

    /** @param  list<array<string, mixed>>  $messages */
    protected function store(array $messages): void
    {
        session()->put(self::sessionKey(), array_slice(array_values($messages), -self::MAX_MESSAGES));
    }

    /**
     * What the service is given: the turns only, no drafts or local notes.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array{role: string, text: string}>
     */
    protected function history(array $messages): array
    {
        return array_values(array_map(
            fn (array $m) => ['role' => $m['role'], 'text' => $m['text']],
            array_filter($messages, fn (array $m) => ! isset($m['kind'])),
        ));
    }

    /** The index of the message holding a still pending proposal with this id. */
    protected function findProposal(string $id): ?int
    {
        foreach ($this->messages() as $index => $message) {
            if (($message['proposal']['id'] ?? null) === $id) {
                return $message['proposal']['state'] === 'pending' ? $index : null;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $note  the line added to the conversation */
    protected function updateProposal(string $id, string $state, array $note): void
    {
        $messages = $this->messages();

        foreach ($messages as $i => $message) {
            if (($message['proposal']['id'] ?? null) === $id) {
                $messages[$i]['proposal']['state'] = $state;
            }
        }

        $messages[] = $note;
        $this->store($messages);
    }

    protected function claimKey(string $id): string
    {
        return 'assistant:leave-proposal:'.auth()->id().':'.$id;
    }

    /** The answer as HTML: raw HTML in it is stripped and unsafe links dropped. */
    protected function toHtml(string $text): string
    {
        return Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }
}
