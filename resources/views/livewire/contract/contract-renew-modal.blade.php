{{--
    Palette rule for this modal: neutral greys plus the single brand red
    (--color-primary). Never tint the red down for a surface — a low-opacity
    #be1f24 reads as pink, which resources/css/app.css explicitly disallows.
    Emphasis surfaces use --color-selection instead.
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
                <div class="flex items-center gap-4 px-5 py-4 border-b border-neutral-200">
                    <img
                        src="{{ $employee?->avatar_url ?: asset('images/default-avatar.png') }}"
                        alt=""
                        loading="lazy"
                        class="size-14 shrink-0 rounded-full object-cover bg-neutral-100 ring-1 ring-neutral-300"
                    />

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-body font-semibold text-neutral-900">
                            {{ $employee?->name ?? __('general.na') }}
                        </p>
                        <p class="mt-0.5 truncate text-caption text-neutral-600">
                            {{ $contract->position?->title ?? __('general.na') }}
                        </p>
                        @if($contract->contractType)
                            <span class="mt-1.5 inline-flex items-center rounded-full border border-neutral-300
                                         bg-neutral-100 px-2 py-0.5 text-caption font-medium text-neutral-700">
                                {{ $contract->contractType->name }}
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Current period, stated as plainly as possible --}}
                <div class="flex items-center justify-between gap-4 px-5 py-3 bg-neutral-50 border-b border-neutral-200">
                    <span class="text-caption font-medium uppercase tracking-wide text-neutral-500">
                        @lang('general.current-period')
                    </span>
                    <span class="flex items-center gap-2 text-body tabular-nums text-neutral-800">
                        <span>{{ $contract->start_date?->format('d-m-Y') ?? '—' }}</span>
                        <svg class="size-4 shrink-0 text-neutral-400 rtl:rotate-180" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14M13 6l6 6-6 6"/>
                        </svg>
                        <span>{{ $contract->end_date?->format('d-m-Y') ?? '—' }}</span>
                    </span>
                </div>

                {{-- The new period --}}
                <div class="px-5 py-4">
                    <h4 class="mb-3 text-caption font-medium uppercase tracking-wide text-neutral-500">
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

                    <p class="mt-4 text-caption leading-relaxed text-neutral-500">
                        @lang('general.renew-explanation')
                    </p>
                </div>

                {{-- Actions. Fixed heights and a shared grid keep the three
                     buttons on one baseline instead of drifting apart. --}}
                <div class="flex flex-col-reverse gap-2 border-t border-neutral-200 bg-neutral-50 px-5 py-4
                            sm:flex-row sm:items-center sm:justify-end">
                    {{-- The dialog's close(): asks requestClose() if the period was changed. --}}
                    <button type="button" x-on:click="close()" wire:loading.attr="disabled"
                            class="inline-flex h-10 items-center justify-center rounded-control px-4 text-body font-medium
                                   text-neutral-700 hover:bg-neutral-200 transition disabled:opacity-60">
                        @lang('general.cancel')
                    </button>

                    <button type="button" wire:click="renewWithEdit" wire:target="renewWithEdit"
                            wire:loading.attr="disabled"
                            class="inline-flex h-10 items-center justify-center rounded-control border border-primary
                                   bg-white px-4 text-body font-semibold text-primary shadow-e1 transition
                                   hover:bg-primary hover:text-white disabled:opacity-60">
                        <span wire:loading.remove wire:target="renewWithEdit">@lang('general.renew-with-edit')</span>
                        <span wire:loading wire:target="renewWithEdit">@lang('general.loading')…</span>
                    </button>

                    <button type="button" wire:click="renewAsIs" wire:target="renewAsIs"
                            wire:loading.attr="disabled"
                            class="inline-flex h-10 items-center justify-center rounded-control bg-primary px-4
                                   text-body font-semibold text-white shadow-e1 transition
                                   hover:bg-primary-hover disabled:opacity-60">
                        <span wire:loading.remove wire:target="renewAsIs">@lang('general.renew-as-is')</span>
                        <span wire:loading wire:target="renewAsIs">@lang('general.saving')…</span>
                    </button>
                </div>
            </div>
        @endif
    </x-modal.dialog>
</div>
