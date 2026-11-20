<?php

namespace App\Livewire\Messages;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Support\Announcements\AnnouncementTemplate;
use App\Support\Announcements\AnnouncementTemplates;
use App\Support\Announcements\HolidayClosure;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ready-made announcements (App\Support\Announcements), reachable from
 * every page for whoever may use message templates: the header's envelope,
 * the Ctrl+K command, the Message templates page and a person's card in the
 * search all open it with the `announcement-compose` event, the last one with
 * the person's name already in.
 *
 * Like the message templates, nothing is sent by the system: the result is
 * copied or opened as a new message for HR to read and send themselves.
 */
class AnnouncementComposer extends Component
{
    use AuthorizesActivePermissions;

    public bool $open = false;

    /** The chosen template's key, '' while choosing. */
    public string $template = '';

    /** @var array<string, mixed> */
    public array $values = [];

    public string $to = '';

    public string $cc = '';

    /**
     * @param  array<string, mixed>  $prefill  values to start with (a person's name, say)
     */
    #[On('announcement-compose')]
    public function compose(?string $template = null, array $prefill = []): void
    {
        $this->authorizeActivePermission('message-templates.use');

        $this->reset(['template', 'values', 'to', 'cc']);
        $this->resetValidation();
        $this->open = true;

        if ($template !== null && AnnouncementTemplates::find($template)) {
            $this->choose($template, $prefill);
        }
    }

    /** @param  array<string, mixed>  $prefill */
    public function choose(string $key, array $prefill = []): void
    {
        $this->authorizeActivePermission('message-templates.use');

        $template = AnnouncementTemplates::find($key);
        abort_unless($template, 404);

        $values = $template->defaults();

        // Only the template's own fields, and only text: the event may come from the page.
        foreach ($prefill as $name => $value) {
            if (array_key_exists($name, $values) && is_scalar($value)) {
                $values[$name] = is_bool($values[$name]) ? (bool) $value : mb_substr((string) $value, 0, 500);
            }
        }

        $this->template = $key;
        $this->values = $values;
        ['to' => $this->to, 'cc' => $this->cc] = $template->recipients();
    }

    /** The message as a file Outlook opens as a new message (see AnnouncementTemplates::eml). */
    public function downloadEml(): StreamedResponse
    {
        $this->authorizeActivePermission('message-templates.use');

        $template = $this->current();
        abort_unless($template, 404);

        $message = $template->render($this->values);
        $eml = AnnouncementTemplates::eml($this->to, $this->cc, $message['subject'], $message['body']);
        abort_if($eml === null, 422);

        return response()->streamDownload(function () use ($eml): void {
            echo $eml;
        }, $template->key().'.eml', ['Content-Type' => 'message/rfc822']);
    }

    public function back(): void
    {
        $this->reset(['template', 'values', 'to', 'cc']);
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function updatedValues(mixed $value, string $field): void
    {
        $template = $this->current();

        if (! $template) {
            return;
        }

        $known = array_keys($template->defaults());

        // Nothing but the template's fields is kept, whatever the browser sends.
        $this->values = array_intersect_key($this->values, array_flip($known));
        $this->values = $template->updated($field, $this->values);
    }

    public function render(): View
    {
        $template = $this->current();
        $message = null;

        if ($template) {
            $rendered = $template->render($this->values);
            $problems = [
                ...array_map(fn (string $label) => __('messages.announcements.problems.missing', ['field' => $label]), $template->missing($this->values)),
                ...($template instanceof HolidayClosure ? $template->problems($this->values) : []),
            ];

            foreach (['to' => $this->to, 'cc' => $this->cc] as $field => $list) {
                foreach (AnnouncementTemplates::invalidAddresses($list) as $address) {
                    $problems[] = __('messages.announcements.problems.address', ['address' => $address]);
                }
            }

            if (AnnouncementTemplates::addresses($this->to) === []) {
                $problems[] = __('messages.announcements.problems.no-to');
            }

            $message = [
                ...$rendered,
                'problems' => $problems,
                'mailto' => AnnouncementTemplates::mailto($this->to, $this->cc, $rendered['subject'], $rendered['body']),
                'web' => AnnouncementTemplates::webLinks($this->to, $this->cc, $rendered['subject'], $rendered['body']),
            ];
        }

        return view('livewire.messages.announcement-composer', [
            'templates' => AnnouncementTemplates::all(),
            'current' => $template,
            'message' => $message,
        ]);
    }

    private function current(): ?AnnouncementTemplate
    {
        return AnnouncementTemplates::find($this->template ?: null);
    }
}
