{{-- d. Categories × channels: a table on md+, one card per kind on phones. --}}
<section class="max-w-4xl rounded-surface border border-line-soft bg-card p-5 shadow-e1 sm:p-6" aria-labelledby="ns-matrix-title" data-testid="category-matrix">
    <h2 id="ns-matrix-title" class="text-h4 font-extrabold text-ink">{{ __('notification-settings.matrix.title') }}</h2>
    <p class="mt-0.5 text-small text-ink-2">{{ __('notification-settings.matrix.lead') }}</p>

    @foreach ($offChannels as $off)
        <p id="ns-off-{{ $off }}" class="mt-2 flex items-center gap-2 text-small text-ink-2">
            <x-ic name="pause" class="text-ink" />{{ __('notification-settings.matrix.channel-off', ['channel' => collect($channels)->firstWhere('value', $off)->label()]) }}
        </p>
    @endforeach
    @error('matrix')<p class="hc-err mt-2" data-inline-error>{{ $message }}</p>@enderror

    {{-- md and up: a real table. --}}
    <div class="mt-4 hidden overflow-x-auto md:block">
        <table class="w-full text-table">
            <caption class="sr-only">{{ __('notification-settings.matrix.title') }}</caption>
            <thead>
                <tr class="border-b border-line">
                    <th scope="col" class="py-2 pe-4 text-start text-th font-bold text-ink-2">{{ __('notification-settings.matrix.category') }}</th>
                    @foreach ($channels as $channel)
                        <th scope="col" class="px-2 py-2 text-center text-th font-bold text-ink-2">
                            {{ $channel->label() }}
                            @if (in_array($channel->value, $offChannels, true))
                                <span class="mt-1 block"><x-tag variant="soft" icon="pause" :label="__('notification-settings.matrix.paused')" /></span>
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-line-soft">
                @foreach ($categories as $category)
                    <tr wire:key="matrix-row-{{ $category->value }}">
                        <th scope="row" class="py-3 pe-4 text-start font-normal">
                            <span class="flex items-start gap-2.5">
                                <x-ic :name="$category->icon()" class="mt-0.5 size-5 text-ink-2" />
                                <span class="min-w-0">
                                    <span class="flex flex-wrap items-center gap-2 font-bold text-ink">
                                        {{ $category->label() }}
                                        @if ($category->urgent())<x-tag variant="rush" :label="__('notification-settings.matrix.urgent')" />@endif
                                    </span>
                                    <span class="block text-small text-ink-2">{{ $category->description() }}</span>
                                </span>
                            </span>
                        </th>
                        @foreach ($channels as $channel)
                            @php $off = in_array($channel->value, $offChannels, true); @endphp
                            <td class="px-2 py-1 text-center">
                                <label class="inline-flex min-h-11 min-w-11 items-center justify-center {{ $off ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                                    <input type="checkbox" class="hc-check rounded-inset disabled:opacity-50"
                                           id="ns-m-{{ $category->value }}-{{ $channel->value }}"
                                           wire:model.live="matrix.{{ $category->value }}.{{ $channel->value }}"
                                           @checked($matrix[$category->value][$channel->value])
                                           @disabled($off)
                                           aria-label="{{ $category->label() }}: {{ $channel->label() }}"
                                           @if ($off) aria-describedby="ns-off-{{ $channel->value }}" @endif>
                                </label>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Phones: one card per kind, each channel a labelled checkbox. --}}
    <div class="mt-4 space-y-3 md:hidden">
        @foreach ($categories as $category)
            <fieldset class="rounded-control border border-line-soft p-4" wire:key="matrix-card-{{ $category->value }}" aria-describedby="ns-mc-{{ $category->value }}-desc">
                <legend class="sr-only">{{ $category->label() }}</legend>
                <div class="flex items-start gap-2.5" aria-hidden="true">
                    <x-ic :name="$category->icon()" class="mt-0.5 size-5 text-ink-2" />
                    <p class="flex flex-wrap items-center gap-2 font-bold text-ink">
                        {{ $category->label() }}
                        @if ($category->urgent())<x-tag variant="rush" :label="__('notification-settings.matrix.urgent')" />@endif
                    </p>
                </div>
                <p id="ns-mc-{{ $category->value }}-desc" class="mt-0.5 text-small text-ink-2">{{ $category->description() }}</p>
                <div class="mt-2 grid grid-cols-2 gap-x-3">
                    @foreach ($channels as $channel)
                        @php $off = in_array($channel->value, $offChannels, true); @endphp
                        <label class="flex min-h-11 items-center gap-2.5 {{ $off ? 'cursor-not-allowed text-ink-3' : 'cursor-pointer text-ink' }}">
                            <input type="checkbox" class="hc-check rounded-inset disabled:opacity-50"
                                   wire:model.live="matrix.{{ $category->value }}.{{ $channel->value }}"
                                   @checked($matrix[$category->value][$channel->value])
                                   @disabled($off)
                                   @if ($off) aria-describedby="ns-off-{{ $channel->value }}" @endif>
                            <span>{{ $channel->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach
    </div>

    <p class="mt-4 flex items-start gap-2 text-small text-ink-2">
        <x-ic name="info" class="mt-0.5 text-ink" />{{ __('notification-settings.matrix.none') }}
    </p>
</section>
