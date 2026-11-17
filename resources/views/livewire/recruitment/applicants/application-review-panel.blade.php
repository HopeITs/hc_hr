@php
    use App\Enums\RecruitmentEvaluationRecommendation as Recommendation;
    use App\Models\RecruitmentApplicationParticipant as Participant;
@endphp

<section class="mt-4 rounded-control border border-neutral-200 bg-white p-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h4 class="font-semibold">{{ __($showAccess ? 'recruitment-applicant.profile_reviews.title' : 'recruitment-applicant.profile_reviews.my_review') }}</h4>
            <p class="mt-1 text-sm text-secondary-medium">{{ __($showAccess ? 'recruitment-applicant.profile_reviews.hint' : 'recruitment-applicant.profile_reviews.advisory') }}</p>
        </div>
        @if ($ownParticipant)
            <x-recruitment.pill>{{ __('recruitment-applicant.profile_reviews.roles.'.$ownParticipant->role) }}</x-recruitment.pill>
        @endif
    </div>

    @if ($showAccess && $canManage)
        <div class="mt-4 grid items-end gap-3 md:grid-cols-[1fr_12rem_auto]">
            <div>
                <x-forms.input-label for="application-review-participant-{{ $application->id }}" :value="__('recruitment-applicant.profile_reviews.person')" />
                <x-forms.select2-input wire:model="newParticipantId" id="application-review-participant-{{ $application->id }}">
                    <option value="">{{ __('recruitment.choose') }}</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->profile?->name }} — {{ $candidate->email }}</option>
                    @endforeach
                </x-forms.select2-input>
                <x-forms.input-error field="newParticipantId" :input-id="'application-review-participant-'.$application->id" />
            </div>
            <div>
                <x-forms.input-label for="application-review-role-{{ $application->id }}" :value="__('recruitment-applicant.profile_reviews.access')" />
                <select id="application-review-role-{{ $application->id }}" wire:model="newParticipantRole"
                    class="w-full cursor-pointer rounded-control border border-secondary-light bg-white px-3 py-2 text-body shadow-e1 focus:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary">
                    @foreach (Participant::roles() as $role)
                        <option value="{{ $role }}">{{ __('recruitment-applicant.profile_reviews.roles.'.$role) }}</option>
                    @endforeach
                </select>
                <x-forms.input-error field="newParticipantRole" :input-id="'application-review-role-'.$application->id" />
            </div>
            <x-primary-button type="button" wire:click="addParticipant" wire:target="addParticipant">
                {{ __('recruitment-applicant.profile_reviews.add') }}
            </x-primary-button>
        </div>

        <ul class="mt-4 divide-y divide-neutral-100 text-sm">
            @forelse ($participants as $participant)
                <li class="flex flex-wrap items-center justify-between gap-3 py-2" wire:key="application-review-participant-{{ $participant->id }}">
                    <div>
                        <span class="font-medium">{{ $participant->user?->profile?->name ?? $participant->user?->email }}</span>
                        <span class="text-secondary-medium">&middot; {{ __('recruitment-applicant.profile_reviews.roles.'.$participant->role) }}</span>
                    </div>
                    <button type="button" wire:click="removeParticipant({{ $participant->id }})" wire:confirm="{{ __('recruitment-applicant.profile_reviews.remove_confirm') }}"
                        class="min-h-11 cursor-pointer text-sm font-semibold text-primary underline underline-offset-4 sm:min-h-0">
                        {{ __('recruitment-applicant.profile_reviews.remove') }}
                    </button>
                </li>
            @empty
                <li class="py-3 text-secondary-medium">{{ __('recruitment-applicant.profile_reviews.no_participants') }}</li>
            @endforelse
        </ul>
    @endif

    @if ($canReview)
        <form wire:submit="saveReview" class="mt-5 space-y-4 border-t border-neutral-100 pt-5">
            <div>
                <h5 class="font-semibold">{{ __('recruitment-applicant.profile_reviews.my_review') }}</h5>
                <p class="mt-1 text-sm text-secondary-medium">{{ __('recruitment-applicant.profile_reviews.advisory') }}</p>
            </div>

            @error('review')
                <div role="alert" class="flex flex-wrap items-center justify-between gap-2 rounded-control border border-primary p-3 text-sm text-primary">
                    <span>{{ $message }}</span>
                    <button type="button" wire:click="reloadReview" class="cursor-pointer font-semibold underline underline-offset-4">
                        {{ __('recruitment-applicant.profile_reviews.reload') }}
                    </button>
                </div>
            @enderror

            <fieldset>
                <legend class="font-medium">
                    {{ __('recruitment-applicant.profile_reviews.recommendation') }}
                    <span class="text-primary" aria-hidden="true">*</span>
                </legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach (Recommendation::values() as $value)
                        <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-control border border-secondary-light px-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-selection has-[:checked]:font-semibold">
                            <input type="radio" wire:model="recommendation" value="{{ $value }}" class="text-primary focus:ring-0">
                            {{ __('recruitment-applicant.evaluations.recommendations.'.$value) }}
                        </label>
                    @endforeach
                </div>
                <x-forms.input-error field="recommendation" input-id="application-review-recommendation" />
            </fieldset>

            <div class="grid gap-4 md:grid-cols-2">
                @foreach (['pros', 'cons'] as $field)
                    <div>
                        <x-forms.input-label :for="'application-review-'.$field.'-'.$application->id" :value="__('recruitment-applicant.profile_reviews.'.$field)" />
                        <x-forms.textarea :id="'application-review-'.$field.'-'.$application->id" wire:model="{{ $field }}" rows="3" maxlength="{{ $maxLength }}" />
                        <x-forms.input-error :field="$field" :input-id="'application-review-'.$field.'-'.$application->id" />
                    </div>
                @endforeach
            </div>
            <div>
                <x-forms.input-label :for="'application-review-notes-'.$application->id" :value="__('recruitment-applicant.profile_reviews.notes')" />
                <x-forms.textarea :id="'application-review-notes-'.$application->id" wire:model="notes" rows="3" maxlength="{{ $maxLength }}" />
                <x-forms.input-error field="notes" :input-id="'application-review-notes-'.$application->id" />
            </div>
            <div class="flex justify-end">
                <x-primary-button type="submit" wire:target="saveReview">
                    {{ $expectedVersion ? __('recruitment-applicant.profile_reviews.update') : __('recruitment-applicant.profile_reviews.submit') }}
                </x-primary-button>
            </div>
        </form>
    @elseif ($ownParticipant)
        <p class="mt-4 rounded-control bg-neutral-50 p-3 text-sm text-secondary-medium">
            {{ __('recruitment-applicant.profile_reviews.viewer_hint') }}
        </p>
    @endif

    @if ($reviews->isNotEmpty())
        <details class="mt-5 border-t border-neutral-100 pt-4" @if (! $canManage) open @endif>
            <summary class="cursor-pointer font-semibold">{{ __('recruitment-applicant.profile_reviews.submitted_reviews') }} ({{ $reviews->count() }})</summary>
            <div class="mt-3 space-y-3">
                @foreach ($reviews as $review)
                    <article class="rounded-control bg-neutral-50 p-4" wire:key="application-profile-review-{{ $review->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span class="font-semibold">{{ $review->reviewer?->profile?->name ?? $review->reviewer?->email }}</span>
                            <span class="text-secondary-medium">
                                {{ __('recruitment-applicant.evaluations.recommendations.'.$review->recommendation) }}
                                &middot; {{ $review->updated_at?->format('Y-m-d H:i') }}
                            </span>
                        </div>
                        <dl class="mt-3 grid gap-3 md:grid-cols-2">
                            @foreach (['pros', 'cons', 'notes'] as $field)
                                @if (filled($review->{$field}))
                                    <div @class(['md:col-span-2' => $field === 'notes'])>
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-secondary-medium">{{ __('recruitment-applicant.profile_reviews.'.$field) }}</dt>
                                        <dd class="mt-1 whitespace-pre-line text-sm wrap-break-word">{{ $review->{$field} }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    </article>
                @endforeach
            </div>
        </details>
    @endif
</section>
