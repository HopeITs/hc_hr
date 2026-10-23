@props(['leaveRequest'])

@php
    $status = \App\Enums\LeaveRequestStatus::getLocalizedName($leaveRequest->status);
    $statusClasses = match ($leaveRequest->status) {
        \App\Enums\LeaveRequestStatus::Accepted => 'bg-neutral-100 text-neutral-800 ring-neutral-200',
        \App\Enums\LeaveRequestStatus::Rejected => 'bg-neutral-100 text-primary-hover ring-neutral-200',
        default => 'bg-neutral-100 text-neutral-800 ring-neutral-200',
    };
@endphp

<div class="space-y-4">
    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="rounded-xl border border-neutral-200 bg-neutral-50 p-4">
            <dt class="text-sm text-neutral-500">@lang('general.status')</dt>
            <dd class="mt-1">
                <span class="inline-flex rounded-full px-3 py-1 text-sm font-semibold ring-1 ring-inset {{ $statusClasses }}">
                    {{ $status['value'] }}
                </span>
            </dd>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-neutral-50 p-4">
            <dt class="text-sm text-neutral-500">@lang('general.request_date')</dt>
            <dd class="mt-1 font-semibold text-neutral-800">
                <bdi dir="ltr">{{ $leaveRequest->created_at?->format('d-m-Y H:i') ?? '—' }}</bdi>
            </dd>
        </div>

        @if ($leaveRequest->approved_at)
            <div class="rounded-xl border border-neutral-200 bg-neutral-50 p-4">
                <dt class="text-sm text-neutral-500">@lang('general.decision-date')</dt>
                <dd class="mt-1 font-semibold text-neutral-800">
                    <bdi dir="ltr">{{ $leaveRequest->approved_at->format('d-m-Y H:i') }}</bdi>
                </dd>
            </div>
        @endif
    </dl>

    @if ($leaveRequest->status === \App\Enums\LeaveRequestStatus::Rejected)
        <div class="rounded-2xl border border-neutral-200 bg-neutral-50 p-5" role="alert">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-primary-hover">
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 16ZM8.22 7.22a.75.75 0 0 1 1.06 0L10 7.94l.72-.72a.75.75 0 1 1 1.06 1.06l-.72.72.72.72a.75.75 0 1 1-1.06 1.06L10 10.06l-.72.72a.75.75 0 0 1-1.06-1.06L8.94 9l-.72-.72a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                    </svg>
                </span>

                <div class="min-w-0">
                    <h3 class="font-semibold text-primary-hover">@lang('general.reject-reason')</h3>
                    <p class="mt-1 whitespace-pre-line break-words text-sm leading-6 text-primary-hover">
                        {{ $leaveRequest->reject_reason ?: '—' }}
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
