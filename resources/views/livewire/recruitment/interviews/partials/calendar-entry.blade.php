{{-- One interview in the calendar: a white chip led by its status dot. Type and status are in the link text itself, not a hover-only title. --}}
@php
    use App\Enums\RecruitmentInterviewStatus;

    $cancelled = $interview->status === RecruitmentInterviewStatus::Cancelled;
    $status = __('recruitment-applicant.interviews.statuses.'.$interview->status);
    $type = __('recruitment-applicant.interviews.types.'.$interview->interview_type);
@endphp
<li wire:key="calendar-interview-{{ $interview->id }}">
    <a href="{{ route('recruitment.interviews.show', ['interviewId' => $interview->id]) }}" wire:navigate
       class="flex gap-1.5 rounded-inset border border-neutral-200 bg-white shadow-e1 transition duration-150 hover:shadow-e2 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary
              {{ $cancelled ? 'text-secondary-medium' : 'text-secondary-dark' }}
              {{ $compact ? 'items-center px-1.5 py-1 text-caption' : 'items-start px-3 py-2 text-small' }}">
        <span aria-hidden="true" class="size-2 shrink-0 rounded-full {{ RecruitmentInterviewStatus::dotClasses($interview->status) }} {{ $compact ? '' : 'mt-1' }}"></span>
        <span class="min-w-0 flex-1 {{ $compact ? 'truncate' : '' }}">
            <time datetime="{{ $interview->scheduled_at->toIso8601String() }}" class="font-semibold tabular-nums {{ $cancelled ? '' : 'text-neutral-900' }}">{{ $interview->scheduled_at->format('H:i') }}</time>
            <span class="{{ $cancelled ? 'line-through' : '' }}">{{ $interview->application?->applicant?->full_name }}</span>
            @if ($compact)
                <span class="sr-only">&middot; {{ $type }} &middot; {{ $status }}</span>
            @else
                <span class="block text-caption text-secondary-medium">{{ $type }} &middot; {{ $status }}</span>
            @endif
        </span>
    </a>
</li>
