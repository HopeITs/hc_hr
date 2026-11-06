@php
    use App\Models\RecruitmentApplicationParticipant as Participant;
@endphp

<section class="rounded-surface border border-neutral-200 bg-white p-4 shadow-e1 sm:p-5">
    <div>
        <h2 class="font-semibold">{{ __('recruitment-applicant.profile_reviews.title') }}</h2>
        <p class="mt-1 text-sm text-secondary-medium">{{ __('recruitment-applicant.profile_reviews.hint') }}</p>
    </div>

    @if ($canManage)
        <div class="mt-4 grid items-end gap-3 md:grid-cols-[1fr_12rem_auto]">
            <div>
                <x-forms.input-label for="applicant-profile-participant-{{ $applicant->id }}" :value="__('recruitment-applicant.profile_reviews.person')" />
                <x-forms.select2-input wire:model="newParticipantId" id="applicant-profile-participant-{{ $applicant->id }}">
                    <option value="">{{ __('recruitment.choose') }}</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->profile?->name }} — {{ $candidate->email }}</option>
                    @endforeach
                </x-forms.select2-input>
                <x-forms.input-error field="newParticipantId" :input-id="'applicant-profile-participant-'.$applicant->id" />
            </div>
            <div>
                <x-forms.input-label for="applicant-profile-role-{{ $applicant->id }}" :value="__('recruitment-applicant.profile_reviews.access')" />
                <select id="applicant-profile-role-{{ $applicant->id }}" wire:model="newParticipantRole" class="h-[34px] w-full cursor-pointer rounded-control border border-secondary-light bg-white px-3 py-0 text-body">
                    @foreach (Participant::roles() as $role)
                        <option value="{{ $role }}">{{ __('recruitment-applicant.profile_reviews.roles.'.$role) }}</option>
                    @endforeach
                </select>
            </div>
            <x-primary-button type="button" wire:click="addParticipant" wire:target="addParticipant">
                {{ __('recruitment-applicant.profile_reviews.add') }}
            </x-primary-button>
        </div>

        <ul class="mt-4 divide-y divide-neutral-100 text-sm">
            @forelse ($participants as $participant)
                <li class="flex flex-wrap items-center justify-between gap-3 py-2" wire:key="applicant-profile-participant-{{ $participant->id }}">
                    <div>
                        <span class="font-medium">{{ $participant->user?->profile?->name ?? $participant->user?->email }}</span>
                        <span class="text-secondary-medium">&middot; {{ __('recruitment-applicant.profile_reviews.roles.'.$participant->role) }}</span>
                    </div>
                    <button type="button" wire:click="removeParticipant({{ $participant->id }})" wire:confirm="{{ __('recruitment-applicant.profile_reviews.remove_confirm') }}" class="min-h-11 cursor-pointer text-sm font-semibold text-primary underline underline-offset-4 sm:min-h-0">
                        {{ __('recruitment-applicant.profile_reviews.remove') }}
                    </button>
                </li>
            @empty
                <li class="py-3 text-secondary-medium">{{ __('recruitment-applicant.profile_reviews.no_participants') }}</li>
            @endforelse
        </ul>
    @endif
</section>
