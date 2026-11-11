{{-- The add/edit holiday dialog: the holidays page and, embedded, the team calendar. --}}
<x-modal.dialog id="showModal" :title="$modalTitle" wire:model.defer="showModal" close-action="requestClose" wire:ignore.self>
    <form class="space-y-4" wire:submit.prevent="save">
        <div class="grid grid-cols-1 gap-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-forms.input-label for="holiday_name_en" required>@lang('general.name') (EN)</x-forms.input-label>
                    <x-forms.input type="text" wire:model="item.name_en" id="holiday_name_en" />
                    <x-error-span>{{ $errors->first('item.name_en') }}</x-error-span>
                </div>
                <div>
                    <x-forms.input-label for="holiday_name_ar" required>@lang('general.name') (AR)</x-forms.input-label>
                    <x-forms.input type="text" wire:model="item.name_ar" id="holiday_name_ar" dir="rtl" />
                    <x-error-span>{{ $errors->first('item.name_ar') }}</x-error-span>
                </div>
            </div>

            @php
                $kinds = ['official' => 'calendar', 'grant' => 'star'] + ($isNationalHr ? ['birthday' => 'cake'] : []);
            @endphp
            <div>
                <x-forms.input-label required>@lang('holidays.kind')</x-forms.input-label>
                <div @class(['grid grid-cols-1 gap-2', 'sm:grid-cols-3' => count($kinds) === 3, 'sm:grid-cols-2' => count($kinds) === 2])
                    role="radiogroup" aria-label="@lang('holidays.kind')">
                    @foreach ($kinds as $kind => $icon)
                        <label class="cursor-pointer" wire:key="kind-{{ $kind }}">
                            <input type="radio" class="peer sr-only" name="holiday_kind" value="{{ $kind }}" wire:model.live="item.kind">
                            <span class="hc-choice flex h-full items-start gap-3 p-3">
                                <span @class([
                                    'grid size-9 shrink-0 place-items-center rounded-control',
                                    'bg-ink text-on-ink' => ($item['kind'] ?? null) === $kind,
                                    'bg-soft text-ink-2' => ($item['kind'] ?? null) !== $kind,
                                ])>
                                    <x-ic :name="$icon" class="size-4.5" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block font-semibold text-ink">@lang('holidays.kinds.'.$kind)</span>
                                    <span class="mt-0.5 block text-small leading-snug text-ink-2">@lang('holidays.kind-hints.'.$kind)</span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <x-error-span>{{ $errors->first('item.kind') }}</x-error-span>
            </div>

            @if ($isNationalHr)
                <div>
                    <x-forms.input-label required>@lang('holidays.applies-to')</x-forms.input-label>
                    <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="@lang('holidays.applies-to')">
                        @foreach (['national' => ['users', 'holidays.national'], 'projects' => ['folder', 'holidays.selected-projects']] as $scope => [$icon, $label])
                            <label class="cursor-pointer" wire:key="scope-{{ $scope }}">
                                <input type="radio" class="peer sr-only" name="holiday_scope" value="{{ $scope }}" wire:model.live="item.scope">
                                <span class="hc-choice flex min-h-11 items-center justify-center gap-2 px-3 py-2 text-ink">
                                    <x-ic :name="$icon" class="size-4 text-ink-2" />
                                    <span class="font-semibold">@lang($label)</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <x-error-span>{{ $errors->first('item.scope') }}</x-error-span>
                </div>
            @endif

            @if (($item['scope'] ?? null) === 'projects')
                <div wire:key="holiday-projects-{{ $item['id'] ?? 'new' }}">
                    <x-forms.input-label required>@lang('holidays.projects')</x-forms.input-label>

                    @if (count($projectGroups) > 0)
                        {{--
                            Checkboxes per governorate. The city tabs only filter the list,
                            they never change the selection, so moving from Aleppo to Homs
                            is: clear Aleppo (its select-all box or "Clear all"), tick Homs.
                            Each tab counts what is ticked there, so nothing is hidden.
                        --}}
                        <div id="holiday_projects"
                            x-data="{
                                selected: $wire.entangle('item.projects'),
                                groups: @js($projectGroups),
                                city: @js(count($projectGroups) > 1 ? 'all' : $projectGroups[0]['id']),
                                visible() {
                                    return this.groups
                                        .filter(g => this.city === 'all' || g.id === this.city)
                                        .flatMap(g => g.options.map(o => o.value));
                                },
                                count(values) { return values.filter(v => (this.selected ?? []).includes(v)).length },
                                state(values) {
                                    const n = this.count(values);
                                    return n === 0 ? 'none' : (n === values.length ? 'all' : 'some');
                                },
                                toggle(values) {
                                    const rest = (this.selected ?? []).filter(v => ! values.includes(v));
                                    this.selected = this.state(values) === 'all' ? rest : [...rest, ...values];
                                },
                                clear() { this.selected = [] },
                            }"
                            @class([
                                'overflow-hidden rounded-control bg-card',
                                'border border-line' => ! $errors->has('item.projects') && ! $errors->has('item.projects.*'),
                                'border-2 border-primary' => $errors->has('item.projects') || $errors->has('item.projects.*'),
                            ])>

                            {{-- City tabs: filter only; the count is what is ticked there. --}}
                            @if (count($projectGroups) > 1)
                                <div class="flex flex-wrap gap-1.5 border-b border-line-soft p-2" role="tablist" aria-label="@lang('holidays.governorate')">
                                    <button type="button" role="tab" x-on:click="city = 'all'" x-bind:aria-selected="city === 'all'"
                                        x-bind:class="city === 'all' ? 'bg-ink text-on-ink border-ink' : 'bg-card text-ink-2 border-line hover:border-ink-2 hover:text-ink'"
                                        class="inline-flex items-center gap-1.5 rounded-round border px-3 py-1 text-small font-semibold transition">
                                        @lang('holidays.all-governorates')
                                        <span dir="ltr" class="num font-normal opacity-80" x-text="(selected ?? []).length"></span>
                                    </button>
                                    @foreach ($projectGroups as $group)
                                        <button type="button" role="tab" wire:key="city-tab-{{ $group['id'] }}"
                                            x-on:click="city = {{ $group['id'] }}" x-bind:aria-selected="city === {{ $group['id'] }}"
                                            x-bind:class="city === {{ $group['id'] }} ? 'bg-ink text-on-ink border-ink' : 'bg-card text-ink-2 border-line hover:border-ink-2 hover:text-ink'"
                                            class="inline-flex items-center gap-1.5 rounded-round border px-3 py-1 text-small font-semibold transition">
                                            {{ $group['name'] }}
                                            <span dir="ltr" class="num font-normal opacity-80"
                                                x-text="count({{ Js::from(array_column($group['options'], 'value')) }}) + '/{{ count($group['options']) }}'"></span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Select everything shown, or clear the whole selection. --}}
                            <div class="flex flex-wrap items-center justify-between gap-2 bg-soft px-3 py-2 text-small">
                                <label class="flex cursor-pointer items-center gap-2 font-semibold text-ink">
                                    <input type="checkbox" class="rounded-inset text-ink"
                                        x-bind:checked="state(visible()) === 'all'"
                                        x-bind:indeterminate="state(visible()) === 'some'"
                                        x-on:change="toggle(visible())">
                                    <span x-text="city === 'all'
                                        ? {{ Js::from(__('holidays.select-all')) }}
                                        : {{ Js::from(__('holidays.select-all-in')) }} + ' ' + groups.find(g => g.id === city)?.name"></span>
                                </label>
                                <span class="flex items-center gap-2">
                                    <span class="text-ink-2"><span class="num font-semibold text-ink" x-text="(selected ?? []).length"></span> @lang('holidays.selected')</span>
                                    <button type="button" x-on:click="clear()" x-bind:disabled="! (selected ?? []).length"
                                        class="inline-flex items-center gap-1 rounded-control px-2 py-1 font-semibold text-ink-2 transition hover:bg-surface hover:text-ink disabled:pointer-events-none disabled:opacity-40">
                                        <x-ic name="reset" class="size-3.5" />
                                        @lang('holidays.clear-all')
                                    </button>
                                </span>
                            </div>

                            <div class="max-h-56 overflow-y-auto overscroll-contain">
                                @foreach ($projectGroups as $group)
                                    <div x-show="city === 'all' || city === {{ $group['id'] }}" wire:key="city-group-{{ $group['id'] }}">
                                        @if (count($projectGroups) > 1)
                                            <p x-show="city === 'all'" class="sticky top-0 border-y border-line-soft bg-card px-3 py-1.5 text-caption font-semibold text-ink-2">{{ $group['name'] }}</p>
                                        @endif
                                        @foreach ($group['options'] as $option)
                                            <label class="flex cursor-pointer items-center gap-2.5 px-3 py-2 text-body text-ink transition hover:bg-soft" wire:key="project-{{ $option['value'] }}">
                                                <input type="checkbox" x-model="selected" value="{{ $option['value'] }}" class="rounded-inset text-ink">
                                                {{ $option['label'] }}
                                            </label>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <p class="rounded-control border border-dashed border-line px-3 py-4 text-center text-small text-ink-2">@lang('holidays.no-projects-to-pick')</p>
                    @endif
                    <x-error-span>{{ $errors->first('item.projects') ?: $errors->first('item.projects.*') }}</x-error-span>
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-forms.input-label for="holiday_start" required>@lang(($item['kind'] ?? '') === 'birthday' ? 'holidays.valid-from' : 'holidays.from')</x-forms.input-label>
                    <x-forms.date-input wire:model="item.start_date" id="holiday_start" />
                    <x-error-span>{{ $errors->first('item.start_date') }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="holiday_end" required>@lang(($item['kind'] ?? '') === 'birthday' ? 'holidays.valid-to' : 'holidays.to')</x-forms.input-label>
                    <x-forms.date-input wire:model="item.end_date" id="holiday_end" />
                    <x-error-span>{{ $errors->first('item.end_date') }}</x-error-span>
                </div>
            </div>

            @if (($item['kind'] ?? null) !== 'grant')
                <label class="flex items-center gap-2">
                    <x-forms.toggle-input wire:model="item.is_recurring" :active="$item['is_recurring'] ?? false" />
                    <span>
                        @lang('holidays.recurring')
                        <span class="block text-small text-ink-2">@lang('holidays.recurring-hint')</span>
                    </span>
                </label>
            @endif
        </div>

        <div class="flex justify-end space-x-2">
            <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
            <x-modal.cancel-btn />
        </div>
    </form>
</x-modal.dialog>
