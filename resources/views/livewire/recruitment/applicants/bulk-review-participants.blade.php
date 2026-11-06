<div>
    @if ($result)
        <p id="bulk-review-access-result" role="status" tabindex="-1" class="scroll-mt-24 rounded-control border border-neutral-200 bg-neutral-50 p-4 text-neutral-800">
            {{ $result }}
            <button type="button" wire:click="cancel" class="ms-2 cursor-pointer text-sm font-semibold underline">{{ __('recruitment-applicant.tags.dismiss') }}</button>
        </p>
    @endif

    @if ($open)
        <section class="rounded-surface border border-neutral-200 bg-neutral-50 p-4 sm:p-6">
            <h2 id="bulk-review-access-title" tabindex="-1" class="text-h4 font-semibold">{{ __('recruitment-applicant.profile_reviews.bulk_title') }}</h2>
            <p class="mt-1 text-sm text-secondary-medium">{{ trans_choice('recruitment-applicant.profile_reviews.bulk_selected', count($applicationIds), ['count' => count($applicationIds)]) }}</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-forms.input-label for="bulkParticipantId" required>{{ __('recruitment-applicant.profile_reviews.person') }}</x-forms.input-label>
                    <x-forms.select2-input id="bulkParticipantId" wire:model="userId">
                        <option value="">{{ __('recruitment.choose') }}</option>
                        @foreach ($candidates as $candidate)
                            <option value="{{ $candidate->id }}">{{ $candidate->profile?->name ?? $candidate->email }} — {{ $candidate->email }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-forms.input-error field="bulkParticipantId" input-id="bulkParticipantId" />
                </div>
                <div>
                    <x-forms.input-label for="bulkParticipantRole" required>{{ __('recruitment-applicant.profile_reviews.access') }}</x-forms.input-label>
                    <select id="bulkParticipantRole" wire:model="role" class="h-[37.6px] w-full rounded-control border-neutral-300 py-0 text-sm shadow-e1">
                        @foreach (\App\Models\RecruitmentApplicationParticipant::roles() as $option)
                            <option value="{{ $option }}">{{ __('recruitment-applicant.profile_reviews.roles.'.$option) }}</option>
                        @endforeach
                    </select>
                    <x-forms.input-error field="bulkParticipantRole" input-id="bulkParticipantRole" />
                </div>
            </div>
            <x-forms.input-error field="applicationIds" input-id="bulk-review-access-selection" />
            <div class="mt-4 flex flex-wrap gap-3">
                <x-primary-button type="button" wire:click="apply" wire:loading.attr="disabled" wire:target="apply">{{ __('recruitment-applicant.profile_reviews.bulk_apply') }}</x-primary-button>
                <x-secondary-button wire:click="cancel">{{ __('recruitment-applicant.cancel') }}</x-secondary-button>
            </div>
        </section>
    @endif
</div>
