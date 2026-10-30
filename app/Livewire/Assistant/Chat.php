<?php

namespace App\Livewire\Assistant;

use App\Services\Assistant\Assistant;
use App\Services\Assistant\GeminiException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * The AI assistant page: one conversation per visit, held in the component
 * only (nothing is stored). Every signed-in user may ask; what the assistant
 * may read is decided by the Assistant service, never by this page.
 */
class Chat extends Component
{
    /** Turns kept on the page; the service sends fewer of them to Gemini. */
    public const MAX_MESSAGES = 40;

    /** @var list<array{role: 'user'|'assistant', text: string}> */
    public array $messages = [];

    public string $question = '';

    /** A friendly line when a question could not be answered (never exception text). */
    public ?string $notice = null;

    public function mount(Assistant $assistant): void
    {
        abort_unless($assistant->isAvailable(), 404);
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

        try {
            $answer = $assistant->reply(auth()->user(), $this->messages, $question);
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

        $this->messages[] = ['role' => 'user', 'text' => $question];
        $this->messages[] = ['role' => 'assistant', 'text' => $answer];
        $this->messages = array_slice($this->messages, -self::MAX_MESSAGES);
        $this->question = '';
    }

    public function clear(): void
    {
        $this->reset('messages', 'question', 'notice');
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.assistant.chat', [
            'suggestions' => array_values((array) __('assistant.suggestions')),
        ]);
    }

    /** The answer as HTML: raw HTML in it is stripped and unsafe links dropped. */
    protected function toHtml(string $text): string
    {
        return Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }
}
