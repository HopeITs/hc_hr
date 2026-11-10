@php
    use App\Enums\LeaveRequestStatus;
@endphp
<x-slot:title>
    {{ __('general.my-leaves') }}
</x-slot:title>

<div>
    {{-- Title and New request share a row while they fit; on a narrow phone the button wraps below the title. --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <livewire:layout.breadcrumbs
            :title="__('general.my-leaves')"
            :crumbs="[]"
            :showButton="true"
        />

        {{-- The page's own primary action (also the guided tour's anchor); the bottom nav's "+" reaches the same
             dialog through ?new=1. Offered only to roles that may create; create() authorizes again on the server. --}}
        @if(auth()->user()->hasActivePermission('leaves.create'))
            <div class="shrink-0">
                <x-primary-button wire:click="create" data-tour="leaves.request">
                    @lang('leaves.modal.new-title')
                </x-primary-button>
            </div>
        @endif
    </div>

    <livewire:employee.leave.birthday-holiday-card />

    @if($loadFailed)
        <x-ui.load-error class="mb-6" :message="__('states.balances-unavailable')" />
    @elseif($availableLeaves !== null && $availableLeaves->isNotEmpty())
        <div data-tour="leaves.balances" class="grid
            grid-cols-1
            sm:grid-cols-2
            lg:grid-cols-3
            xl:grid-cols-5
            gap-6
            mb-6">
            @foreach($availableLeaves as $availableLeave)
                <x-leave-card
                    class="{{ $availableLeave->isAnnual() ? 'col-span-1 sm:col-span-2' : 'col-span-1' }}"
                    :title="$availableLeave->leaveType->name"
                    :total="$availableLeave->deserved_formatted"
                    :remaining="$availableLeave->remaining"
                    :total-days="$availableLeave->intTotal"
                    :remaining-days="$availableLeave->intRemaining"
                    :is-annual="$availableLeave->isAnnual()"
                    :used="$availableLeave->used_formatted"
                    :pending="formatDuration($availableLeave->pending_days , $availableLeave->pending_hours)"
                />
            @endforeach
        </div>
    @else
        <div class="rounded-surface shadow-e2 mb-6 text-center font-semibold text-ink-2 p-4" role="status">
            @lang('general.no-available-data')
        </div>

    @endif

    {{-- Result of the last send/update (A3): the real status and who acts next.
         Stays until hidden, unlike the toast, so it can be read at any pace. --}}
    @php
        $submittedLeave = $this->submittedLeave;
    @endphp
    @if($submittedLeave)
        <section wire:key="submitted-leave-{{ $submittedLeave->id }}-{{ $submittedKind }}"
                 x-data="{ open: true }" x-show="open"
                 aria-labelledby="submitted-leave-title"
                 data-testid="leave-submitted-result"
                 class="mb-6 rounded-surface border border-s-4 border-line-soft border-s-ink bg-card p-4 shadow-e2">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1 basis-56 space-y-2">
                    <h2 id="submitted-leave-title" class="flex flex-wrap items-center gap-2 font-semibold text-ink">
                        <span>{{ __('leaves.submit.'.($submittedKind === 'updated' ? 'updated' : 'sent').'-title') }}</span>
                        <span><span class="sr-only">@lang('general.status'): </span><x-status :status="LeaveRequestStatus::of($submittedLeave->status)" /></span>
                    </h2>
                    <p class="break-words text-body text-ink">
                        <span class="font-medium">@lang('leaves.next-step.title'):</span>
                        {{ app(\App\Services\LeaveRequestNextStep::class)->describe($submittedLeave) }}
                    </p>
                </div>
                <div class="ms-auto flex shrink-0 items-center gap-2">
                    <x-button variant="outline" size="sm"
                            wire:click="openDetails({{ $submittedLeave->id }})">
                        @lang('leaves.submit.view-details')
                    </x-button>
                    <button type="button"
                            x-on:click="open = false"
                            aria-label="{{ __('leaves.submit.dismiss') }}"
                            class="grid size-11 place-items-center rounded-control text-ink-3 hover:bg-hover hover:text-ink">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M6 6l12 12M18 6L6 18"/>
                        </svg>
                    </button>
                </div>
            </div>
        </section>
    @endif

    <x-tabs-list data-tour="leaves.filters">
        <x-tabs-list-item
            :active="$statusFilter === 'all'" active-tab="all">
            {{ __('general.all') }} ({{ $counts['all'] ?? 0 }})
        </x-tabs-list-item>

        <x-tabs-list-item
            :active="$statusFilter === (string) LeaveRequestStatus::Pending"
            :active-tab="LeaveRequestStatus::Pending">
            {{ LeaveRequestStatus::getLocalizedName(0)['value'] }} ({{ $counts['pending'] ?? 0 }})
        </x-tabs-list-item>

        <x-tabs-list-item
            :active="$statusFilter === (string) LeaveRequestStatus::Accepted"
            :active-tab="LeaveRequestStatus::Accepted">
            {{ LeaveRequestStatus::getLocalizedName(1)['value'] }} ({{ $counts['accepted'] ?? 0 }})
        </x-tabs-list-item>

        <x-tabs-list-item
            :active="$statusFilter === (string) LeaveRequestStatus::Rejected"
            :active-tab="LeaveRequestStatus::Rejected">
            {{ LeaveRequestStatus::getLocalizedName(2)['value'] }} ({{ $counts['rejected'] ?? 0 }})
        </x-tabs-list-item>
    </x-tabs-list>

    {{-- Table --}}
    <div class="mt-4" data-tour="leaves.table">
        <livewire:employee.leave.my-leave-request-table :statusFilter="$statusFilter" key="table-{{ $statusFilter }}"/>
    </div>

    <x-modal.dialog
        max-width="4xl"
        id="leaveDetailsModal"
        :title="__('general.leave-details')"
        wire:model.defer="detailsModal"
        wire:ignore.self>
        @php
            $selectedLeave = $this->selectedLeave;
        @endphp

        @if($selectedLeave)
            <div class="space-y-6">
                <x-leave.decision-summary :leave-request="$selectedLeave" />

                {{-- Who acts next, for every status (A3). --}}
                <div class="rounded-xl border border-line-soft bg-soft p-4" data-testid="leave-next-step">
                    <h3 class="text-small text-ink-2">@lang('leaves.next-step.title')</h3>
                    <p class="mt-1 break-words font-semibold text-ink">{{ app(\App\Services\LeaveRequestNextStep::class)->describe($selectedLeave) }}</p>
                </div>

                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">{{ trans_choice('general.leave-type', 1) }}</dt>
                        <dd class="mt-1 font-semibold text-ink">{{ $selectedLeave->leaveBalance->leaveType->name }}</dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">@lang('general.type')</dt>
                        <dd class="mt-1 font-semibold text-ink">{{ __('leaves.unit.'.($selectedLeave->type === 'hour' ? 'hour' : 'day')) }}</dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">@lang('general.during-period')</dt>
                        <dd class="mt-1 font-semibold text-ink">
                            @if($selectedLeave->type === 'hour')
                                <bdi dir="ltr">{{ $selectedLeave->start_at->format('d-m-Y, H:i') }}</bdi>
                                <span aria-hidden="true">{{ __('leaves.range-separator') }}</span>
                                <bdi dir="ltr">{{ $selectedLeave->end_at->format('H:i') }}</bdi>
                            @else
                                <bdi dir="ltr">{{ $selectedLeave->start_at->format('d-m-Y') }}</bdi>
                                <span aria-hidden="true">{{ __('leaves.range-separator') }}</span>
                                <bdi dir="ltr">{{ $selectedLeave->end_at->format('d-m-Y') }}</bdi>
                            @endif
                        </dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">@lang('general.duration')</dt>
                        <dd class="mt-1 font-semibold text-ink">{{ $selectedLeave->duration }}</dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4 sm:col-span-2">
                        <dt class="text-small text-ink-2">@lang('general.alternative-employee')</dt>
                        <dd class="mt-1 break-words font-semibold text-ink"><bdi>{{ $selectedLeave->alternativeEmployee?->name ?? '—' }}</bdi></dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4 sm:col-span-2">
                        <dt class="text-small text-ink-2">@lang('general.reason')</dt>
                        <dd class="mt-1 whitespace-pre-line break-words text-ink">{{ $selectedLeave->reason }}</dd>
                    </div>
                </dl>

                <div class="grid">
                    @include('livewire.employee.leave.partials.hr-notes', ['leaveRequest' => $selectedLeave])
                </div>

                <div class="flex justify-end">
                    <x-button variant="quiet" wire:click="closeDetails">
                        @lang('general.cancel')
                    </x-button>
                </div>
            </div>
        @endif
    </x-modal.dialog>


    <x-modal.dialog
        max-width="4xl"
        id="showModal"
        :title="$modalTitle"
        wire:model.defer="showModal"
        close-action="requestClose"
        wire:ignore.self
        class="flex items-center justify-center px-2">
        @php
            // The guided form (category → subtype → dates → summary → details). What is offered comes
            // from LeaveEligibility (leaveChoices); every value still goes through the form's validation.
            $choices = $showModal ? $this->leaveChoices : ['categories' => [], 'notice' => null];
            $active = $showModal ? $this->activeCategory : null;
            $activeKey = $active['key'] ?? null;
            $chosen = filled($form->leave_balance_id);
            $isAnnual = $activeKey === 'annual' && $chosen;
            $hasSubtypeStep = $active !== null && ($activeKey === 'annual' || count($active['options']) > 1);
            $impact = $showModal ? $this->leaveImpact : null;
            $ready = ($impact['ready'] ?? false) === true;
            $chosenOption = $active ? collect($active['options'])->first(fn ($o) => (string) $o['balance_id'] === (string) $form->leave_balance_id) : null;
            $subtypeLabel = $isAnnual
                ? __('leaves.request.subtype.'.($form->type === 'hour' ? 'hourly' : 'daily'))
                : ($hasSubtypeStep ? ($chosenOption['label'] ?? null) : null);

            // Step states: the first unfinished visible step is the current one.
            $done = [
                'category' => $active !== null,
                'subtype' => $chosen,
                'dates' => $ready,
                'details' => mb_strlen(trim((string) $form->reason)) >= 3,
                'summary' => $ready && ($impact['errors'] ?? []) === [],
            ];
            $current = collect($done)->filter(fn ($isDone, $step) => $step !== 'subtype' || $hasSubtypeStep)
                ->search(false, true);
            $stateOf = fn (string $step) => $done[$step] ? 'done' : ($current === $step ? 'current' : 'todo');
            // Numbered here, not with ++ in the tag: Blade evaluates a component's
            // attribute expressions more than once, which skipped numbers.
            $visibleSteps = array_values(array_filter([
                'category',
                $hasSubtypeStep ? 'subtype' : null,
                $chosen ? 'dates' : null,
                $chosen ? 'details' : null,
                $chosen && $impact !== null ? 'summary' : null,
            ]));
            $numberOf = fn (string $step) => array_search($step, $visibleSteps, true) + 1;
        @endphp
        {{-- Phones (M1): the sheet itself scrolls, so there is no nested scroll area; sm and up keeps the capped panel. --}}
        <div data-csb-exclude class="thin-scroll w-full sheet:flex-1 sheet:flex sheet:flex-col bg-card rounded-surface px-0 py-0 dialog:max-h-[60dvh] dialog:overflow-y-auto dialog:px-6 dialog:py-4 dialog:md:px-10 dialog:md:py-6">
            {{-- Enter in a field takes the same path as Save (there is no save()). --}}
            <form wire:submit.prevent="confirmSave" class="space-y-8 sheet:flex-1 sheet:flex sheet:flex-col" data-testid="leave-request-form">
                <x-forms.errors-validation-list/>

                {{-- 1. Category: only what the employee may request now. --}}
                <x-leave.request-step :number="$numberOf('category')" id="leave-step-category" :title="__('leaves.request.steps.category')"
                                      help="leave-units" :state="$stateOf('category')" wire:key="leave-step-category-{{ $form->id ?? 'new' }}">
                    @if($choices['categories'] === [])
                        <p class="rounded-control border border-dashed border-line bg-soft px-3 py-3 text-small text-ink-2" role="status">
                            @lang('leaves.request.none-available')
                        </p>
                    @else
                        <div class="grid grid-cols-2 gap-2 sm:gap-3 md:grid-cols-3 lg:grid-cols-4" role="radiogroup"
                             aria-labelledby="leave-step-category-title" data-testid="leave-categories">
                            @foreach($choices['categories'] as $category)
                                <label class="relative block cursor-pointer" wire:key="leave-category-{{ $category['key'] }}">
                                    <input type="radio" class="peer sr-only" name="leave_category" id="leave-category-{{ $category['key'] }}"
                                           value="{{ $category['key'] }}" wire:model.live="leaveCategory"
                                           @checked($activeKey === $category['key'])
                                           @if($category['note']) aria-describedby="leave-category-{{ $category['key'] }}-note" @endif>
                                    <span class="hc-choice flex h-full min-h-24 flex-col gap-1 p-3">
                                        <span class="flex items-start justify-between gap-2">
                                            <span class="grid size-9 place-items-center rounded-round bg-soft text-ink">
                                                <x-ic :name="$category['icon']" class="size-5" />
                                            </span>
                                            @if($activeKey === $category['key'])
                                                <x-ic name="check-circle" class="size-5 text-ink" />
                                            @endif
                                        </span>
                                        <span class="font-bold leading-snug text-ink">{{ $category['label'] }}</span>
                                        <span class="text-caption text-ink-2 num">{{ $category['left'] }}</span>
                                        @if($category['note'])
                                            <span id="leave-category-{{ $category['key'] }}-note" class="text-caption text-ink-2">{{ $category['note'] }}</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    @if($choices['notice'])
                        <p class="mt-3 flex items-start gap-2 text-small text-ink-2" data-testid="leave-unpaid-notice">
                            <x-ic name="info" class="mt-0.5 size-4" />
                            <span>{{ $choices['notice'] }}</span>
                        </p>
                    @endif
                    <x-error-span>{{ $errors->first('form.leave_balance_id') }}</x-error-span>
                </x-leave.request-step>

                {{-- 2. Subtype: annual asks day or hour (form.type); sick, bereavement and maternity name
                     their balance. A category with a single subtype skips this step. --}}
                @if($hasSubtypeStep)
                    <x-leave.request-step :number="$numberOf('subtype')" id="leave-step-subtype" :help="$activeKey === 'annual' ? 'leave-units' : null"
                                          :title="__('leaves.request.steps.'.($activeKey === 'annual' ? 'unit' : 'subtype'))"
                                          :state="$stateOf('subtype')" wire:key="leave-step-subtype-{{ $activeKey }}">
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 sm:gap-3" role="radiogroup" aria-labelledby="leave-step-subtype-title">
                            @if($activeKey === 'annual')
                                @foreach(['day' => ['daily', 'calendar'], 'hour' => ['hourly', 'clock']] as $unit => [$labelKey, $icon])
                                    <label class="relative block cursor-pointer" wire:key="leave-unit-{{ $unit }}">
                                        <input type="radio" class="peer sr-only" name="form.type" id="radio-formtype-{{ $unit }}"
                                               value="{{ $unit }}" wire:model.live="form.type" @checked($form->type === $unit)>
                                        <span class="hc-choice flex h-full min-h-16 items-center gap-3 p-3">
                                            <span class="grid size-9 shrink-0 place-items-center rounded-round bg-soft text-ink">
                                                <x-ic :name="$icon" class="size-5" />
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block font-bold text-ink">@lang('leaves.request.subtype.'.$labelKey)</span>
                                                <span class="block text-caption text-ink-2">@lang('leaves.request.subtype.'.$labelKey.'-hint')</span>
                                            </span>
                                            @if($form->type === $unit)
                                                <x-ic name="check-circle" class="size-5 text-ink" />
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            @else
                                @foreach($active['options'] as $option)
                                    @php $selected = (string) $form->leave_balance_id === (string) $option['balance_id']; @endphp
                                    <label @class(['relative block', 'cursor-pointer' => ! $option['disabled'], 'cursor-not-allowed' => $option['disabled']])
                                           wire:key="leave-subtype-{{ $option['balance_id'] }}">
                                        <input type="radio" class="peer sr-only" name="form.leave_balance_id" id="leave-subtype-{{ $option['balance_id'] }}"
                                               value="{{ $option['balance_id'] }}" wire:model.live="form.leave_balance_id"
                                               @checked($selected) @disabled($option['disabled'])
                                               @if($option['reason']) aria-describedby="leave-subtype-{{ $option['balance_id'] }}-reason" @endif>
                                        <span @class([
                                                'hc-choice flex h-full min-h-16 items-start gap-3 p-3',
                                                'border-dashed bg-card' => $option['disabled'],
                                            ])>
                                            <span class="min-w-0 flex-1">
                                                <span @class(['block font-bold', 'text-ink' => ! $option['disabled'], 'text-ink-2' => $option['disabled']])>{{ $option['label'] }}</span>
                                                @if($option['disabled'])
                                                    <span class="mt-0.5 flex items-center gap-1 text-caption font-medium text-ink-2">
                                                        <x-ic name="ban" class="size-3.5" />@lang('leaves.request.unavailable')
                                                    </span>
                                                    <span id="leave-subtype-{{ $option['balance_id'] }}-reason" class="mt-1 block text-caption text-ink-2">{{ $option['reason'] }}</span>
                                                @else
                                                    <span class="block text-caption text-ink-2 num">{{ $option['left'] }}</span>
                                                @endif
                                            </span>
                                            @if($selected)
                                                <x-ic name="check-circle" class="size-5 text-ink" />
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            @endif
                        </div>
                        <x-error-span>{{ $errors->first('form.type') }}</x-error-span>
                    </x-leave.request-step>
                @endif

                @if($chosen)
                    {{-- 3. Dates: whole days, or one date and its hours (annual only). --}}
                    <x-leave.request-step :number="$numberOf('dates')" id="leave-step-dates" :title="__('leaves.request.steps.dates')"
                                          :state="$stateOf('dates')" wire:key="leave-step-dates-{{ $isAnnual && $form->type === 'hour' ? 'hour' : 'day' }}">
                        @if($isAnnual && $form->type === 'hour')
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
                                <div class="col-span-2 sm:col-span-1">
                                    <x-forms.input-label for="leave_date" required>{{__('general.leave-date')}}</x-forms.input-label>
                                    <x-forms.date-input id="leave_date" wire:model.live="form.leave_date" :label="__('general.leave-date')"/>
                                    <x-error-span>{{ $errors->first('form.leave_date') }}</x-error-span>
                                </div>
                                <div>
                                    <x-forms.input-label for="start_time" required>{{__('general.start-time')}} (24)</x-forms.input-label>
                                    <x-forms.time-picker id="start_time" wire-model="form.start_hour" format="HH:mm" min="09:00" max="21:00" :live="true" />
                                    <x-error-span>{{ $errors->first('form.start_hour') }}</x-error-span>
                                </div>
                                <div>
                                    <x-forms.input-label for="end_time" required>{{__('general.end-time')}} (24)</x-forms.input-label>
                                    <x-forms.time-picker id="end_time" wire-model="form.end_hour" format="HH:mm" min="09:00" max="21:00" :live="true" />
                                    <x-error-span>{{ $errors->first('form.end_hour') }}</x-error-span>
                                </div>
                            </div>
                        @else
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4">
                                <div>
                                    <x-forms.input-label for="start_at" required>{{__('general.start-date')}}</x-forms.input-label>
                                    <x-forms.date-input id="start_at" wire:model.live="form.start_at" :label="__('general.start-date')"/>
                                    <x-error-span>{{ $errors->first('form.start_at') }}</x-error-span>
                                </div>
                                <div>
                                    <x-forms.input-label for="end_at" required>{{__('general.end-date')}}</x-forms.input-label>
                                    <x-forms.date-input id="end_at" wire:model.live="form.end_at" :label="__('general.end-date')"/>
                                    <x-error-span>{{ $errors->first('form.end_at') }}</x-error-span>
                                </div>
                            </div>
                        @endif

                        {{-- The computed duration, priced on the server (the same quote as the summary). --}}
                        <p class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-small text-ink-2" data-testid="leave-duration">
                            <x-ic name="clock" class="size-4" />
                            @if($ready)
                                <span>@lang('leaves.request.duration'):</span>
                                <strong class="font-bold text-ink"><bdi>{{ $impact['requested'] }}</bdi></strong>
                            @else
                                <span>@lang('leaves.request.duration-pending')</span>
                            @endif
                        </p>

                        {{-- D-7: December leave only as an exception. Offered only
                             when the typed period touches December. --}}
                        @if($form->periodTouchesDecember())
                            <div class="mt-4 rounded-control border-[1.5px] border-ink bg-card px-3 py-2" wire:key="leave-exceptional">
                                <label for="is_exceptional" class="flex min-h-11 cursor-pointer items-start gap-3">
                                    <input type="checkbox" id="is_exceptional" wire:model.live="form.is_exceptional"
                                           aria-describedby="is_exceptional-hint"
                                           class="hc-check mt-1 rounded-inset">
                                    <span>
                                        <span class="block font-medium text-ink">@lang('leave_quote.exceptional.label')</span>
                                        <span id="is_exceptional-hint" class="block text-small text-ink">@lang('leave_quote.exceptional.hint')</span>
                                    </span>
                                </label>
                                <x-error-span>{{ $errors->first('form.is_exceptional') }}</x-error-span>
                            </div>
                        @endif
                    </x-leave.request-step>

                    {{-- 4. Reason and cover. --}}
                    <x-leave.request-step :number="$numberOf('details')" id="leave-step-details" :title="__('leaves.request.steps.details')"
                                          :state="$stateOf('details')" wire:key="leave-step-details">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div class="md:col-span-2">
                                <x-forms.input-label for="reason" required>{{__('general.reason')}}</x-forms.input-label>
                                <x-forms.input id="reason" wire:model="form.reason" required examples="examples.leave_reason"
                                    :hint="__('examples.hints.leave_reason')" :hint-always="false"/>
                                <x-error-span>{{ $errors->first('form.reason') }}</x-error-span>
                            </div>
                            <div class="md:col-span-2" wire:key="container-alt-employee-{{ $form->leaveRequest ? 'edit' : 'new' }}">
                                <x-forms.input-label for="alternative_employee">{{__('general.alternative-employee')}}</x-forms.input-label>
                                <x-forms.select2-input wire:model="form.alternative_employee" id="alternative_employee">
                                    <option
                                        class="text-ink-3" @disabled($form->leaveRequest !== null) @selected($form->leaveRequest === null)>
                                        @lang('general.select')
                                    </option>
                                    @foreach($alternativeEmployees as $alternativeEmployee)
                                        <option value="{{$alternativeEmployee->id}}">{{$alternativeEmployee->name}}</option>
                                    @endforeach
                                </x-forms.select2-input>
                                <x-error-span>{{ $errors->first('form.alternative_employee') }}</x-error-span>
                            </div>
                        </div>
                    </x-leave.request-step>

                    {{-- 5. Summary, last: the live impact (A3), priced on the server on each change, for the chosen balance only. --}}
                    @if($impact !== null)
                        <x-leave.request-step :number="$numberOf('summary')" id="leave-step-summary" :title="__('leaves.request.steps.summary')"
                                              :state="$stateOf('summary')" wire:key="leave-impact">
                            @include('livewire.employee.leave.partials.impact-summary', [
                                'impact' => $impact,
                                'context' => [
                                    'category' => $active['label'] ?? null,
                                    'icon' => $active['icon'] ?? null,
                                    'subtype' => $subtypeLabel,
                                    'period' => $this->requestPeriod,
                                ],
                            ])
                        </x-leave.request-step>
                    @endif
                @endif

                {{-- Phones (M1): Submit/Cancel stay pinned to the bottom of the sheet, above the safe area, while
                     the form scrolls; the modal root's scroll padding keeps a focused field clear of this bar. --}}
                <x-modal.actions data-testid="leave-form-actions">
                    {{-- Disabled while saving is feedback only; duplicates are
                         stopped on the server (LeaveRequestForm::store). --}}
                    <x-primary-button
                        type="button"
                        wire:click="confirmSave"
                        wire:target="confirmSave,processSave"
                        class="!w-auto">
                        {{ $form->id ? __('leaves.modal.save-changes') : __('leaves.modal.submit') }}
                    </x-primary-button>

                    {{-- Closes locally, like Escape and the header close: no
                         navigation, no reload, nothing submitted. --}}
                    <x-button variant="quiet" :loading="false" x-on:click="close()">
                        @lang('general.cancel')
                    </x-button>
                </x-modal.actions>
            </form>
        </div>
    </x-modal.dialog>
</div>
