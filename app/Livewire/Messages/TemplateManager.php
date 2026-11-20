<?php

namespace App\Livewire\Messages;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Models\MessageTemplate;
use App\Services\MessageTemplateRenderer;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * HR's list of email and WhatsApp templates. Writing one sends nothing: a
 * template is only filled in and opened as a new message from a person's card
 * (see MessageTemplateRenderer).
 */
class TemplateManager extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;

    public ?int $editingId = null;

    public bool $formOpen = false;

    public string $name = '';

    public string $channel = MessageTemplate::EMAIL;

    public string $language = 'en';

    public string $subject = '';

    public string $body = '';

    public bool $cc_manager = false;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorizeActivePermission('message-templates.manage');
    }

    public function newTemplate(): void
    {
        $this->resetForm();
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeActivePermission('message-templates.manage');

        $template = MessageTemplate::findOrFail($id);

        $this->editingId = $template->id;
        $this->name = $template->name;
        $this->channel = $template->channel;
        $this->language = $template->language;
        $this->subject = (string) $template->subject;
        $this->body = $template->body;
        $this->cc_manager = $template->cc_manager;
        $this->is_active = $template->is_active;
        $this->formOpen = true;
    }

    public function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'subject', 'body', 'cc_manager', 'formOpen']);
        $this->channel = MessageTemplate::EMAIL;
        $this->language = 'en';
        $this->is_active = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorizeActivePermission('message-templates.manage');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'channel' => ['required', Rule::in([MessageTemplate::EMAIL, MessageTemplate::WHATSAPP])],
            'language' => ['required', Rule::in(['en', 'ar'])],
            'subject' => [Rule::requiredIf($this->channel === MessageTemplate::EMAIL), 'nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:3000'],
            'cc_manager' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        if ($data['channel'] === MessageTemplate::WHATSAPP) {
            // A chat has no subject and nobody on CC.
            $data['subject'] = null;
            $data['cc_manager'] = false;
        }

        if ($this->editingId) {
            MessageTemplate::findOrFail($this->editingId)->update($data);
        } else {
            MessageTemplate::create([...$data, 'created_by' => auth()->id()]);
        }

        $this->toast(__('messages.saved'));
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeActivePermission('message-templates.manage');

        $template = MessageTemplate::findOrFail($id);
        $template->update(['is_active' => ! $template->is_active]);
    }

    public function delete(int $id): void
    {
        $this->authorizeActivePermission('message-templates.manage');

        MessageTemplate::findOrFail($id)->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        $this->toast(__('messages.deleted'));
    }

    public function render()
    {
        return view('livewire.messages.template-manager', [
            'templates' => MessageTemplate::query()->orderBy('channel')->orderBy('name')->get(),
            'variables' => MessageTemplateRenderer::VARIABLES,
        ]);
    }
}
