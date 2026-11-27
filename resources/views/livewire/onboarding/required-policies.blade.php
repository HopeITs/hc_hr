{{-- HR: which policies a new volunteer must read and agree to, and where each one stands. The files are
     managed in the Policies folder (track 7). --}}
<div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8" data-testid="required-policies">
    <x-page-header :title="__('onboarding.required-policies.title')" :lead="__('onboarding.required-policies.lead')">
        <x-slot:crumb><a href="{{ route('policies.index') }}" wire:navigate>← @lang('policies.back')</a></x-slot:crumb>
        <x-slot:actions>
            <x-button :href="$folderUrl" variant="primary" size="sm" data-testid="open-policy-folder">
                <x-ic name="folder" /> @lang('onboarding.required-policies.open-folder')
            </x-button>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        <p class="text-small text-ink-2">@lang('onboarding.required-policies.folder-hint')</p>
    </x-card>

    <x-card>
        <ul class="divide-y divide-line-soft">
            @forelse ($documents as $document)
                <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="doc-{{ $document->id }}">
                    <div class="min-w-0 space-y-1">
                        @if (filled($document->content))
                            <a href="{{ route('policies.show', $document->id) }}" wire:navigate class="font-medium text-ink hover:underline"><bdi>{{ $document->title }}</bdi></a>
                        @else
                            <span class="font-medium text-ink"><bdi>{{ $document->title }}</bdi></span>
                        @endif
                        <div class="flex flex-wrap items-center gap-2 text-small text-ink-2">
                            @if ($document->read_status === \App\Models\PolicyDocument::READING)
                                <x-status tone="work" icon="clock" :label="__('policies.status.reading')" />
                            @elseif ($document->read_status === \App\Models\PolicyDocument::FAILED)
                                <x-status tone="stop" icon="warning" :label="__('policies.status.failed')" />
                                <span>{{ __('policies.error.'.($document->read_error ?? 'unreadable')) }}</span>
                            @endif
                            @if (! $document->media_id)
                                <span data-testid="text-only-{{ $document->id }}">@lang('onboarding.required-policies.text-only')</span>
                                <x-button type="button" variant="link" size="sm" wire:click="deleteTextOnly({{ $document->id }})"
                                    wire:confirm="{{ __('onboarding.required-policies.delete-confirm') }}">@lang('onboarding.required-policies.delete')</x-button>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <select class="rounded-control border border-line bg-card px-2 py-1 text-small text-ink" aria-label="@lang('onboarding.required-policies.kind')"
                            wire:change="setKind({{ $document->id }}, $event.target.value)" data-testid="kind-{{ $document->id }}">
                            @foreach (\App\Models\PolicyDocument::KINDS as $kind)
                                <option value="{{ $kind }}" @selected($document->onboarding_kind === $kind)>@lang('onboarding.required-policies.kind-'.$kind)</option>
                            @endforeach
                        </select>
                        {{-- A policy with no text cannot be required (nothing to agree to); one already required stays tickable so HR can untick it. --}}
                        @php $canRequire = $document->hasText() || $document->required_for_onboarding; @endphp
                        <label class="flex items-center gap-2 text-small {{ $canRequire ? 'text-ink-2' : 'text-ink-3' }}">
                            <input type="checkbox" class="rounded border-line" @checked($document->required_for_onboarding) @disabled(! $canRequire)
                                wire:change="setRequired({{ $document->id }}, $event.target.checked)"
                                @unless ($document->hasText()) aria-describedby="needs-text-{{ $document->id }}" @endunless
                                data-testid="required-{{ $document->id }}">
                            @lang('onboarding.required-policies.required')
                        </label>
                        @unless ($document->hasText())
                            <p class="w-full text-small text-ink-2" id="needs-text-{{ $document->id }}" data-testid="needs-text-{{ $document->id }}">@lang('onboarding.required-policies.needs-text')</p>
                        @endunless
                    </div>
                </li>
            @empty
                <li class="py-3 text-body text-ink-2">@lang('onboarding.required-policies.none')</li>
            @endforelse
        </ul>
    </x-card>
</div>
