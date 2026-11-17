{{-- Opened from the table's bulk action. Every id is re-scoped before anything is written. --}}
<div>
    @if ($result)
        <div id="bulk-reject-result" role="status" tabindex="-1" class="scroll-mt-24 rounded-control border border-primary/30 bg-primary/5 p-4 text-sm text-primary">
            <p class="font-semibold">{{ __('recruitment-applicant.decisions.bulk_done', ['count' => $result['rejected']]) }}</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach (['already_rejected', 'ineligible', 'out_of_scope', 'queued', 'already_notified', 'no_recipient', 'queue_failed'] as $key)
                    @if (($result[$key] ?? 0) > 0)
                        <li @class(['text-primary' => $key === 'queue_failed'])>{{ __('recruitment-applicant.decisions.bulk_outcome.'.$key, ['count' => $result[$key]]) }}</li>
                    @endif
                @endforeach
            </ul>
            <button type="button" wire:click="cancel" class="mt-2 cursor-pointer text-sm font-semibold underline">{{ __('recruitment-applicant.tags.dismiss') }}</button>
        </div>
    @endif

    @if ($open)
        <section class="rounded-surface border border-primary/30 bg-primary/5 p-4 sm:p-6">
            <h2 id="bulk-reject-title" tabindex="-1" class="scroll-mt-24 text-h4 font-semibold">{{ __('recruitment-applicant.decisions.bulk_title') }}</h2>
            <p class="mt-1 text-sm text-secondary-medium">
                {{ trans_choice('recruitment-applicant.tags.bulk_selected', count($applicationIds), ['count' => count($applicationIds)]) }}
                &middot; {{ __('recruitment-applicant.decisions.bulk_hint', ['max' => $limit]) }}
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-forms.input-label for="bulkReasonCode" required>{{ __('recruitment-applicant.decisions.reason_code') }}</x-forms.input-label>
                    <select id="bulkReasonCode" wire:model.live="reasonCode" aria-required="true"
                        @error('reasonCode') aria-invalid="true" aria-describedby="bulkReasonCode-error" @enderror
                        class="mt-1 w-full rounded-control border-secondary-light">
                        <option value="">—</option>
                        @foreach ($reasons as $code)
                            <option value="{{ $code }}">{{ __('recruitment-applicant.decisions.rejection_reasons.'.$code) }}</option>
                        @endforeach
                    </select>
                    <x-forms.input-error field="reasonCode" input-id="bulkReasonCode" />
                </div>

                <div class="sm:col-span-2">
                    <x-forms.input-label for="bulkReason" :required="$needsReason">{{ __('recruitment-applicant.decisions.internal_note') }}</x-forms.input-label>
                    <textarea id="bulkReason" wire:model="reason" rows="3" @if ($needsReason) aria-required="true" @endif
                        aria-describedby="bulkReason-hint @error('reason') bulkReason-error @enderror" @error('reason') aria-invalid="true" @enderror
                        class="mt-1 w-full rounded-control border-secondary-light"></textarea>
                    <p id="bulkReason-hint" class="mt-1 text-xs text-secondary-medium">{{ __('recruitment-applicant.decisions.internal_note_hint') }}</p>
                    <x-forms.input-error field="reason" input-id="bulkReason" />
                </div>

                <label class="flex items-start gap-2 text-sm sm:col-span-2">
                    <input type="checkbox" wire:model="notify" class="mt-0.5 rounded border-secondary-light">
                    <span>
                        {{ __('recruitment-applicant.decisions.bulk_notify') }}
                        <span class="block text-xs text-secondary-medium">{{ __('recruitment-applicant.decisions.notify_hint') }}</span>
                    </span>
                </label>
            </div>
            <x-forms.input-error field="applicationIds" input-id="bulk-reject-selection" />

            <div class="mt-4 flex flex-wrap gap-3">
                <x-primary-button type="button" wire:click="apply" wire:target="apply"
                    wire:confirm="{{ __('recruitment-applicant.decisions.bulk_confirm') }}">
                    {{ __('recruitment-applicant.decisions.bulk_apply') }}
                </x-primary-button>
                <x-secondary-button wire:click="cancel">
                    {{ __('recruitment-applicant.cancel') }}
                </x-secondary-button>
            </div>
        </section>
    @endif
</div>
