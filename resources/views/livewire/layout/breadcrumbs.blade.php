<div class="md:flex block items-center justify-between page-breadcrumb mb-4">
    <div class="my-auto mb-2">
        <h2 class="mb-1">{{ $title }}</h2>

        @if (!empty($items))
            <nav class="flex" aria-label="{{ __('general.a11y.breadcrumb') }}">
                <ol class="inline-flex flex-wrap items-center gap-1 md:gap-2">

                    {{-- Home --}}
                    <li class="inline-flex items-center">
                        <a href="{{ route('dashboard') }}" wire:navigate
                           class="inline-flex items-center text-small text-ink-2 hover:text-ink">
                            <x-ic name="home" class="size-4" />
                            <span class="sr-only">{{ __('general.dashboard') }}</span>
                        </a>
                    </li>

                    @foreach($items as $i => $crumb)
                        <li><span class="text-default">/</span></li>

                        <li class="text-body {{ $loop->last ? 'text-ink' : 'text-default' }}">
                            @if(!empty($crumb['href']))
                                <a href="{{ $crumb['href'] }}" wire:navigate class="hover:underline">
                                    {{ $crumb['label'] }}
                                </a>
                            @else
                                {{ $crumb['label'] }}
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif
    </div>
</div>
