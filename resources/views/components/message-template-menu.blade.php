{{--
    "Use a template": a dropdown on a person's card listing the active email and
    WhatsApp templates, each already filled in for this person. A link opens a
    new message (mailto: or WhatsApp) for the user to read and edit; nothing is
    sent. Shown only to who holds message-templates.use, and only when there is
    at least one template. Usage: <x-message-template-menu :employee="$employee" />
--}}
@props(['employee'])

@php
    $viewer = auth()->user();
    $items = collect();

    if ($viewer?->hasActivePermission('message-templates.use')) {
        $renderer = app(\App\Services\MessageTemplateRenderer::class);

        $items = \App\Models\MessageTemplate::query()->active()->orderBy('channel')->orderBy('name')->get()
            ->map(fn ($template) => [
                'template' => $template,
                'url' => $renderer->url($template, $employee, $viewer),
            ]);
    }
@endphp

@if($items->isNotEmpty())
    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
        <x-button variant="outline" size="sm" :loading="false" x-on:click="open = ! open" x-bind:aria-expanded="open">
            @lang('messages.use-template')
        </x-button>
        <ul x-show="open" x-cloak x-transition
            class="absolute z-30 mt-1 w-64 overflow-hidden rounded-surface border border-line-soft bg-card py-1 shadow-e3 end-0">
            @foreach($items as $item)
                <li>
                    @if($item['url'])
                        <a href="{{ $item['url'] }}" @if($item['template']->channel === 'whatsapp') target="_blank" rel="noopener" @endif
                           class="flex items-center justify-between gap-2 px-3 py-2 text-small font-semibold text-ink hover:bg-hover">
                            <span class="truncate"><bdi>{{ $item['template']->name }}</bdi></span>
                            <span class="shrink-0 text-caption text-ink-3">{{ $item['template']->channel === 'email' ? __('messages.email') : 'WhatsApp' }}</span>
                        </a>
                    @else
                        <span class="flex items-center justify-between gap-2 px-3 py-2 text-small text-ink-3" title="{{ __('messages.no-address') }}">
                            <span class="truncate"><bdi>{{ $item['template']->name }}</bdi></span>
                            <span class="shrink-0 text-caption">{{ __('messages.no-address') }}</span>
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
