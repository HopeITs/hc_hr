{{--
    HR's notes and objections on a leave request (C3, DEC-05 revision G5).
    Shown in the request details, which only the requester, their manager and
    HR may open (LeaveRequestPolicy::view). Receives $leaveRequest.
--}}
@php($hrNotes = $leaveRequest->notes()->with('author:id,first_name_en,first_name_ar,last_name_en,last_name_ar')->get())
@if ($hrNotes->isNotEmpty())
    <div class="col-span-full" data-testid="leave-hr-notes">
        <p class="text-small text-ink-2">{{ __('leaves.review.notes-title') }}</p>
        <ul class="mt-1 flex flex-col gap-2">
            @foreach ($hrNotes as $note)
                <li class="rounded-control border border-line-soft bg-soft px-3 py-2">
                    <p class="text-caption text-ink-2">
                        <span @class(['font-semibold', 'text-primary' => $note->kind === 'objection'])>{{ __('leaves.review.kind.'.$note->kind) }}</span>
                        · <bdi>{{ $note->author?->name ?? __('general.na') }}</bdi>
                        · <bdi dir="ltr">{{ $note->created_at->format('d-m-Y, H:i') }}</bdi>
                    </p>
                    <p class="mt-1 whitespace-pre-line break-words text-ink">{{ $note->body }}</p>
                </li>
            @endforeach
        </ul>
    </div>
@endif
