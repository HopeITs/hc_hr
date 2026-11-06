{{--
    The amendments of one contract (ملحق عقد). The newest is shown open — it is
    either the one in flight or the one that says what the contract is now;
    older ones fold into one line each under "Earlier amendments", so a
    contract amended many times still costs the page one card.
--}}
<div>
    @if($amendments->isNotEmpty() || $canAmend)
        @php
            $latest = $amendments->first();
            $earlier = $amendments->slice(1)->values();
        @endphp

        <x-card id="amendments" class="scroll-mt-24" :title="__('contracts.amendment.list-title')" data-tour="contract-details.amendments">
            <x-slot:icon>
                @if($canAmend)
                    <x-button variant="outline" size="sm" :href="route('contracts.amend', $contract->id)">
                        <x-ic name="plus" class="size-4" />
                        @lang('contracts.amendment.add')
                    </x-button>
                @endif
            </x-slot:icon>

            @if($latest === null)
                <p class="text-small text-ink-2">@lang('contracts.amendment.none')</p>
            @else
                <div wire:key="amendment-{{ $latest->id }}">
                    @include('livewire.contract._amendment-item', [
                        'amendment' => $latest,
                        'lines' => $changes[$latest->id] ?? [],
                        'isCurrent' => $latest->id === $currentId,
                    ])
                </div>

                @if($earlier->isNotEmpty())
                    <details wire:ignore.self class="group/earlier mt-4 border-t border-line-soft pt-3">
                        <summary class="flex cursor-pointer list-none items-center gap-2 text-small font-semibold text-ink
                                        [&::-webkit-details-marker]:hidden">
                            <x-ic name="chevron" class="size-4 transition-transform group-open/earlier:rotate-90 rtl:-scale-x-100" />
                            {{ trans_choice('contracts.amendment.earlier', $earlier->count(), ['count' => $earlier->count()]) }}
                        </summary>

                        <ol class="mt-2 divide-y divide-line-soft">
                            @foreach($earlier as $amendment)
                                @php($lines = $changes[$amendment->id] ?? [])
                                <li wire:key="amendment-{{ $amendment->id }}">
                                    <details wire:ignore.self class="group/item py-2">
                                        {{-- One line: number, date and what changed. --}}
                                        <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 text-small
                                                        [&::-webkit-details-marker]:hidden">
                                            <x-ic name="chevron" class="size-3.5 text-ink-3 transition-transform group-open/item:rotate-90 rtl:-scale-x-100" />
                                            <span class="font-semibold text-ink">@lang('contracts.amendment.number', ['number' => $amendment->number])</span>
                                            <span class="num text-ink-2">{{ $amendment->effective_from->format('d/m/Y') }}</span>
                                            @if($lines !== [])
                                                {{-- What changed, named, not valued: the values open with the row. --}}
                                                <span class="min-w-0 flex-1 truncate text-ink-2">
                                                    @lang('contracts.amendment.changed'): {{ collect($lines)->pluck(0)->implode(' · ') }}
                                                </span>
                                            @endif
                                        </summary>

                                        <div class="ps-6 pt-2">
                                            @include('livewire.contract._amendment-item', [
                                                'amendment' => $amendment,
                                                'lines' => $lines,
                                                'isCurrent' => $amendment->id === $currentId,
                                            ])
                                        </div>
                                    </details>
                                </li>
                            @endforeach
                        </ol>
                    </details>
                @endif
            @endif
        </x-card>
    @endif
</div>
