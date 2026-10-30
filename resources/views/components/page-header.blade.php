{{--
    Page header: title + optional lead line on the start side, actions on the end side.

      <x-page-header :title="__('…')" :lead="__('…')">
          <x-slot:crumb><a href="…">…</a></x-slot:crumb>   (optional, above the title)
          <x-slot:actions><x-button>…</x-button></x-slot:actions>
      </x-page-header>

    The title is the page's h1 (clamp 26–34px, 800, balanced).
--}}
@props(['title', 'lead' => null])

<header {{ $attributes->class(['mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-3']) }}>
    <div class="min-w-0">
        @isset($crumb)
            <div class="mb-2 inline-flex min-h-8 items-center gap-1.5 font-semibold text-ink-2 hover:text-ink">{{ $crumb }}</div>
        @endisset
        <h1 class="text-h1 font-extrabold text-ink">{{ $title }}</h1>
        @if ($lead)
            <p class="mt-1.5 max-w-[64ch] text-body text-ink-2">{{ $lead }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</header>
