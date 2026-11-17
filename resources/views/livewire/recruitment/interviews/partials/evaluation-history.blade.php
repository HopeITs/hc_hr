{{-- An evaluation's revisions, newest first. Only for viewers allowed to see the evaluation itself. --}}
<ol class="mt-2 space-y-3">
    @foreach ($revisions as $revision)
        <li class="border-s-2 border-secondary-soft ps-3" wire:key="revision-{{ $revision->id }}">
            <p class="font-medium">
                {{ __('recruitment-applicant.evaluations.actions.'.$revision->action) }}
                <span class="font-normal text-secondary-medium">
                    &middot; v{{ $revision->version }}
                    &middot; {{ $revision->changedBy?->profile?->name ?? $revision->changedBy?->email ?? '—' }}
                    &middot; {{ $revision->changed_at?->copy()->setTimezone($timezone ?? config('app.timezone'))->format('Y-m-d H:i') }}
                </span>
            </p>
            @if ($revision->reason)
                <p class="mt-1 text-secondary-medium">{{ __('recruitment-applicant.evaluations.reason') }}: {{ $revision->reason }}</p>
            @endif
            @include('livewire.recruitment.interviews.partials.evaluation-content', ['evaluation' => $revision])
        </li>
    @endforeach
</ol>
