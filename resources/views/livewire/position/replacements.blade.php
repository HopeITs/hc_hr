<x-slot:title>{{ __('critical-positions.panel-title') }}</x-slot:title>

<div data-testid="replacements-panel">
    <x-page-header :title="__('critical-positions.panel-title')" :lead="$position->title">
        <x-slot:crumb><a href="{{ route('positions.critical') }}" wire:navigate>@lang('critical-positions.title')</a></x-slot:crumb>
        <x-slot:actions>
            @if ($needs)
                <x-tag variant="act" :label="__('critical-positions.needs-replacement')" />
            @endif
            <x-tag variant="soft" icon="alert" :label="__('critical-positions.critical')" />
        </x-slot:actions>
    </x-page-header>

    @foreach ($reasons as $reason)
        <p class="mb-4 text-body text-ink-2">@lang('critical-positions.reason.'.$reason)</p>
    @endforeach

    <p class="mb-6 text-small text-ink-2">@lang('critical-positions.suggestions-only')</p>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-card>
            <h2 class="mb-3 text-h4 font-bold text-ink">@lang('critical-positions.internal-title')</h2>
            @forelse ($internal as $suggestion)
                <div class="py-3 border-t first:border-t-0 border-line-soft" wire:key="internal-{{ $suggestion->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-semibold text-ink">{{ $suggestion->name }}</p>
                            <p class="text-small text-ink-2">{{ $suggestion->detail }}</p>
                        </div>
                        <x-tag variant="soft" :label="__('critical-positions.score', ['score' => $suggestion->score])" />
                    </div>
                    <ul class="mt-2 space-y-1 text-small text-ink-2">
                        @foreach ($suggestion->reasonTexts() as $text)
                            <li>{{ $text }}</li>
                        @endforeach
                    </ul>
                    <x-button class="mt-2" variant="link" size="sm" :href="$suggestion->url">@lang('critical-positions.open-profile')</x-button>
                </div>
            @empty
                <x-center-empty-state class="min-h-40" :title="__('critical-positions.internal-empty')" :description="__('critical-positions.internal-empty-help')" />
            @endforelse
        </x-card>

        <x-card>
            <h2 class="mb-3 text-h4 font-bold text-ink">@lang('critical-positions.cv-title')</h2>
            @forelse ($cvs as $suggestion)
                <div class="py-3 border-t first:border-t-0 border-line-soft" wire:key="cv-{{ $suggestion->id }}">
                    <p class="font-semibold text-ink">{{ $suggestion->name }}</p>
                    <ul class="mt-2 space-y-1 text-small text-ink-2">
                        @foreach ($suggestion->reasonTexts() as $text)
                            <li>{{ $text }}</li>
                        @endforeach
                    </ul>
                    @if ($suggestion->url)
                        <x-button class="mt-2" variant="link" size="sm" :href="$suggestion->url">@lang('critical-positions.open-candidate')</x-button>
                    @endif
                </div>
            @empty
                <x-center-empty-state class="min-h-40" :title="__('critical-positions.cv-empty')" :description="__('critical-positions.cv-empty-help')" />
            @endforelse
        </x-card>
    </div>
</div>
