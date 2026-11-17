{{-- Opened from the table's bulk action. Every id is re-scoped before anything is written. --}}
<div>
    @if ($result)
        <p id="bulk-tags-result" role="status" tabindex="-1" class="scroll-mt-24 rounded-control border border-neutral-200 bg-neutral-50 p-4 text-neutral-800">
            {{ $result }}
            <button type="button" wire:click="cancel" class="ms-2 cursor-pointer text-sm font-semibold underline">{{ __('recruitment-applicant.tags.dismiss') }}</button>
        </p>
    @endif

    @if ($open)
        <section class="rounded-surface border border-neutral-200 bg-neutral-50 p-4 sm:p-6">
            <h2 id="bulk-tags-title" tabindex="-1" class="scroll-mt-24 text-h4 font-semibold">{{ __('recruitment-applicant.tags.bulk_title') }}</h2>
            <p class="mt-1 text-sm text-secondary-medium">
                {{ trans_choice('recruitment-applicant.tags.bulk_selected', count($applicationIds), ['count' => count($applicationIds)]) }}
                &middot; {{ __('recruitment-applicant.tags.bulk_hint', ['max' => $limit]) }}
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <fieldset @error('mode') aria-describedby="bulk-tags-mode-error" @enderror>
                    <legend class="text-sm font-medium">{{ __('recruitment-applicant.tags.bulk_mode') }}</legend>
                    <div class="mt-2 flex gap-4 text-sm">
                        <label class="flex items-center gap-2">
                            <input type="radio" wire:model="mode" value="{{ \App\Livewire\Recruitment\Applicants\BulkTags::ATTACH }}" class="border-neutral-300">
                            {{ __('recruitment-applicant.tags.bulk_attach') }}
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" wire:model="mode" value="{{ \App\Livewire\Recruitment\Applicants\BulkTags::DETACH }}" class="border-neutral-300">
                            {{ __('recruitment-applicant.tags.bulk_detach') }}
                        </label>
                    </div>
                    <x-forms.input-error field="mode" input-id="bulk-tags-mode" />
                </fieldset>

                <div>
                    <x-forms.input-label for="bulkTagId" required>{{ __('recruitment-applicant.tags.tag') }}</x-forms.input-label>
                    <select id="bulkTagId" wire:model="tagId" aria-required="true"
                        @error('tagId') aria-invalid="true" aria-describedby="bulkTagId-error" @enderror
                        class="mt-1 w-full rounded-control border-neutral-300">
                        <option value="">—</option>
                        @foreach ($tags as $tag)
                            <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                        @endforeach
                    </select>
                    <x-forms.input-error field="tagId" input-id="bulkTagId" />
                </div>
            </div>
            <x-forms.input-error field="applicationIds" input-id="bulk-tags-selection" />

            <div class="mt-4 flex flex-wrap gap-3">
                <x-primary-button type="button" wire:click="apply" wire:loading.attr="disabled" wire:target="apply">
                    {{ __('recruitment-applicant.tags.bulk_apply') }}
                </x-primary-button>
                <x-secondary-button wire:click="cancel">
                    {{ __('recruitment-applicant.cancel') }}
                </x-secondary-button>
            </div>
        </section>
    @endif
</div>
