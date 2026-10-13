{{-- The interview's staff. Removed participants stay listed, struck through, so the history reads true. --}}
@php use App\Services\RecruitmentInterviewSchedulingService; @endphp
<section class="rounded-surface border border-secondary-soft bg-white p-6 shadow-e1">
    <h2 class="text-h4 font-semibold">{{ __('recruitment-applicant.interviews.participants') }}</h2>
    @if ($interview->participants->isEmpty())
        <p class="mt-2 text-sm text-secondary-medium">{{ __('recruitment-applicant.interviews.no_participants') }}</p>
    @else
        <ul class="mt-3 divide-y divide-secondary-soft text-sm">
            @foreach ($interview->participants as $participant)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2 {{ $participant->removed_at ? 'text-secondary-medium' : '' }}" wire:key="participant-{{ $participant->id }}">
                    <div>
                        <span class="font-medium {{ $participant->removed_at ? 'line-through' : '' }}">{{ $personName($participant->user) }}</span>
                        <span class="text-secondary-medium">&middot; {{ __('recruitment-applicant.interviews.roles.'.$participant->role) }}</span>
                        @unless (RecruitmentInterviewSchedulingService::isActiveUser($participant->user))
                            <x-recruitment.pill class="ms-2">{{ __('recruitment-applicant.interviews.inactive') }}</x-recruitment.pill>
                        @endunless
                        @if ($participant->removed_at)
                            <span class="ms-2 text-xs">{{ __('recruitment-applicant.interviews.removed_on', ['date' => $at($participant->removed_at)]) }}</span>
                        @endif
                    </div>
                    @if ($canManage)
                        <span class="text-xs text-secondary-medium">{{ __('recruitment-applicant.interviews.notification_statuses.'.$participant->notification_status) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
