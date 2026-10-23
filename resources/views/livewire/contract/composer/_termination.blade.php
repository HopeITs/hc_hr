@php
    $miniButton = 'inline-flex items-center gap-1.5 rounded-control border border-neutral-300 bg-white
                   px-2.5 py-1 text-caption font-semibold text-secondary-dark shadow-e1 transition
                   hover:border-primary hover:text-primary active:scale-[0.98]
                   focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
@endphp

{{--
    Section 5 — editing only. A contract cannot already have ended early at the
    moment it is written, so the create screen does not render this at all.

    Optional throughout: the section never blocks the ones before it and never
    holds up the save. Leaving all three fields empty is the normal case.
--}}
<x-contract.section
    name="termination"
    index="4"
    :title="__('general.composer.termination')"
    summary="terminationSummary"
>
    {{-- Said once, plainly, because recording a date here deactivates the
         contract — and that consequence should not be discovered after saving. --}}
    <p class="mt-4 flex gap-2 rounded-control border border-neutral-200 border-s-4 border-s-primary
              bg-neutral-50 px-3 py-2 text-small leading-relaxed text-neutral-800">
        <svg class="mt-0.5 size-4 shrink-0 text-primary" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M10 1.5a8.5 8.5 0 100 17 8.5 8.5 0 000-17zM9 5.5h2v6H9v-6zm0 7.5h2v2H9v-2z"/>
        </svg>
        <span>@lang('general.composer.termination-hint')</span>
    </p>

    <div class="mt-4 grid gap-x-6 gap-y-4 md:grid-cols-2">
        @if($canManageAutoRenew)
            {{-- A trial never auto-renews (ContractObserver::saving()), so there is nothing to toggle. --}}
            <div class="md:col-span-2" x-show="selectedType?.category !== 'trial'">
                <div class="flex min-h-[2.625rem] items-center justify-between gap-4 rounded-control border border-neutral-200 bg-neutral-50 px-3 py-2">
                    <div class="min-w-0">
                        <x-forms.input-label for="composer_auto_renew">
                            @lang('general.auto-renew')
                        </x-forms.input-label>
                        <p class="mt-0.5 text-caption text-secondary-medium">
                            @lang('general.auto-renew-hint')
                        </p>
                        {{-- What switching it on actually does
                             (ContractAutoRenewalService). --}}
                        <p class="mt-1 text-caption text-secondary-medium">
                            @lang('contracts.composer-help.auto-renew')
                        </p>
                    </div>

                    <x-forms.toggle-input
                        id="composer_auto_renew"
                        x-model="autoRenew"
                        x-bind:checked="autoRenew"
                        class="shrink-0 p-2 text-xl"
                    />
                </div>
            </div>
        @endif

        <div>
            <x-forms.input-label for="composer_terminated_at">@lang('general.terminated-at')</x-forms.input-label>
            {{-- Mirrors validateTerms(): a contract cannot end before it began. --}}
            <x-forms.date-input
                id="composer_terminated_at"
                x-model="terminatedAt"
                x-bind:min="startDate"
            />
            <x-error-span>{{ $errors->first('form.terminated_at') }}</x-error-span>
        </div>

        <div>
            <x-forms.input-label for="composer_termination_reason">
                @lang('general.reason-of-termination')
            </x-forms.input-label>
            <x-forms.combobox
                id="composer_termination_reason"
                clearable
                options="terminationReasons"
                model="terminationReasonId"
            />
            <x-error-span>{{ $errors->first('form.termination_reason_id') }}</x-error-span>
        </div>

        <div class="md:col-span-2">
            <x-forms.input-label for="composer_reason_of_termination">
                @lang('general.reason-of-termination') — @lang('general.note')
            </x-forms.input-label>
            <x-forms.textarea
                id="composer_reason_of_termination"
                rows="3"
                x-model="reasonOfTermination"
                placeholder="{{ __('general.optional') }}"
            />
            <x-error-span>{{ $errors->first('form.reason_of_termination') }}</x-error-span>
        </div>
    </div>

    {{-- Only offered once there is something to undo. --}}
    <div x-cloak x-show="isTerminated || reasonOfTermination" class="mt-4">
        <button type="button" class="{{ $miniButton }}" x-on:click="clearTermination()">
            <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M6 6l8 8M14 6l-8 8" stroke-linecap="round"/>
            </svg>
            @lang('general.composer.clear-termination')
        </button>
    </div>
</x-contract.section>
