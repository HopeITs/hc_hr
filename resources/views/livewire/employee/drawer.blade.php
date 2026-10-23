{{--
    Read-only employee drawer (B4). Opened by the browser event `employee-drawer`
    ({ id }) that the list's name button / row click dispatches. Escape, the close
    button and the backdrop close it; focus goes back to the control that opened it.
--}}
<div x-data="{
        trigger: null,
        close() {
            const t = this.trigger;
            $wire.close().then(() => t && t.isConnected && t.focus());
        },
    }"
    x-on:employee-drawer.window="trigger = (document.activeElement && document.activeElement !== document.body) ? document.activeElement : [...document.querySelectorAll(`[data-employee-open='${$event.detail.id}']`)].find(el => el.offsetParent !== null) ?? null; $wire.open($event.detail.id)">
    @if($employee)
        @php
            $contract = $employee->primaryContract();
            $position = $contract?->position?->title;
            $role = role_label($employee->account?->myRole());
            $email = $employee->account?->email;
            $titleId = 'employee-drawer-title-'.$employee->id;
            $fields = [
                [trans_choice('general.city', 1), $employee->city?->name],
                [__('general.work_email'), $email, 'ltr'],
                [__('general.join-at'), $employee->joined_at?->copy()->locale(app()->getLocale())->translatedFormat('d M Y')],
                [__('general.years-of-service'), $employee->years_of_service_formatted],
            ];
        @endphp
        <div class="fixed inset-0 z-50" wire:key="employee-drawer-{{ $employee->id }}">
            <div class="absolute inset-0 bg-secondary-dark/40 backdrop-blur-[1px]" x-on:click="close()" aria-hidden="true"></div>
            {{-- Same panel as the org chart profile: right drawer on desktop, bottom sheet on mobile. --}}
            <div role="dialog" aria-modal="true" aria-labelledby="{{ $titleId }}"
                x-trap.noscroll="true"
                x-on:keydown.escape.stop="close()"
                class="absolute inset-x-0 bottom-0 flex max-h-[92dvh] min-h-0 flex-col rounded-t-3xl bg-white shadow-e3 sm:inset-y-0 sm:end-0 sm:start-auto sm:h-dvh sm:max-h-none sm:w-[28rem] sm:rounded-none">
                <div class="relative shrink-0 overflow-hidden bg-gradient-to-b from-secondary-dark to-secondary-light px-6 pb-4 pt-6 text-white">
                    <button type="button" x-on:click="close()"
                        aria-label="{{ __('general.emp-drawer-close') }}"
                        class="absolute end-4 top-4 z-20 flex size-8 items-center justify-center rounded-surface text-white/80 transition hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                        <svg class="size-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                    <div class="relative flex flex-col items-center text-center">
                        <img src="{{ $employee->avatar_url }}" alt="" class="size-20 max-w-none rounded-full object-cover shadow-e3 ring-4 ring-white/30 sm:size-24" />
                        <h2 id="{{ $titleId }}" class="mt-3 break-words text-h4 font-extrabold tracking-tight"><bdi>{{ $employee->name }}</bdi></h2>
                        @if($position)<p class="mt-1 break-words text-body font-semibold text-white/80"><bdi>{{ $position }}</bdi></p>@endif
                        <div class="mt-2 flex flex-wrap items-center justify-center gap-2">
                            @include('livewire.employee.partials.status', ['employee' => $employee])
                            @if($employee->is_board_member)
                                <span class="rounded-full bg-primary px-3 py-1 text-caption font-bold uppercase tracking-wide">{{ __('general.board-member') }}</span>
                            @endif
                            @if($role)
                                <span class="rounded-full bg-white/15 px-3 py-1 text-caption font-semibold"><bdi>{{ $role }}</bdi></span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain px-4 py-4 [scrollbar-gutter:stable] sm:px-6">
                    @if($pendingRequests && $pendingRequests->isNotEmpty())
                        <div class="rounded-surface border border-primary/30 bg-primary/5 px-3 py-2">
                            <h3 class="flex items-center gap-2 text-small font-bold uppercase tracking-wide text-primary">
                                {{ __('employees.drawer.pending-requests') }}
                                <span class="rounded-full bg-primary px-2 py-0.5 text-caption font-bold text-white">{{ $pendingRequests->count() }}</span>
                            </h3>
                            <ul class="mt-2 space-y-1 text-body text-secondary-dark">
                                @foreach($pendingRequests as $request)
                                    <li class="flex flex-wrap justify-between gap-x-3">
                                        <span><bdi>{{ $request->leaveType?->name ?? __('employees.drawer.leave-request') }}</bdi></span>
                                        <span class="tabular-nums text-secondary-medium"><bdi>{{ $request->start_at?->locale(app()->getLocale())->translatedFormat('d M Y') }}</bdi></span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach($fields as $field)
                            @continue(blank($field[1]) || ($field[2] ?? null) === 'ltr')
                            <div class="rounded-surface border border-neutral-100 bg-secondary-soft/5 px-3 py-2">
                                <dt class="text-small font-bold uppercase tracking-wide text-secondary-light">{{ $field[0] }}</dt>
                                <dd class="mt-1 break-words text-body font-semibold text-secondary-dark"><bdi>{{ $field[1] }}</bdi></dd>
                            </div>
                        @endforeach
                    </dl>

                    @foreach($assignments as $row)
                        @php($pos = $row['position'])
                        <div class="rounded-surface border border-neutral-100 p-3">
                            <h3 class="text-small font-bold uppercase tracking-wide text-secondary-light">
                                {{ $assignments->count() > 1 ? __('employees.drawer.position-n', ['n' => $loop->iteration]) : __('general.position') }}
                            </h3>
                            <p class="mt-1 break-words text-body font-bold text-secondary-dark"><bdi>{{ $pos->title }}</bdi></p>
                            <p class="break-words text-small text-secondary-medium"><bdi>{{ collect([$pos->center?->name, $pos->department?->name])->filter()->implode(' · ') }}</bdi></p>

                            <div class="mt-3 text-small text-secondary-light">{{ __('general.direct_manager') }}</div>
                            @if($row['manager'])
                                <div class="flex items-center gap-2 text-body font-semibold text-secondary-dark">
                                    <img src="{{ $row['manager']->avatar_url }}" alt="" class="size-7 max-w-none rounded-full object-cover" />
                                    <span class="truncate"><bdi>{{ $row['manager']->name }}</bdi></span>
                                </div>
                            @else
                                <div class="text-body text-secondary-medium">—</div>
                            @endif

                            @if($row['reports']->isNotEmpty())
                                <div class="mt-3 flex items-center gap-2 text-small text-secondary-light">
                                    {{ __('general.direct-reports') }}
                                    <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-caption font-bold text-secondary-medium">{{ $row['reports']->count() }}</span>
                                </div>
                                <ul class="mt-1 space-y-1">
                                    @foreach($row['reports'] as $report)
                                        <li class="flex items-center gap-2 text-body text-secondary-dark">
                                            <img src="{{ $report->avatar_url }}" alt="" class="size-6 max-w-none rounded-full object-cover" />
                                            <span class="truncate"><bdi>{{ $report->name }}</bdi></span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach

                    @if($email)
                        <div>
                            <h3 class="mb-2 text-small font-bold uppercase tracking-wide text-secondary-light">{{ __('general.contact') }}</h3>
                            <a href="mailto:{{ $email }}"
                               class="flex items-center gap-2 rounded-surface border border-neutral-100 px-3 py-2 text-body font-medium text-secondary-dark transition hover:border-secondary-light hover:bg-secondary-soft">
                                <span class="flex size-7 shrink-0 items-center justify-center rounded-surface bg-secondary-soft/10 text-primary">
                                    <svg class="size-4" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                                </span>
                                <span class="truncate" dir="ltr">{{ $email }}</span>
                            </a>
                        </div>
                    @endif
                </div>

                @if($employee->username)
                    <div class="shrink-0 border-t border-neutral-100 bg-white/95 px-4 py-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[0_-12px_24px_-18px_rgba(15,23,42,0.45)] backdrop-blur sm:px-6">
                        <a href="{{ route('employees.show', ['employee' => $employee->username]) }}" wire:navigate
                            class="flex items-center justify-center gap-2 rounded-surface bg-primary px-4 py-2 text-body font-semibold text-white transition focus:outline-none focus:ring-2 focus:ring-secondary-light focus:ring-offset-2">
                            {{ __('general.view-full-profile') }}
                            <svg class="size-4 rtl:rotate-180" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                        </a>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
