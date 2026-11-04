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
     employee is never left on screen under a new click.

     A profile the viewer may not see (unknown, former, or no longer visible
     after a role switch) answers openProfile() with a plain 404, or drops an
     open drawer with 'profile-unavailable'. Both close the drawer with the
     same generic message and nothing about the record (access-policy.md §4.4). --}}
<div x-data="{
        show: false,
        requestedId: null,
        get ready() {
            return this.requestedId !== null && this.$wire.employeeId === this.requestedId;
        },
        init() {
            this.$wire.$intercept('openProfile', ({ onError }) => {
                onError(({ response, preventDefault }) => {
                    if (response?.status !== 404) {
                        return;
                    }

                    preventDefault();
                    this.unavailable();
                });
            });
        },
        open(employeeId) {
            this.requestedId = Number(employeeId);
            this.show = true;

            if (! this.ready) {
                this.$wire.openProfile(this.requestedId);
            }
        },
        unavailable() {
            this.show = false;
            this.requestedId = null;
            window.dispatchEvent(new CustomEvent('toast', {
                detail: { type: 'info', message: @js(__('general.no-available-data')) },
            }));
        },
     }"
     x-cloak
     x-show="show"
     x-trap.inert.noscroll="show"
     x-effect="document.body.classList.toggle('overflow-hidden', show)"
     x-on:open-profile.window="open($event.detail.employeeId)"
     x-on:profile-unavailable.window="unavailable()"
     x-on:livewire:navigating.window="show = false; document.body.classList.remove('overflow-hidden')"
     x-on:keydown.escape.window="show = false"
     class="fixed inset-0 z-50">

    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-backdrop backdrop-blur-[1px]"
         aria-hidden="true"
         x-show="show" x-transition.opacity
         x-on:click="show = false"></div>

    {{-- Panel: drawer from the inline end on tablet/desktop, bottom sheet on phones.
         Only the body scrolls, so the header and the footer action stay in reach. --}}
    <div role="dialog" aria-modal="true" aria-labelledby="profile-drawer-title" tabindex="-1"
         class="absolute inset-x-0 bottom-0 flex max-h-[92dvh] min-h-0 flex-col overflow-hidden rounded-t-3xl border-t border-line-soft bg-card shadow-e3 focus:outline-none
                sm:inset-y-0 sm:end-0 sm:start-auto sm:h-dvh sm:max-h-none sm:w-[28rem] sm:rounded-none sm:border-t-0 sm:border-s"
         x-show="show"
         x-on:wheel.stop
         x-on:touchmove.stop
         x-transition:enter="transition ease-out duration-200 motion-reduce:transition-none"
         x-transition:enter-start="translate-y-full sm:translate-y-0 sm:translate-x-full sm:rtl:-translate-x-full"
         x-transition:enter-end="translate-y-0 sm:translate-x-0"
         x-transition:leave="transition ease-in duration-150 motion-reduce:transition-none"
         x-transition:leave-start="translate-y-0 sm:translate-x-0"
         x-transition:leave-end="translate-y-full sm:translate-y-0 sm:translate-x-full sm:rtl:-translate-x-full">

        {{-- Sheet grab handle (decorative). --}}
        <div class="flex shrink-0 justify-center pt-2 sm:hidden" aria-hidden="true">
            <span class="h-1 w-10 rounded-full bg-line"></span>
        </div>

        {{-- Always rendered, so the dialog can be closed while it is still loading. --}}
        <button type="button" x-on:click="show = false" aria-label="{{ __('access.close') }}"
                class="absolute end-3 top-3 z-20 flex size-11 items-center justify-center rounded-control text-ink-2 transition hover:bg-hover hover:text-ink max-sm:top-4">
            <svg class="size-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/>
            </svg>
        </button>

        @if($employee)
        <div class="flex min-h-0 flex-1 flex-col" x-show="ready">
            {{-- Identity --}}
            <div class="flex shrink-0 items-center gap-4 border-b border-line-soft px-5 pb-5 pe-16 pt-4 sm:px-6 sm:pe-16 sm:pt-6">
                <img src="{{ $employee->avatar_url }}" alt=""
                     class="size-16 max-w-none shrink-0 rounded-full bg-soft object-cover ring-1 ring-line-soft sm:size-[4.5rem]">
                <div class="min-w-0">
                    <h2 id="profile-drawer-title" class="break-words text-h4 font-extrabold leading-snug tracking-tight text-ink text-balance">
                        <bdi>{{ $employee->name }}</bdi>
                    </h2>
                    @if($position)
                        <p class="mt-0.5 break-words text-small font-medium text-ink-2"><bdi>{{ $position->title }}</bdi></p>
                    @endif
                    @if($employee->is_board_member)
                        <x-tag class="mt-2" icon="star" :label="__('general.board-member')" />
                    @endif
                </div>
            </div>

            {{-- Body --}}
            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-5 sm:px-6">
                <div class="space-y-6">
                {{-- Org placement --}}
                @if($position)
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-4 min-[360px]:grid-cols-2">
                        @foreach([
                            ['label' => trans_choice('general.program', 1), 'value' => $position->program?->name],
                            ['label' => trans_choice('general.department', 1), 'value' => $position->department?->name],
                            ['label' => trans_choice('general.center', 1), 'value' => $position->center?->name],
                            ['label' => trans_choice('general.city', 1), 'value' => $position->city?->name ?? $employee->city?->name],
                        ] as $item)
                            <div class="min-w-0">
                                <dt class="text-caption font-bold uppercase tracking-wide text-ink-2">{{ $item['label'] }}</dt>
                                @if(filled($item['value']))
                                    <dd class="mt-1 break-words text-body font-semibold text-ink"><bdi>{{ $item['value'] }}</bdi></dd>
                                @else
                                    <dd class="mt-1 text-body text-ink-2">{{ __('general.not-specified') }}</dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>
                @endif

                {{-- Manager: who this person reports to. --}}
                @if($position)
                    <section aria-labelledby="profile-drawer-manager">
                        <h3 id="profile-drawer-manager" class="mb-2 text-caption font-bold uppercase tracking-wide text-ink-2">@lang('general.direct-manager')</h3>
                        @if($managerEmployee)
                            <button type="button" wire:click="$dispatch('open-profile', { employeeId: {{ $managerEmployee->id }} })"
                                    class="group flex w-full items-center gap-3 rounded-surface border border-line-soft bg-soft px-3 py-2.5 text-start transition hover:border-line hover:bg-hover">
                                <img src="{{ $managerEmployee->avatar_url }}" alt="" class="size-10 max-w-none shrink-0 rounded-full object-cover ring-1 ring-line-soft">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-body font-bold text-ink"><bdi>{{ $managerEmployee->name }}</bdi></div>
                                    <div class="truncate text-small text-ink-2"><bdi>{{ $manager->title }}</bdi></div>
                                </div>
                                <svg class="size-4 shrink-0 text-ink-3 transition group-hover:text-ink rtl:rotate-180" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/></svg>
                            </button>
                        @else
                            <p class="rounded-surface border border-dashed border-line px-3 py-2.5 text-small text-ink-2">{{ __('general.not-specified') }}</p>
                        @endif
                    </section>
                @endif

                {{-- Contact --}}
                @php($contacts = collect([
                    ['icon' => 'phone', 'value' => $employee->work_phone, 'href' => 'tel:'],
                    ['icon' => 'mail', 'value' => $employee->work_email, 'href' => 'mailto:'],
                ])->filter(fn ($c) => filled($c['value'])))
                @if($contacts->isNotEmpty())
                    <section>
                        <h3 class="mb-2 text-caption font-bold uppercase tracking-wide text-ink-2">@lang('general.contact')</h3>
                        <div class="space-y-1">
                            @foreach($contacts as $contact)
                                <a href="{{ $contact['href'].$contact['value'] }}"
                                   class="-mx-2 flex min-h-11 items-center gap-3 rounded-control px-2 py-1.5 text-body font-medium text-ink transition hover:bg-hover">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-control bg-soft text-ink-2">
                                        @if($contact['icon'] === 'mail')
                                            <svg class="size-4" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                                        @else
                                            <svg class="size-4" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2 5a2 2 0 0 1 2-2h2l2 5-2 1a12 12 0 0 0 5 5l1-2 5 2v2a2 2 0 0 1-2 2A16 16 0 0 1 2 5Z"/></svg>
                                        @endif
                                    </span>
                                    <span class="truncate" dir="ltr">{{ $contact['value'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Direct reports --}}
                @if($reports->isNotEmpty())
                    <section>
                        <h3 class="mb-2 flex items-center gap-2 text-caption font-bold uppercase tracking-wide text-ink-2">
                            @lang('general.direct-reports')
                            <span class="rounded-full bg-surface px-2 py-0.5 text-caption font-bold text-ink-2">{{ \App\Models\Position::headcount($reports) }}</span>
                        </h3>
                        <div class="divide-y divide-line-soft rounded-surface border border-line-soft">
                            @foreach($reports as $report)
                                @forelse($report->activeContracts->pluck('employee')->filter()->unique('id') as $rEmployee)
                                    <button type="button" wire:click="$dispatch('open-profile', { employeeId: {{ $rEmployee->id }} })"
                                            class="flex w-full items-center gap-3 px-3 py-2 text-start transition first:rounded-t-surface last:rounded-b-surface hover:bg-hover">
                                        <img src="{{ $rEmployee->avatar_url }}" alt="" class="size-8 max-w-none shrink-0 rounded-full object-cover">
                                        <div class="min-w-0">
                                            <div class="truncate text-body font-semibold text-ink"><bdi>{{ $rEmployee->name }}</bdi></div>
                                            <div class="truncate text-small text-ink-2"><bdi>{{ $report->title }}</bdi></div>
                                        </div>
                                    </button>
                                @empty
                                    <div class="flex items-center gap-3 px-3 py-2 text-ink-2">
                                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full border border-dashed border-line"><x-icons.user class="size-4"/></span>
                                        <div class="min-w-0">
                                            <div class="truncate text-body font-semibold">@lang('general.vacant-position')</div>
                                            <div class="truncate text-small"><bdi>{{ $report->title }}</bdi></div>
                                        </div>
                                    </div>
                                @endforelse
                            @endforeach
                        </div>
                    </section>
                @endif
                </div>
            </div>

            {{-- Footer action stays visible while the details scroll. --}}
            @if(filled($employee->username))
                <div class="shrink-0 border-t border-line-soft px-5 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 sm:px-6">
                    <x-button full :href="route('employees.show', ['employee' => $employee->username])">
                        @lang('general.view-full-profile')
                        <svg class="size-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                    </x-button>
                </div>
            @endif
        </div>
        @endif

        {{-- Shown while the requested employee is still loading. --}}
        <div class="flex min-h-40 flex-1 items-center justify-center p-8" x-show="! ready" role="status">
            <x-icons.spinner class="size-6 animate-spin text-ink-2" />
            <span class="sr-only">{{ __('general.a11y.loading') }}</span>
        </div>
    </div>
</div>
