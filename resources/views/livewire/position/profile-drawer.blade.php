@php
    $employee = $this->employee;
    $position = $this->position;
    $manager = $position?->directManager;
    $managerEmployee = $manager?->activeContracts?->first()?->employee;
    $reports = $this->directReports;
@endphp

{{-- Alpine owns the drawer: every card dispatches the browser event
     'open-profile', which opens the drawer on the spot and asks the server for
     that employee. The server's copy only renders once its employeeId matches
     the one just requested — until then a spinner shows, so the previous
     employee is never left on screen under a new click. --}}
<div x-data="{
        show: false,
        requestedId: null,
        get ready() {
            return this.requestedId !== null && this.$wire.employeeId === this.requestedId;
        },
        open(employeeId) {
            this.requestedId = Number(employeeId);
            this.show = true;

            if (! this.ready) {
                this.$wire.openProfile(this.requestedId);
            }
        },
     }"
     x-cloak
     x-show="show"
     x-effect="document.body.classList.toggle('overflow-hidden', show)"
     x-on:open-profile.window="open($event.detail.employeeId)"
     x-on:livewire:navigating.window="show = false; document.body.classList.remove('overflow-hidden')"
     x-on:keydown.escape.window="show = false"
     class="fixed inset-0 z-50">

    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-secondary-dark/40 backdrop-blur-[1px]"
         x-show="show" x-transition.opacity
         x-on:click="show = false"></div>

    {{-- Panel: right drawer on desktop, bottom sheet on mobile --}}
    <div class="absolute inset-x-0 bottom-0 flex max-h-[92dvh] min-h-0 flex-col rounded-t-3xl bg-white shadow-e3
                sm:inset-y-0 sm:end-0 sm:start-auto sm:h-dvh sm:max-h-none sm:w-[28rem] sm:rounded-none"
         x-show="show"
         x-on:wheel.stop
         x-on:touchmove.stop
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-y-full sm:translate-y-0 sm:translate-x-full sm:rtl:-translate-x-full"
         x-transition:enter-end="translate-y-0 sm:translate-x-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="translate-y-0 sm:translate-x-0"
         x-transition:leave-end="translate-y-full sm:translate-y-0 sm:translate-x-full sm:rtl:-translate-x-full">

        @if($employee)
        <div class="flex min-h-0 flex-1 flex-col" x-show="ready">
            {{-- Header --}}
            <div class="relative px-6 pt-6 pb-4 overflow-hidden text-white shrink-0 bg-gradient-to-b from-secondary-dark to-secondary-light">

                <button type="button" x-on:click="show = false"
                        class="absolute z-20 flex items-center justify-center transition rounded-surface end-4 top-4 size-8 text-white/80 hover:bg-white/10 hover:text-white">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/>
                    </svg>
                </button>

                <div class="relative flex flex-col items-center text-center">
                    <img src="{{ $employee->avatar_url }}" alt="{{ $employee->name }}"
                         class="object-cover rounded-full shadow-e3 size-20 ring-4 ring-white/30 sm:size-24">
                    <h2 class="mt-3 text-h4 font-extrabold tracking-tight">{{ $employee->name }}</h2>
                    @if($position)
                        <p class="mt-1 text-body font-semibold text-white/80">{{ $position->title }}</p>
                    @endif
                    @if($employee->is_board_member)
                        <span class="mt-2 rounded-full bg-primary px-3 py-1 text-caption font-bold uppercase tracking-wide">
                            @lang('general.board-member')
                        </span>
                    @endif
                </div>
            </div>

            {{-- Body --}}
            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain scroll-smooth px-4 py-4 [scrollbar-gutter:stable] sm:px-6">
                <div class="pb-4 space-y-4">
                {{-- Org placement --}}
                @if($position)
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach([
                            ['label' => trans_choice('general.program', 1), 'value' => $position->program?->name],
                            ['label' => trans_choice('general.department', 1), 'value' => $position->department?->name],
                            ['label' => trans_choice('general.center', 1), 'value' => $position->center?->name],
                            ['label' => trans_choice('general.city', 1), 'value' => $position->city?->name ?? $employee->city?->name],
                        ] as $item)
                            <div class="px-3 py-2 border border-neutral-100 rounded-surface bg-secondary-soft/5">
                                <div class="text-small font-bold uppercase tracking-wide text-secondary-light">{{ $item['label'] }}</div>
                                <div class="mt-1 truncate text-body font-semibold text-secondary-dark">{{ $item['value'] ?? __('general.not-specified') }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Contact --}}
                @php($contacts = collect([
                    ['icon' => 'phone', 'value' => $employee->work_phone, 'href' => 'tel:'],
                    ['icon' => 'mail', 'value' => $employee->work_email, 'href' => 'mailto:'],
                ])->filter(fn ($c) => filled($c['value'])))
                @if($contacts->isNotEmpty())
                    <div>
                        <h3 class="mb-2 text-small font-bold tracking-wide uppercase text-secondary-light">@lang('general.contact')</h3>
                        <div class="space-y-2">
                            @foreach($contacts as $contact)
                                <a href="{{ $contact['href'].$contact['value'] }}"
                                   class="flex items-center gap-2 rounded-surface border border-neutral-100 px-3 py-2 text-body font-medium text-secondary-dark transition hover:border-secondary-light hover:bg-secondary-soft">
                                    <span class="flex items-center justify-center rounded-surface size-7 shrink-0 bg-secondary-soft/10 text-primary">
                                        @if($contact['icon'] === 'mail')
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                                        @else
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2 5a2 2 0 0 1 2-2h2l2 5-2 1a12 12 0 0 0 5 5l1-2 5 2v2a2 2 0 0 1-2 2A16 16 0 0 1 2 5Z"/></svg>
                                        @endif
                                    </span>
                                    <span class="truncate" dir="ltr">{{ $contact['value'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Manager --}}
                @if($managerEmployee)
                    <div>
                        <h3 class="mb-2 text-small font-bold tracking-wide uppercase text-secondary-light">@lang('general.direct-manager')</h3>
                        <button type="button" wire:click="$dispatch('open-profile', { employeeId: {{ $managerEmployee->id }} })"
                                class="flex items-center w-full gap-3 px-3 py-2 transition border border-neutral-100 rounded-surface text-start hover:border-secondary-light hover:bg-secondary-soft">
                            <img src="{{ $managerEmployee->avatar_url }}" alt="" class="object-cover rounded-full size-9">
                            <div class="min-w-0">
                                <div class="text-body font-bold truncate text-secondary-dark">{{ $managerEmployee->name }}</div>
                                <div class="text-small truncate text-secondary-light">{{ $manager->title }}</div>
                            </div>
                        </button>
                    </div>
                @endif

                {{-- Direct reports --}}
                @if($reports->isNotEmpty())
                    <div>
                        <h3 class="flex items-center gap-2 mb-2 text-small font-bold tracking-wide uppercase text-secondary-light">
                            @lang('general.direct-reports')
                            <span class="rounded-full bg-neutral-100 px-2 py-1 text-caption font-bold text-secondary-medium">{{ \App\Models\Position::headcount($reports) }}</span>
                        </h3>
                        <div class="space-y-2">
                            @foreach($reports as $report)
                                @forelse($report->activeContracts->pluck('employee')->filter()->unique('id') as $rEmployee)
                                    <button type="button" wire:click="$dispatch('open-profile', { employeeId: {{ $rEmployee->id }} })"
                                            class="flex items-center w-full gap-3 px-3 py-2 transition border border-neutral-100 rounded-surface text-start hover:border-secondary-light hover:bg-secondary-soft">
                                        <img src="{{ $rEmployee->avatar_url }}" alt="" class="object-cover rounded-full size-9">
                                        <div class="min-w-0">
                                            <div class="text-body font-bold truncate text-secondary-dark">{{ $rEmployee->name }}</div>
                                            <div class="text-small truncate text-secondary-light">{{ $report->title }}</div>
                                        </div>
                                    </button>
                                @empty
                                    <div class="flex items-center gap-3 px-3 py-2 border border-neutral-200 border-dashed rounded-surface text-secondary-light">
                                        <span class="flex items-center justify-center border border-neutral-300 border-dashed rounded-full size-9"><x-icons.user class="size-4"/></span>
                                        <div class="min-w-0">
                                            <div class="text-body font-semibold truncate">@lang('general.vacant-position')</div>
                                            <div class="text-small truncate">{{ $report->title }}</div>
                                        </div>
                                    </div>
                                @endforelse
                            @endforeach
                        </div>
                    </div>
                @endif

                </div>
            </div>

            {{-- Footer action stays visible while the details scroll. --}}
            @if(filled($employee->username))
                <div class="shrink-0 border-t border-neutral-100 bg-white/95 px-4 py-4 shadow-[0_-12px_24px_-18px_rgba(88,89,91,0.45)] backdrop-blur sm:px-6">
                    <a href="{{ route('employees.show', ['employee' => $employee->username]) }}" wire:navigate
                       class="flex items-center justify-center gap-2 rounded-surface bg-primary px-4 py-2 text-body font-semibold text-white transition hover:bg-primary focus:outline-none focus:ring-2 focus:ring-secondary-light focus:ring-offset-2">
                        @lang('general.view-full-profile')
                        <svg class="size-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                    </a>
                </div>
            @endif
        </div>
        @endif

        {{-- Shown while the requested employee is still loading. --}}
        <div class="flex items-center justify-center flex-1 p-8" x-show="! ready">
            <x-icons.spinner class="size-6 animate-spin text-primary" />
        </div>
    </div>
</div>
