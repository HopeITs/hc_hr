@php
    use App\Enums\LeaveRequestStatus;
@endphp
<div
        class="w-full h-full  bg-white rounded-surface shadow-[0px_0px_25px_-14px_rgba(88,_89,_91,_0.30)] ring-1 ring-[var(--color-neutral-200)] overflow-hidden flex flex-col">
    <x-dashboard-cards.card-header :title="__('general.upcoming-leaves')" :count="$upcomingLeaves->count()">
        <x-slot:icon>
            <img class="block size-full object-contain" src="{{ asset('assets/icons/upcoming-leaves.svg') }}" alt="">
        </x-slot:icon>
    </x-dashboard-cards.card-header>

    <!-- Scrollable Content -->
    <div
            class="flex-1 overflow-y-auto px-6 pb-4 space-y-4 scrollbar-thin scrollbar-thumb-[var(--color-secondary-medium)] scrollbar-track-[var(--color-neutral-100)] hover:scrollbar-thumb-[var(--color-secondary-medium)]">
        <!-- Leave Item -->

        @forelse($upcomingLeaves as $upcomingLeave)
            <div class="pb-3  border-b border-b-secondary-soft">
                <div class="flex justify-between items-center">
                    <p class="text-body font-bold text-[#58595B]">{{$upcomingLeave->leaveBalance->contract->employee->name}}</p>
                    @activerole('HR','super-admin','general-manager','manager')
                    <span
                            class="text-caption h-5.5 w-16 font-normal tracking-wide mt-4 text-center text-white bg-{{LeaveRequestStatus::getLocalizedName($upcomingLeave->status)['color']}} px-1 py-1  rounded-full">{{LeaveRequestStatus::getLocalizedName($upcomingLeave->status)['value']}}</span>
                    @endactiverole
                </div>

                @if($upcomingLeave->type === 'day')
                    @php
                        if($upcomingLeave->start_at->isSameDay($upcomingLeave->end_at)){
                            if($upcomingLeave->start_at->isToday()){
                            $leave_date = '<span class="me-1">'.__('general.today').'</span>';
                            }elseif($upcomingLeave->start_at->isTomorrow()){
                            $leave_date = '<span class="me-1">'.__('general.tomorrow').'</span>';
                            }else{
                            $leave_date = $upcomingLeave->start_at->format('d M');}
                        }else{
                            $leave_date ='<span>' . $upcomingLeave->start_at->format('d M') .  '</span><span class="me-1"> - '.$upcomingLeave->end_at->format('d M') .'</span>';
                        }
                    @endphp
                    <p class="text-caption font-light mt-1 text-secondary-medium gap-1 flex">{!! $leave_date . ' ('.$upcomingLeave->leaveBalance->leaveType->name .') ' !!}</p>

                @else
                    @php
                        if($upcomingLeave->start_at->isToday()){
                            $leave_date = '<span class="me-1">'.__('general.today').'</span><span>' .$upcomingLeave->start_at->format('H:i') . '</span><span> - ' .$upcomingLeave->end_at->format('H:i') .'</span>' ;
                        }elseif($upcomingLeave->start_at->isTomorrow()){
                            $leave_date = '<span class="me-1">'.__('general.tomorrow').'</span><span>' .$upcomingLeave->start_at->format('H:i') . '</span><span> - ' .$upcomingLeave->end_at->format('H:i') .'</span>';
                        }else{
                            $leave_date = $upcomingLeave->start_at->format('d M').'<span>' .$upcomingLeave->start_at->format('H:i') . '</span><span> - ' .$upcomingLeave->end_at->format('H:i') .'</span>' ;
                        }

                    @endphp
                    <p class="text-caption font-light mt-1 text-secondary-medium space-x-1">{!!   $leave_date . ' ('.$upcomingLeave->leaveBalance->leaveType->name .') '  !!}
                    </p>
                @endif
            </div>
        @empty
            <div class="flex flex-col items-center justify-center  text-body font-light text-secondary-light h-full">
                <span class="text-[10.5px]">@lang('general.no-available-data')</span>
            </div>
        @endforelse

    </div>
</div>
