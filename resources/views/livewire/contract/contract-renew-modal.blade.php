{{--
    Palette rule for this modal: neutral greys plus the single brand red
    (--color-primary). Never tint the red down for a surface — a low-opacity
    #BE1F24 reads as pink, which resources/css/app.css explicitly disallows.
    Emphasis surfaces use --color-selection (#e6e7e8) instead.
--}}
<div>
    {{-- Explicit id: x-modal.dialog otherwise defaults every instance to
         id="modal", and this one shares pages with other modals. --}}
    <x-modal.dialog id="contractRenewModal" wire:model="show" maxWidth="lg" close-action="requestClose"
                    :title="__('general.renew-contract')">
        @if($contract)
            @php($employee = $contract->employee)

            {{-- Cancel out the dialog's own p-4 so each band can run edge to
                 edge; every band then re-applies the same px-5 gutter. --}}
            <div class="-m-4">

                {{-- Who is being renewed --}}
                <div class="flex items-center gap-4 px-5 py-4 border-b border-line-soft">
                    <img
                        src="{{ $employee?->avatar_url ?: asset('images/default-avatar.png') }}"
                        alt=""
                        loading="lazy"
                        class="size-14 shrink-0 rounded-full object-cover bg-surface ring-1 ring-line"
                    />

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-body font-semibold text-ink">
                            {{ $employee?->name ?? __('general.na') }}
                        </p>
                        <p class="mt-0.5 truncate text-caption text-ink-2">
                            {{ $contract->position?->title ?? __('general.na') }}
                        </p>
                        @if($contract->contractType)
                            <x-tag variant="soft" class="mt-1.5">{{ $contract->contractType->name }}</x-tag>
                        @endif
                    </div>
                </div>

                {{-- Current period, stated as plainly as possible --}}
                <div class="flex items-center justify-between gap-4 px-5 py-3 bg-soft border-b border-line-soft">
                    <span class="text-caption font-bold uppercase text-ink-2">
                        @lang('general.current-period')
                    </span>
                    <span class="flex items-center gap-2 text-body tabular-nums text-ink">
                        <span>{{ $contract->start_date?->format('d-m-Y') ?? '—' }}</span>
                        <svg class="size-4 shrink-0 text-ink-3 rtl:rotate-180" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14M13 6l6 6-6 6"/>
                        </svg>
                        <span>{{ $contract->end_date?->format('d-m-Y') ?? '—' }}</span>
                    </span>
                </div>

                {{-- The new period --}}
                <div class="px-5 py-4">
                    <h4 class="mb-3 text-caption font-bold uppercase text-ink-2">
                        @lang('general.new-period')
                    </h4>

                    {{-- items-start keeps both columns top-aligned when only one
                         of them is showing a validation message. --}}
                    <div class="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
                        <div class="min-w-0">
                            <x-forms.input-label for="renew_start_date" required>
                                @lang('general.start-date')
                            </x-forms.input-label>
                            <x-forms.date-input id="renew_start_date" class="w-full"
                                                wire:model.defer="form.start_date"/>
                            <x-error-span>{{ $errors->first('form.start_date') }}</x-error-span>
                        </div>

                        <div class="min-w-0">
                            <x-forms.input-label for="renew_end_date" required>
                                @lang('general.end-date')
                            </x-forms.input-label>
                            <x-forms.date-input id="renew_end_date" class="w-full"
                                                wire:model.defer="form.end_date"/>
                            <x-error-span>{{ $errors->first('form.end_date') }}</x-error-span>
                        </div>
                    </div>

                    {{-- Errors from the identity/pay validators, which have no
                         field of their own inside this modal. --}}
                    @foreach(['form.employee_id', 'form.position_id', 'form.contract_type_id', 'form.salary'] as $field)
                        @error($field)
                        <p class="mt-2 text-caption text-primary">{{ $message }}</p>
                        @enderror
                    @endforeach

                    <p class="mt-4 text-small leading-relaxed text-ink-2">
                        @lang('general.renew-explanation')
                    </p>
                </div>

                {{-- Actions. Fixed heights and a shared grid keep the three
                     buttons on one baseline instead of drifting apart. --}}
                <div class="flex flex-col-reverse gap-2 border-t border-line-soft bg-soft px-5 py-4
                            sm:flex-row sm:items-center sm:justify-end">
                    {{-- The dialog's close(): asks requestClose() if the period was changed. --}}
                    <x-button variant="quiet" x-on:click="close()">
                        @lang('general.cancel')
                    </x-button>

                    <x-button variant="outline" wire:click="renewWithEdit">
                        <span wire:loading.remove wire:target="renewWithEdit">@lang('general.renew-with-edit')</span>
                        <span wire:loading wire:target="renewWithEdit">@lang('general.loading')…</span>
                    </x-button>

                    <x-button wire:click="renewAsIs">
                        <span wire:loading.remove wire:target="renewAsIs">@lang('general.renew-as-is')</span>
                        <span wire:loading wire:target="renewAsIs">@lang('general.saving')…</span>
                    </x-button>
                </div>
            </div>
        @endif
    </x-modal.dialog>
</div>
