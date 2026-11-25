{{-- One policy in passages. Opened from the search: the typed words are marked and the page
     scrolls to the passage that matched. --}}
<div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8" data-testid="policy-show"
     @if ($target !== null) x-data x-init="$nextTick(() => document.getElementById('policy-passage-{{ $target }}')?.scrollIntoView({ block: 'center' }))" @endif>
    <x-page-header :title="$document->title">
        <x-slot:crumb>
            <a href="{{ route('policies.index') }}" wire:navigate>← @lang('policies.back')</a>
        </x-slot:crumb>
    </x-page-header>


    <div class="space-y-3">
        @foreach ($passages as $i => $html)
            <div id="policy-passage-{{ $i }}" wire:key="passage-{{ $i }}"
                 @class([
                     'whitespace-pre-line rounded-control border p-4 text-body text-ink',
                     'border-ink bg-soft' => $target === $i,
                     'border-line-soft bg-card' => $target !== $i,
                 ])
                 @if ($target === $i) data-testid="policy-target" aria-label="{{ __('policies.match') }}" @endif>{!! $html !!}</div>
        @endforeach
    </div>
</div>
