{{--
    Component sheet (/{locale}/_ui, local and testing only — UiSheetController).
    Every shared component in every state: the visual regression reference for
    the identity work. Check it in light and dark, English and Arabic, 375px and
    1440px. Static on purpose: nothing here reads data or calls Livewire.
--}}
@php
    use App\Enums\ContractStatus;
    use App\Enums\EmployeeStatus;
    use App\Enums\LeaveRequestStatus;

    $section = 'rounded-surface border border-line-soft bg-card p-5 shadow-e1';
    $h2 = 'mb-1 text-h4 font-extrabold text-ink';
    $tones = ['work' => 'play', 'done' => 'check', 'attn' => 'alert', 'stop' => 'ban', 'wait' => 'clock'];
@endphp

<x-layouts.app :title="__('ui.sheet.title')">
    <div class="mx-auto flex max-w-6xl flex-col gap-6 p-4 sm:p-6" data-testid="ui-sheet">
        <x-page-header :title="__('ui.sheet.title')" :lead="__('ui.sheet.lead')">
            <x-slot:actions>
                <x-theme-toggle />
            </x-slot:actions>
        </x-page-header>

        {{-- Status --}}
        <section class="{{ $section }}" aria-labelledby="ui-status">
            <h2 id="ui-status" class="{{ $h2 }}">{{ __('ui.sheet.status') }}</h2>
            <p class="mb-4 text-small text-ink-2">{{ __('ui.sheet.status-lead') }}</p>
            <div class="flex flex-wrap items-center gap-2" data-testid="ui-tones">
                @foreach ($tones as $tone => $icon)
                    <x-status :tone="$tone" :icon="$icon" :label="__('ui.sheet.tone.'.$tone)" />
                @endforeach
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="flex flex-wrap items-center gap-2">
                    @foreach (LeaveRequestStatus::getInstances() as $case)
                        <x-status :status="$case" />
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @foreach (EmployeeStatus::cases() as $case)
                        <x-status :status="$case" />
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @foreach (ContractStatus::cases() as $case)
                        <x-status :status="$case" />
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Tags --}}
        <section class="{{ $section }}" aria-labelledby="ui-tags">
            <h2 id="ui-tags" class="{{ $h2 }} mb-4">{{ __('ui.sheet.tags') }}</h2>
            <div class="flex flex-wrap items-center gap-2">
                @foreach (['act', 'late', 'rush', 'soft'] as $variant)
                    <x-tag :variant="$variant">{{ __('ui.sheet.tag.'.$variant) }}</x-tag>
                @endforeach
                <x-badge color="green">{{ __('ui.sheet.tag.soft') }}</x-badge>
            </div>
        </section>

        {{-- Buttons --}}
        <section class="{{ $section }}" aria-labelledby="ui-buttons">
            <h2 id="ui-buttons" class="{{ $h2 }} mb-4">{{ __('ui.sheet.buttons') }}</h2>
            <div class="flex flex-col gap-3">
                @foreach (['sm', 'md', 'lg'] as $size)
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach (['primary', 'outline', 'quiet', 'link'] as $variant)
                            <x-button :variant="$variant" :size="$size" :loading="false" :icon="$variant === 'primary' ? 'plus' : null">{{ __('ui.sheet.variant.'.$variant) }}</x-button>
                        @endforeach
                    </div>
                @endforeach
                <div class="flex flex-wrap items-center gap-2">
                    <x-button :loading="false" disabled>{{ __('ui.sheet.variant.disabled') }}</x-button>
                    <x-button variant="outline" :loading="false" disabled>{{ __('ui.sheet.variant.disabled') }}</x-button>
                    {{-- The busy state as Livewire shows it while the action runs. --}}
                    <button type="button" class="hc-btn hc-btn--primary" disabled aria-busy="true"><span class="hc-spin" aria-hidden="true"></span>{{ __('ui.sheet.variant.busy') }}</button>
                    <x-primary-button type="button" :showSpinner="false" class="!w-auto">{{ __('ui.sheet.variant.primary') }}</x-primary-button>
                    <x-secondary-button class="!w-auto">{{ __('ui.sheet.variant.quiet') }}</x-secondary-button>
                </div>
            </div>
        </section>

        {{-- Forms --}}
        <section class="{{ $section }}" aria-labelledby="ui-forms" x-data="{ schedule: '0', weekends: true }">
            <h2 id="ui-forms" class="{{ $h2 }} mb-4">{{ __('ui.sheet.forms') }}</h2>
            <div class="grid gap-x-5 gap-y-4 sm:grid-cols-2">
                <div>
                    <x-forms.input-label for="ui-text" :value="__('ui.sheet.field.text')" required />
                    <x-forms.input id="ui-text" :placeholder="__('ui.sheet.field.text')" aria-describedby="ui-text-hint" />
                    <p id="ui-text-hint" class="mt-1 text-hint text-ink-2">{{ __('ui.sheet.field.text-hint') }}</p>
                </div>
                <div>
                    <x-forms.input-label for="ui-invalid" :value="__('ui.sheet.field.invalid')" />
                    <x-forms.input id="ui-invalid" type="email" value="name@" aria-invalid="true" aria-describedby="ui-invalid-error" />
                    <p id="ui-invalid-error" class="hc-err mt-1">{{ __('ui.sheet.field.invalid-error') }}</p>
                </div>
                <div>
                    <x-forms.input-label for="ui-select" :value="__('ui.sheet.field.select')" />
                    <x-forms.select-input id="ui-select">
                        <option>Damascus</option>
                        <option>Aleppo</option>
                    </x-forms.select-input>
                </div>
                <div>
                    <x-forms.input-label for="ui-disabled" :value="__('ui.sheet.variant.disabled')" />
                    <x-forms.input id="ui-disabled" value="—" disabled />
                </div>
                <div class="sm:col-span-2">
                    <x-forms.input-label for="ui-textarea" :value="__('ui.sheet.field.textarea')" />
                    <x-forms.textarea id="ui-textarea"></x-forms.textarea>
                </div>
                <div>
                    <x-forms.input type="checkbox" id="ui-checkbox" checked>{{ __('ui.sheet.field.checkbox') }}</x-forms.input>
                    <x-forms.radio-group :legend="__('ui.sheet.field.radio')">
                        <x-forms.radio-input name="ui-radio" value="a" checked>{{ __('ui.sheet.field.option-a') }}</x-forms.radio-input>
                        <x-forms.radio-input name="ui-radio" value="b">{{ __('ui.sheet.field.option-b') }}</x-forms.radio-input>
                    </x-forms.radio-group>
                </div>
                <div class="flex flex-col gap-3">
                    <div>
                        <x-forms.input-label :value="__('ui.sheet.field.segmented')" />
                        <x-forms.segmented-control name="ui-schedule" model="schedule" :aria-label="__('ui.sheet.field.segmented')"
                                                   :options="['0' => __('ui.sheet.field.option-a'), '1' => __('ui.sheet.field.option-b')]" />
                    </div>
                    <x-forms.checkbox-card id="ui-card" model="weekends">{{ __('ui.sheet.field.card') }}</x-forms.checkbox-card>
                </div>
            </div>
        </section>

        {{-- Table: the livewire-tables theme (stacked cards below 820px) --}}
        <section class="{{ $section }}" aria-labelledby="ui-table">
            <h2 id="ui-table" class="{{ $h2 }} mb-4">{{ __('ui.sheet.table') }}</h2>
            <div class="hc-tbl-wrap overflow-x-auto rounded-surface border border-line-soft bg-card shadow-e1">
                <table class="hc-tbl relative w-full text-table">
                    <thead class="border-b border-line-soft">
                        <tr>
                            @foreach (['name', 'city', 'state'] as $col)
                                <th class="bg-soft p-0 text-start text-ink-2"><span class="flex whitespace-nowrap px-3 py-2.5 text-th font-bold">{{ __('ui.sheet.sample.'.$col) }}</span></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([['Rania Haddad', 'Damascus', EmployeeStatus::Active], ['Fadi Khoury', 'Aleppo', EmployeeStatus::AwaitingContract], ['Maya Saleh', 'Homs', EmployeeStatus::Inactive]] as [$name, $city, $state])
                            <tr class="group">
                                <td data-label="{{ __('ui.sheet.sample.name') }}" class="border-t border-line-soft bg-card p-0 group-hover:bg-hover"><div class="px-3 py-3 text-ink">{{ $name }}</div></td>
                                <td data-label="{{ __('ui.sheet.sample.city') }}" class="border-t border-line-soft bg-card p-0 group-hover:bg-hover"><div class="px-3 py-3 text-ink">{{ $city }}</div></td>
                                <td data-label="{{ __('ui.sheet.sample.state') }}" class="border-t border-line-soft bg-card p-0 group-hover:bg-hover"><div class="px-3 py-2.5"><x-status :status="$state" /></div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Card, empty state, dialog --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <section aria-labelledby="ui-card-title" class="flex flex-col gap-3">
                <h2 id="ui-card-title" class="{{ $h2 }}">{{ __('ui.sheet.card') }}</h2>
                <x-card :title="__('ui.sheet.sample.card-title')">
                    <x-slot:icon><x-ic name="calendar" class="size-5 text-ink-3" /></x-slot:icon>
                    {{ __('ui.sheet.sample.card-body') }}
                </x-card>
                <h2 class="{{ $h2 }} mt-3">{{ __('ui.sheet.dialog') }}</h2>
                <div class="flex flex-wrap gap-2">
                    <x-button variant="outline" :loading="false" x-on:click="$confirm()">{{ __('ui.sheet.open-dialog') }}</x-button>
                    {{-- A plain tag: Blade does not compile @js() inside a component tag's attribute. --}}
                    <button type="button" class="hc-btn hc-btn--quiet" x-on:click="$confirm({ danger: false, title: @js(__('ui.sheet.dialog')), message: @js(__('ui.sheet.lead')), confirmLabel: @js(__('general.confirmation')) })">{{ __('ui.sheet.open-dialog') }}</button>
                </div>
            </section>
            <section aria-labelledby="ui-empty" class="flex flex-col gap-3">
                <h2 id="ui-empty" class="{{ $h2 }}">{{ __('ui.sheet.empty') }}</h2>
                <x-center-empty-state :title="__('ui.sheet.sample.empty-title')" :description="__('ui.sheet.sample.empty-help')">
                    <x-slot:action><x-button icon="plus" :loading="false">{{ __('ui.sheet.sample.empty-action') }}</x-button></x-slot:action>
                </x-center-empty-state>
            </section>
        </div>
    </div>
</x-layouts.app>
