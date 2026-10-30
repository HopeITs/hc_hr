@props(['leaveRequest'])

@php
    $status = \App\Enums\LeaveRequestStatus::of($leaveRequest->status);
@endphp

<div class="space-y-4">
    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="rounded-surface border border-line-soft bg-soft p-4">
            <dt class="text-small text-ink-2">@lang('general.status')</dt>
            <dd class="mt-1">
                <x-status :status="$status" />
            </dd>
        </div>

        <div class="rounded-surface border border-line-soft bg-soft p-4">
            <dt class="text-small text-ink-2">@lang('general.request_date')</dt>
            <dd class="mt-1 font-semibold text-ink">
                <bdi dir="ltr">{{ $leaveRequest->created_at?->format('d-m-Y H:i') ?? '—' }}</bdi>
            </dd>
        </div>

        @if ($leaveRequest->approved_at)
            <div class="rounded-surface border border-line-soft bg-soft p-4">
                <dt class="text-small text-ink-2">@lang('general.decision-date')</dt>
                <dd class="mt-1 font-semibold text-ink">
                    <bdi dir="ltr">{{ $leaveRequest->approved_at->format('d-m-Y H:i') }}</bdi>
                </dd>
            </div>
        @endif
    </dl>

    @if ($leaveRequest->status === \App\Enums\LeaveRequestStatus::Rejected)
        {{-- Neutral panel with a dashed edge (the "stopped" shape); the words say it was rejected. --}}
        <div class="rounded-surface border-[1.5px] border-dashed border-ink-2 bg-card p-5" role="alert">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-soft text-ink">
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 16ZM8.22 7.22a.75.75 0 0 1 1.06 0L10 7.94l.72-.72a.75.75 0 1 1 1.06 1.06l-.72.72.72.72a.75.75 0 1 1-1.06 1.06L10 10.06l-.72.72a.75.75 0 0 1-1.06-1.06L8.94 9l-.72-.72a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                    </svg>
                </span>

                <div class="min-w-0">
                    <h3 class="font-bold text-ink">@lang('general.reject-reason')</h3>
                    <p class="mt-1 whitespace-pre-line break-words text-small text-ink-2">
                        {{ $leaveRequest->reject_reason ?: '—' }}
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
