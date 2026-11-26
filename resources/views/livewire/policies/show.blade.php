{{-- One policy as a formatted page, in its own language's direction, with its original file. Opened from
     the search: the typed words are marked and the page scrolls to the passage that matched. --}}
<div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8" data-testid="policy-show"
     @if ($target !== null) x-data x-init="$nextTick(() => document.getElementById('policy-passage-{{ $target }}')?.scrollIntoView({ block: 'center' }))" @endif>
    <x-page-header :title="$document->title">
        <x-slot:crumb>
            <a href="{{ \App\Livewire\Policies\Index::canOpen(auth()->user()) ? route('policies.index') : route('policies.folder') }}" wire:navigate>← @lang('policies.back')</a>
        </x-slot:crumb>
        <x-slot:actions>
            @if ($document->media)
                @if ($document->isPdf())
                    <x-button :href="route('policies.file', $document->id)" variant="outline" size="sm" target="_blank" :wire:navigate="false" data-testid="policy-open-file">
                        <x-ic name="eye" /> @lang('policies.open-file')
                    </x-button>
                @endif
                <x-button :href="route('policies.file', ['document' => $document->id, 'download' => 1])" variant="outline" size="sm" :wire:navigate="false" data-testid="policy-download">
                    <x-ic name="file" /> @lang('policies.download', ['type' => strtoupper((string) $document->media->extension)])
                </x-button>
            @endif
            @if ($canManage && $document->media)
                <x-button :href="\App\Livewire\Archives\ArchivedFilesModule::folderUrl($document->media->model_id)" variant="quiet" size="sm">
                    @lang('policies.manage-in-folder')
                </x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2 text-small text-ink-2">
        @if ($document->source_modified_at)
            <span>{{ __('policies.updated', ['date' => $document->source_modified_at->translatedFormat('j F Y')]) }}</span>
        @endif
        @if ($standing === 'outdated')
            <x-tag variant="act">@lang('policies.new-version')</x-tag>
        @elseif ($standing === 'agreed')
            <x-status tone="done" icon="check" :label="__('policies.you-agreed')" />
        @endif
        @if (! $document->media)
            <span>@lang('policies.no-file')</span>
        @endif
    </div>

    @if ($agreementDue)
        <x-card data-testid="policy-agree">
            <p class="text-body text-ink">
                {{ $standing === 'outdated' ? __('policies.agree-again-lead') : __('policies.agree-lead') }}
            </p>
            <x-button class="mt-3" variant="primary" size="sm" wire:click="agree" wire:loading.attr="disabled" data-testid="policy-agree-button">
                <x-ic name="check" /> @lang('policies.agree')
            </x-button>
        </x-card>
    @endif

    <article dir="{{ $direction }}" lang="{{ $direction === 'rtl' ? 'ar' : 'en' }}"
             class="policy-body rounded-surface border border-line-soft bg-card p-5 sm:p-8" data-testid="policy-body">
        @foreach ($passages as $i => $html)
            <section id="policy-passage-{{ $i }}" wire:key="passage-{{ $i }}"
                     @class(['policy-passage', 'policy-passage--target' => $target === $i])
                     @if ($target === $i) data-testid="policy-target" aria-label="{{ __('policies.match') }}" @endif>{!! $html !!}</section>
        @endforeach
    </article>
</div>
