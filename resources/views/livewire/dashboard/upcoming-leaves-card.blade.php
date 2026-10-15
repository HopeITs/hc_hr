@php
    use App\Enums\LeaveRequestStatus;
@endphp
<div class="flex h-full w-full flex-col overflow-hidden rounded-surface bg-white ring-1 ring-neutral-200 shadow-sm">
    <x-dashboard-cards.card-header :title="__('general.upcoming-leaves')" :count="$upcomingLeaves->count()" />

    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 pb-5 pt-2 text-start scrollbar-thin scrollbar-thumb-[var(--color-secondary-light)] scrollbar-track-transparent">
        @forelse($upcomingLeaves as $upcomingLeave)
            <div wire:key="upcoming-leave-{{ $upcomingLeave->id }}" class="flex items-start gap-3">
                <div class="flex size-10 shrink-0 flex-col items-center justify-center gap-0.5 rounded-control bg-neutral-100 pt-1 text-center text-secondary-dark" aria-hidden="true">
                    <span class="block text-base font-bold leading-none">{{ $upcomingLeave->start_at->format('d') }}</span>
                    <span class="block max-w-full truncate px-0.5 text-caption font-medium leading-none">{{ $upcomingLeave->start_at->copy()->locale(app()->getLocale())->isoFormat('MMM') }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="break-words text-body font-bold leading-5 text-secondary-dark">{{ $upcomingLeave->leaveBalance->contract->employee->name }}</p>
                    <p class="mt-1 text-small font-normal leading-5 text-secondary-medium">
                        @if($upcomingLeave->start_at->isSameDay($upcomingLeave->end_at))
                            @if($upcomingLeave->start_at->isToday())
                                {{ __('general.today') }}
                            @elseif($upcomingLeave->start_at->isTomorrow())
                                {{ __('general.tomorrow') }}
                            @else
                                {{ $upcomingLeave->start_at->copy()->locale(app()->getLocale())->isoFormat('D MMM') }}
                            @endif
                        @else
                            {{ $upcomingLeave->start_at->copy()->locale(app()->getLocale())->isoFormat('D MMM') }} – {{ $upcomingLeave->end_at->copy()->locale(app()->getLocale())->isoFormat('D MMM') }}
                        @endif
                        @if($upcomingLeave->type === 'hour')
                            <bdi dir="ltr">{{ $upcomingLeave->start_at->format('H:i') }} – {{ $upcomingLeave->end_at->format('H:i') }}</bdi>
                        @endif
                        <span aria-hidden="true"> · </span>{{ $upcomingLeave->leaveBalance->leaveType->name }}
                    </p>
                </div>
                <div class="flex max-w-24 shrink-0 flex-col items-end gap-1 pt-1 text-end">
                    <span class="text-small font-semibold leading-5 text-secondary-dark">{{ $upcomingLeave->duration }}</span>
                    @activerole('HR','super-admin','general-manager','manager')
                        <span class="inline-flex items-center justify-end gap-1 text-caption font-normal leading-4 text-secondary-medium">
                            <span class="size-1.5 shrink-0 rounded-full bg-secondary-dark" aria-hidden="true"></span>
                            {{ LeaveRequestStatus::getLocalizedName($upcomingLeave->status)['value'] }}
                        </span>
                    @endactiverole
                </div>
            </div>
        @empty
            <div class="flex h-full items-center justify-center text-caption font-normal text-secondary-light">
                <span>@lang('general.no-available-data')</span>
            </div>
        @endforelse
    </div>

    @if($upcomingLeaves->count() > 3 && auth()->user()->hasActivePermission('leaves.view-all'))
        <div class="flex shrink-0 justify-center bg-white px-6 pb-2">
            <a href="{{ route('leaves.all') }}" class="inline-flex min-h-11 items-center justify-center gap-1 rounded-control px-3 text-small font-bold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                {{ __('general.view-all') }}<span aria-hidden="true" class="rtl:rotate-180">→</span>
            </a>
        </div>
    @endif
</div>
