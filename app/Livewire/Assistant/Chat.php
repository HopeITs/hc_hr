<?php

namespace App\Livewire\Assistant;

use App\Livewire\Concerns\WithFeedback;
use App\Models\AssistantConversation;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\AssistantReply;
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
 * Conversations are saved (AssistantConversation, encrypted), and the session
 * only says which one is current: the panel and the page show the same one,
 * so "expand" (and back) continues it, and nothing the browser sends can
 * change an earlier turn. "New chat" starts another and the earlier ones stay
 * in the history. Every signed-in user may ask; what the assistant may read
 * is decided by the Assistant service.
 *
 * Leave drafts: an answer may carry a leave proposal. It is kept next to that
 * answer under a random id; the browser only ever sends that id back, and
 * LeaveRequestSubmitter re-checks and re-validates it.
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

    /**
     * Answers the question here, or, when no provider here can, starts a
     * relay: the browser then asks Puter and reports back (relayStep).
     *
     * @return array{relay: array<string, mixed>}|null
     */
    public function send(Assistant $assistant): ?array
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

            return null;
        }

        RateLimiter::hit($key, 3600);

        $question = $this->question;
        $history = $this->history($this->messages());

        if ($assistant->hasServerModel()) {
            try {
                $this->record($question, $assistant->reply(auth()->user(), $history, $question));

                return null;
            } catch (Throwable $e) {
                report($e);

                if (! $assistant->usesBrowser()) {
                    $this->notice = $e instanceof GeminiException && $e->isRateLimited()
                        ? __('assistant.errors.busy')
                        : __('assistant.errors.failed');

                    // The question stays in the box so it can be sent again.
                    return null;
                }
            }
        }

        // No provider here could answer: the browser asks Puter instead.
        [$state, $request] = $assistant->begin(auth()->user(), $history, $question);

        return $this->relay($state, $request, Str::random(20));
    }

    /**
     * The browser's model answered one round of a relayed question (see
     * Assistant::begin). Only the relay id held in the session is accepted.
     *
     * @param  array<string, mixed>  $message  a chat-completions message
     * @return array{relay: array<string, mixed>}|null the next request, or null when answered
     */
    public function relayStep(string $id, array $message, Assistant $assistant): ?array
    {
        abort_unless($assistant->isAvailable() && $assistant->usesBrowser(), 404);

        $relay = session(self::relayKey());

        if (! is_array($relay) || ($relay['id'] ?? null) !== $id) {
            return null;
        }

        try {
            [$state, $request, $reply] = $assistant->advance(auth()->user(), $relay['state'], self::cleanMessage($message));
        } catch (Throwable $e) {
            report($e);
            session()->forget(self::relayKey());
            $this->notice = __('assistant.errors.failed');

            return null;
        }

        if ($reply !== null) {
            session()->forget(self::relayKey());
            $this->record($state['question'], $reply);

            return null;
        }

        return $this->relay($state, $request, $id);
    }

    /** The browser could not ask Puter: `signin` (refused or closed), `funds` (out of credit) or anything else. */
    public function relayFailed(string $id, string $reason = ''): void
    {
        if ((session(self::relayKey())['id'] ?? null) !== $id) {
            return;
        }

        session()->forget(self::relayKey());

        $this->notice = match ($reason) {
            'signin' => __('assistant.errors.puter-signin'),
            'funds' => __('assistant.errors.puter-funds'),
            default => __('assistant.errors.failed'),
        };
    }

    /** The session key of the signed-in user's relayed question. */
    public static function relayKey(): string
    {
        return 'assistant.relay.'.auth()->id();
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

    /** Starts a new conversation; the current one stays in the history. */
    public function clear(): void
    {
        session()->forget([self::sessionKey(), self::relayKey()]);
        $this->reset('question', 'notice');
        $this->resetValidation();
    }

    /** Goes back to one of the signed-in user's earlier conversations. */
    public function openConversation(int $id): void
    {
        if (! AssistantConversation::ownedBy(auth()->id())->whereKey($id)->exists()) {
            return;
        }

        session()->put(self::sessionKey(), $id);
        session()->forget(self::relayKey());
        $this->reset('question', 'notice');
        $this->resetValidation();
    }

    /** Deletes one of the signed-in user's conversations. */
    public function deleteConversation(int $id): void
    {
        $deleted = AssistantConversation::ownedBy(auth()->id())->whereKey($id)->delete();

        if ($deleted && session(self::sessionKey()) === $id) {
            $this->clear();
        }
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
            // Titles and dates only: the messages are not decrypted for the list.
            'conversations' => AssistantConversation::ownedBy(auth()->id())
                ->latest('updated_at')->latest('id')
                ->limit(AssistantConversation::KEEP)
                ->get(['id', 'title', 'updated_at']),
            'currentId' => session(self::sessionKey()),
            'suggestions' => array_values((array) __('assistant.suggestions')),
            'usesPuter' => app(Assistant::class)->usesBrowser(),
        ])->title(__('assistant.title'));
    }

    /** The session key holding the id of the signed-in user's current conversation. */
    public static function sessionKey(): string
    {
        return 'assistant.conversation.'.auth()->id();
    }

    /** The messages of the signed-in user's current conversation. */
    public static function currentMessages(): array
    {
        $messages = self::current()?->messages;

        return is_array($messages) ? array_values($messages) : [];
    }

    /** Adds the question and its answer (with any leave draft) to the conversation. */
    protected function record(string $question, AssistantReply $reply): void
    {
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
     * Keeps a relay's state in the session and gives the browser what it
     * needs for the next round: the id, the model and the request.
     *
     * @return array{relay: array<string, mixed>}
     */
    protected function relay(array $state, array $request, string $id): array
    {
        session()->put(self::relayKey(), ['id' => $id, 'state' => $state]);

        return ['relay' => ['id' => $id, 'model' => config('assistant.puter.model')] + $request];
    }

    /**
     * Only what a chat-completions answer carries, bounded: text and at most
     * five tool calls (name and JSON arguments).
     *
     * @return array{content: string, tool_calls: list<array<string, mixed>>}
     */
    protected static function cleanMessage(array $message): array
    {
        $calls = [];

        foreach (array_slice(array_values((array) ($message['tool_calls'] ?? [])), 0, 5) as $call) {
            $name = $call['function']['name'] ?? null;
            $arguments = $call['function']['arguments'] ?? '{}';

            if (! is_string($name) || $name === '') {
                continue;
            }

            $calls[] = ['function' => [
                'name' => Str::limit($name, 64, ''),
                'arguments' => is_string($arguments) ? Str::limit($arguments, 4000, '') : json_encode($arguments),
            ]];
        }

        return [
            'content' => is_string($message['content'] ?? null) ? Str::limit($message['content'], 20000, '') : '',
            'tool_calls' => $calls,
        ];
    }

    /**
     * @return list<array{role: 'user'|'assistant', text: string, kind?: string, proposal?: array{id: string, state: string, data: array<string, mixed>}}>
     */
    protected function messages(): array
    {
        return self::currentMessages();
    }

    /** The signed-in user's current conversation, if they have started one. */
    protected static function current(): ?AssistantConversation
    {
        $id = session(self::sessionKey());

        return $id ? AssistantConversation::ownedBy(auth()->id())->find($id) : null;
    }

    /**
     * Saves the current conversation, starting one (titled with its first
     * question) when there is none yet.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    protected function store(array $messages): void
    {
        $messages = array_slice(array_values($messages), -self::MAX_MESSAGES);
        $conversation = self::current();

        if ($conversation === null) {
            $first = collect($messages)->firstWhere('role', 'user')['text'] ?? __('assistant.history.untitled');

            $conversation = AssistantConversation::create([
                'user_id' => auth()->id(),
                'title' => Str::limit(Str::squish($first), 80),
                'messages' => $messages,
            ]);

            session()->put(self::sessionKey(), $conversation->id);
            $this->prune();

            return;
        }

        $conversation->messages = $messages;
        $conversation->touch();
        $conversation->save();
    }

    /** Only the most recent AssistantConversation::KEEP chats are kept. */
    protected function prune(): void
    {
        $old = AssistantConversation::ownedBy(auth()->id())
            ->latest('updated_at')->latest('id')
            ->skip(AssistantConversation::KEEP)->take(PHP_INT_MAX)
            ->pluck('id');

        if ($old->isNotEmpty()) {
            AssistantConversation::whereKey($old)->delete();
        }
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
