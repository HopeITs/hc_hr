<?php

namespace App\Livewire\Assistant;

use App\Livewire\Concerns\WithFeedback;
use App\Models\AssistantConversation;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\AssistantReply;
use App\Services\Assistant\Drafts\DraftRegistry;
use App\Services\Assistant\GeminiException;
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
 * the RecordDraft of its type re-checks and re-validates it.
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

    /** Answers the question and adds both to the conversation. */
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

        try {
            $this->record($question, $assistant->reply(auth()->user(), $this->history($this->messages()), $question));
        } catch (Throwable $e) {
            report($e);

            // The question stays in the box so it can be sent again.
            $this->notice = $e instanceof GeminiException && $e->isRateLimited()
                ? __('assistant.errors.busy')
                : __('assistant.errors.failed');
        }
    }

    /**
     * Creates the record drafted under one answer (a leave request, a news
     * item, an employee, ...). Only the id comes from the browser: the
     * proposal itself is the one saved with the conversation, its RecordDraft
     * checks the permission and validates it again, and it can be confirmed
     * once.
     */
    public function confirmDraft(string $id, DraftRegistry $drafts): void
    {
        $this->notice = null;

        $index = $this->findProposal($id);

        if ($index === null) {
            return;
        }

        $saved = $this->messages()[$index]['proposal'];
        // Drafts saved before there were other kinds are leave requests.
        $draft = $drafts->for($saved['type'] ?? 'leave');

        if ($draft === null) {
            return;
        }

        // Atomic, so a double click or the panel and the page together cannot submit twice.
        if (! Cache::add($this->claimKey($id), true, now()->addDay())) {
            return;
        }

        try {
            // The draft's id doubles as the save token: a repeat returns the first result.
            $result = $draft->submit(auth()->user(), $saved['data'], $id);
        } catch (ValidationException $e) {
            $this->updateProposal($id, 'failed', [
                'role' => 'assistant',
                'text' => collect($e->errors())->flatten()->map(fn ($m) => '- '.$m)
                    ->prepend(__('assistant.draft.failed'))->implode("\n"),
                'kind' => 'error',
            ]);

            return;
        } catch (Throwable $e) {
            report($e);
            Cache::forget($this->claimKey($id));
            $this->notice = __('assistant.errors.submit');

            return;
        }

        $this->updateProposal($id, 'submitted', array_filter([
            'role' => 'assistant',
            'text' => $result['message'],
            'kind' => 'submitted',
            'url' => $result['url'] ?? null,
            'link' => $result['link'] ?? null,
        ]));

        $this->toast($result['message']);
    }

    /** Discards the draft under one answer; nothing is created. */
    public function cancelDraft(string $id): void
    {
        $index = $this->findProposal($id);

        // The chat-or-page buttons have nothing to cancel.
        if ($index === null || ($this->messages()[$index]['proposal']['type'] ?? null) === 'choice' || Cache::has($this->claimKey($id))) {
            return;
        }

        $this->updateProposal($id, 'cancelled', [
            'role' => 'assistant',
            'text' => __('assistant.draft.cancelled'),
            'kind' => 'cancelled',
        ]);
    }

    /** Starts a new conversation; the current one stays in the history. */
    public function clear(): void
    {
        session()->forget(self::sessionKey());
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
            'currentId' => self::current()?->id,
            'suggestions' => array_values((array) __('assistant.suggestions')),
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

        if (is_array($reply->draft) && $reply->draft !== []) {
            $answer['proposal'] = [
                'id' => Str::random(20),
                'state' => 'pending',
                'type' => $reply->draftType,
                'data' => $reply->draft,
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

        // Before chats were saved this key held the messages themselves.
        if (! is_int($id) && ! ctype_digit((string) (is_scalar($id) ? $id : ''))) {
            return null;
        }

        return AssistantConversation::ownedBy(auth()->id())->find((int) $id);
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

    /**
     * What a draft's card shows, from the RecordDraft of its type.
     *
     * @return array{title: string, icon: string, rows: array<string, string>}
     */
    protected function draftCard(array $proposal): array
    {
        $draft = app(DraftRegistry::class)->for($proposal['type'] ?? 'leave');

        return $draft?->card((array) ($proposal['data'] ?? []))
            ?? ['title' => __('assistant.draft.title'), 'icon' => 'file', 'rows' => []];
    }

    /** The answer as HTML: raw HTML in it is stripped and unsafe links dropped. */
    protected function toHtml(string $text): string
    {
        return Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }
}
