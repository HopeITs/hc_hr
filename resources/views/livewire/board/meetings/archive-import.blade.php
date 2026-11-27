{{--
    Old minutes (before the system): drop the files, check what was read from each, save. Each row is saved as a
    meeting that was held, with its own number and date, and the signed file kept as it is.
--}}
<div>
    @if (! $archiveOpen)
        <p class="text-small text-ink-2" role="status">@lang('board.archive.closed')</p>
    @elseif (! $open)
        <x-button variant="outline" type="button" wire:click="$set('open', true)">@lang('board.archive.open')</x-button>
    @else
        <section class="space-y-4 rounded-surface border border-line bg-card p-5" data-testid="board-archive-import" aria-labelledby="archive-title">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 id="archive-title" class="text-h4 font-bold text-ink">@lang('board.archive.title')</h2>
                    <p class="text-small text-ink-2">@lang('board.archive.help')</p>
                </div>
                <x-button variant="quiet" type="button" wire:click="$set('open', false)">@lang('board.close')</x-button>
            </div>

            <div>
                {{-- The native picker's own button does not follow the theme: a labelled button opens it instead. --}}
                <label class="inline-flex cursor-pointer items-center gap-2 rounded-control border border-line bg-card px-4 py-2 font-bold text-ink hover:bg-hover focus-within:outline focus-within:outline-2 focus-within:outline-offset-2">
                    <x-ic name="inbox" aria-hidden="true" />
                    @lang('board.archive.choose')
                    <input type="file" wire:model="files" multiple accept=".pdf,.docx,.doc" class="sr-only" aria-label="{{ __('board.archive.file') }}" />
                </label>
                <span class="ms-2 text-small text-ink-2">@lang('board.archive.types')</span>
                <p class="mt-1 text-small text-ink-2" wire:loading wire:target="files">@lang('board.archive.reading')</p>
                @error('files') <p class="mt-1 text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                @error('files.*') <p class="mt-1 text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
            </div>

            @if ($rows !== [])
                <p class="text-small text-ink-2">@lang('board.archive.check')</p>

                @foreach ($rows as $i => $row)
                    <div class="space-y-3 rounded-surface border border-line-soft bg-soft p-4" wire:key="archive-row-{{ $i }}">
                        <p class="truncate font-semibold text-ink" title="{{ $row['name'] }}">{{ $row['name'] }}</p>
                        <div class="grid gap-3 sm:grid-cols-4">
                            <div>
                                <x-forms.input-label :value="__('board.archive.number')" :required="true" />
                                <x-forms.input type="number" min="1" wire:model="rows.{{ $i }}.number" />
                                @error("rows.$i.number") <p class="mt-1 text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-forms.input-label :value="__('board.archive.date')" :required="true" />
                                <x-forms.input type="date" wire:model="rows.{{ $i }}.date" />
                                @error("rows.$i.date") <p class="mt-1 text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-forms.input-label :value="__('board.archive.from')" />
                                <x-forms.input type="time" wire:model="rows.{{ $i }}.from" />
                            </div>
                            <div>
                                <x-forms.input-label :value="__('board.archive.to')" />
                                <x-forms.input type="time" wire:model="rows.{{ $i }}.to" />
                            </div>
                            <div class="sm:col-span-2"><x-forms.input-label :value="__('board.fields.location')" /><x-forms.input wire:model="rows.{{ $i }}.location" /></div>
                            <div><x-forms.input-label :value="__('board.fields.room')" /><x-forms.input wire:model="rows.{{ $i }}.room" /></div>
                            <div><x-forms.input-label :value="__('board.fields.led-by')" /><x-forms.input wire:model="rows.{{ $i }}.led_by" /></div>
                            <div class="sm:col-span-4"><x-forms.input-label :value="__('board.fields.purpose')" /><x-forms.input wire:model="rows.{{ $i }}.purpose" /></div>
                        </div>
                        @if ($row['number'] === '' || $row['date'] === '')
                            <p class="text-small text-ink-2">@lang('board.archive.not-found')</p>
                        @endif
                        <div class="flex gap-2">
                            <x-button size="sm" variant="primary" type="button" wire:click="saveRow({{ $i }})">@lang('board.archive.save')</x-button>
                            <x-button size="sm" variant="quiet" type="button" wire:click="dropRow({{ $i }})">@lang('board.remove')</x-button>
                        </div>
                    </div>
                @endforeach

                @if (count($rows) > 1)
                    <x-button variant="primary" type="button" wire:click="saveAll">@lang('board.archive.save-all')</x-button>
                @endif
            @endif
        </section>
    @endif
</div>
