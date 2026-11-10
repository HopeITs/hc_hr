@php
    $notSpecified = __('general.not-specified');
@endphp
<x-slot:title>
    {{ $employee->name }}
</x-slot:title>
@php
    $currency = [0 => $notSpecified, 1 => 'SYP', 2 => 'USD', 3 => 'EUR'];
    // Month names follow the page language; callers isolate the result with <bdi>.
    $formatDate = fn($date) => $date ? \Carbon\Carbon::parse($date)->locale(app()->getLocale())->translatedFormat('d M Y') : $notSpecified;
    // Stored values for course organizations, labelled in the page language.
    $courseOrganizations = [
        'Hope Center' => __('employees.courses.organization-hope-center'),
        'Personal' => __('employees.courses.organization-personal'),
        'Other' => __('employees.courses.organization-other'),
    ];
    $positionName = $currentPosition?->title ?? $notSpecified;
    $workEmail = $employee->account?->email;
    $directManagerName = $directManager?->name;
@endphp

<div class="min-h-screen bg-soft px-3 py-3 text-ink sm:px-6 sm:py-4 lg:px-8">
    <div class="mx-auto max-w-7xl space-y-3 sm:space-y-4">
        <section data-tour="profile.header"
            class="relative overflow-hidden rounded-surface border border-line-soft bg-card shadow-e1 sm:rounded-surface">
            <div class="absolute inset-x-0 top-0 h-1 bg-ink sm:h-1.5"></div>

            <div
                class="relative flex flex-col items-start gap-3 p-4 pt-4 sm:gap-6 sm:p-8 min-[900px]:flex-row min-[900px]:items-center">
                <div class="shrink-0 self-start">
                    @if ($canPropose)
                        <div class="mb-2">@include('livewire.employee.partials.proposal-slot', ['section' => 'photo'])</div>
                    @endif
                    <img src="{{ $employee->avatar_url }}" alt="{{ $employee->name }}"
                        class="h-20 w-20 rounded-surface border-2 border-card object-fit shadow-e2 ring-1 ring-line-soft sm:h-40 sm:w-40 sm:rounded-surface sm:border-4 sm:shadow-e3">
                </div>

                <div class="min-w-0 w-full flex-1 min-[900px]:basis-[calc(100%-11.5rem)]">
                    <div class="mb-2 flex flex-wrap items-center gap-2 sm:mb-3 sm:gap-2">
                        @if ($isOnLeave)
                            <x-status tone="work" icon="calendar" :label="__('general.on-leave')" />
                        @else
                            <x-status :status="$employee->is_active ? \App\Enums\EmployeeStatus::Active : \App\Enums\EmployeeStatus::Inactive" />
                        @endif
                        @if ($employee->is_board_member)
                            <x-tag variant="soft">@lang('general.board-member')</x-tag>
                        @endif
                    </div>

                    <h1 class="whitespace-normal break-normal py-1 text-h4 font-bold leading-normal tracking-tight text-ink sm:text-h1 sm:leading-normal">
                        <bdi>{{ $employee->name }}</bdi></h1>
                    <p class="mt-1 whitespace-normal break-words py-1 text-body font-semibold leading-normal text-ink-2 sm:mt-2 sm:text-h4 sm:leading-normal">{{ $positionName }}
                    </p>

                    <div class="mt-4 flex flex-row flex-wrap gap-x-3 gap-y-2 text-small text-ink-2 sm:gap-x-4 sm:text-body">
                        @if ($currentPosition?->department)
                            <span class="inline-flex items-center gap-2"><x-icons.homepage
                                    class="h-3.5 w-3.5 text-ink-3 sm:h-4 sm:w-4" /> {{ $currentPosition->department->name }}</span>
                        @endif
                        @if ($currentPosition?->program)
                            <span class="inline-flex items-center gap-2"><x-icons.contracts
                                    class="h-3.5 w-3.5 text-ink-3 sm:h-4 sm:w-4" /> {{ $currentPosition->program->name }}</span>
                        @endif
                        @if ($currentPosition?->city)
                            <span class="inline-flex items-center gap-2"><x-icons.map-pin
                                    class="h-3.5 w-3.5 text-ink-3 sm:h-4 sm:w-4" /> {{ $currentPosition->city->name }}</span>
                        @endif
                    </div>

                    <div class="mt-4 flex min-w-0 flex-row flex-wrap gap-2 sm:gap-3" data-tour="profile.actions">
                        @if ($canEdit)
                            <x-button size="sm" :href="route('employees.edit', $employee)" :title="__('general.edit')" :aria-label="__('general.edit')">
                                <x-icons.edit class="h-4 w-4" /> <span class="hidden min-[701px]:inline">@lang('general.edit')</span>
                            </x-button>
                        @endif
                        @if ($workEmail)
                            <x-button variant="outline" size="sm" :href="'mailto:'.$workEmail" :wire:navigate="false" :title="__('general.email')" :aria-label="__('general.email')" class="min-w-0">
                                <x-icons.envelope class="h-4 w-4 shrink-0" /> <span
                                    class="hidden min-w-0 truncate min-[701px]:inline">@lang('general.email')</span>
                            </x-button>
                        @endif
                        @if ($employee->work_phone)
                            <x-button variant="outline" size="sm" :href="'tel:'.$employee->work_phone" :wire:navigate="false" :title="__('general.work-phone')" :aria-label="__('general.work-phone')">
                                <x-icons.phone class="h-4 w-4 shrink-0" /> <span class="hidden min-[701px]:inline">@lang('general.work-phone')</span>
                            </x-button>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        {{-- Length of service comes from the career history, so it is only
             there for viewers who get that tier ($yearsOfService is null otherwise). --}}
        @php
            $summary = array_values(array_filter([
                $yearsOfService !== null ? [__('general.years-of-service'), $yearsOfService, 'icons.clock'] : null,
                [trans_choice('general.department', 1), $currentPosition?->department?->name ?? $notSpecified, 'icons.homepage'],
                [trans_choice('general.center', 1), $currentPosition?->center?->name ?? $notSpecified, 'icons.map-pin'],
                [__('general.contract-status'), $currentContract ? __('general.active') : __('general.inactive'), 'icons.contracts'],
            ]));
        @endphp
        <section data-tour="profile.summary" @class(['grid grid-cols-2 gap-2 sm:gap-3', 'lg:grid-cols-4' => count($summary) === 4, 'lg:grid-cols-3' => count($summary) === 3])>
            @foreach ($summary as [$label, $value, $icon])
                <div class="rounded-surface border border-line-soft bg-card p-3 shadow-e1 sm:rounded-surface sm:p-4">
                    <div
                        class="mb-2 inline-flex h-7 w-7 items-center justify-center rounded-surface bg-surface text-ink sm:mb-3 sm:h-9 sm:w-9 sm:rounded-surface">
                        <x-dynamic-component :component="$icon" class="block h-3.5 w-3.5 sm:h-5 sm:w-5" />
                    </div>
                    <p
                        class="truncate text-caption font-semibold uppercase text-ink-2 sm:text-small">
                        {{ $label }}</p>
                    <p class="mt-1 truncate text-small font-bold text-ink sm:text-body">{{ $value }}</p>
                </div>
            @endforeach
        </section>

        <div class="grid gap-4 xl:grid-cols-3">
            <main class="space-y-4 xl:col-span-2">
                {{-- D1 timeline (profile-contract.md section 4, Q1 = B): career and badges,
                     built on the server for this viewer; a Directory-only viewer gets none. --}}
                @if ($canViewRecords)
                    <x-profile-section data-section="timeline" :title="__('employees.profile.timeline')" :subtitle="__('employees.profile.timeline-subtitle')" icon="icons.clock">
                        @if ($timeline === [])
                            <p class="text-body text-ink-2">@lang('employees.profile.timeline-empty')</p>
                        @else
                            <ol class="relative space-y-3 border-s-2 border-line-soft ps-4" data-testid="profile-timeline">
                                @foreach ($timeline as $i => $event)
                                    <li wire:key="event-{{ $i }}" data-event="{{ $event['kind'] }}" class="relative">
                                        {{-- The kind is told by its icon (and the words below), not by a coloured dot. --}}
                                        <span aria-hidden="true" class="absolute -start-[1.6rem] top-1 grid size-4 place-items-center rounded-full bg-card text-ink">
                                            <x-ic class="size-3.5" :name="match (true) {
                                                $event['kind'] === 'badge.warning' => 'warning',
                                                $event['kind'] === 'badge.reward', $event['kind'] === 'badge.salary' => 'star',
                                                default => 'dot',
                                            }" />
                                        </span>
                                        <p class="text-small text-ink-2"><bdi>{{ $formatDate($event['date']) }}</bdi></p>
                                        <p class="text-body font-bold text-ink">
                                            {{-- Event codes carry a dot, which the translator reads as nesting: the keys use a dash. --}}
                                            {{ __('employees.profile.events.'.str_replace('.', '-', $event['kind'])) }}
                                            @if ($event['title'] && ! str_starts_with($event['kind'], 'badge.'))
                                                · <span class="font-semibold">{{ $event['title'] }}</span>
                                            @endif
                                        </p>
                                        @if ($event['detail'])
                                            <p class="text-small text-ink-2">{{ $event['detail'] }}</p>
                                        @endif
                                        @if ($event['pay'] && $event['pay']['amount'])
                                            <p class="text-small font-semibold text-ink"><bdi>{{ number_format((float) $event['pay']['amount']) }} {{ $currency[$event['pay']['currency'] ?? 0] ?? '' }}</bdi></p>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </x-profile-section>
                @endif

                <x-profile-section data-tour="profile.overview" :title="__('general.employment-overview')" :subtitle="__('general.professional-information')" icon="icons.user">
                    <dl class="grid gap-4 sm:grid-cols-2">
                        @foreach ([[__('general.position'), $positionName], [trans_choice('general.job-level', 1), $currentPosition?->jobLevel?->name ?? $notSpecified], [__('general.direct-manager'), $directManagerName ?? $notSpecified], [trans_choice('general.program', 1), $currentPosition?->program?->name ?? $notSpecified], [trans_choice('general.city', 1), $currentPosition?->city?->name ?? $notSpecified]] as [$label, $value])
                            <div class="rounded-surface bg-soft p-4">
                                <dt class="text-small font-semibold text-ink-2">{{ $label }}</dt>
                                <dd class="mt-1 text-body font-bold text-ink">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-profile-section>

                @if ($canViewRecords)
                    <x-profile-section data-tour="profile.career" :title="__('general.career-history')" :subtitle="__('general.contract-details')" icon="icons.contracts">
                        <div class="space-y-4">
                            @forelse($contracts as $contract)
                                <article class="relative rounded-surface border border-line-soft p-4 ps-6">
                                    <span
                                        class="absolute inset-y-4 start-0 w-1 rounded-e-full {{ $contract->is_active && !$contract->terminated_at ? 'bg-ink' : 'bg-line' }}"></span>
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h3 class="font-bold text-ink">
                                                {{ $contract->position?->title ?? $notSpecified }}</h3>
                                            <p class="mt-1 text-body text-ink-2">
                                                {{ $contract->position?->department?->name ?? $notSpecified }}
                                                @if ($contract->contractType)
                                                    · {{ $contract->contractType->name }}
                                                @endif
                                            </p>
                                        </div>
                                        <x-status class="shrink-0" :status="$contract->is_active && ! $contract->terminated_at ? \App\Enums\ContractStatus::Active : \App\Enums\ContractStatus::Inactive" />
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-small text-ink-2">
                                        <span><bdi>{{ $formatDate($contract->start_date) }}</bdi> –
                                            <bdi>{{ $formatDate($contract->terminated_at ?? $contract->end_date) }}</bdi></span>
                                        @if ($contract->weekly_hours)
                                            <span>@lang('general.weekly-hours'): {{ $contract->weekly_hours }}</span>
                                        @endif
                                        @if ($canViewFinancial && $contract->salary)
                                            <span class="font-bold text-ink">@lang('general.salary'):
                                                <bdi>{{ number_format($contract->salary) }}
                                                {{ $currency[$contract->currency] ?? '' }}</bdi></span>
                                        @endif
                                    </div>
                                </article>
                            @empty
                                <p class="rounded-surface bg-soft p-4 text-center text-body text-ink-2">
                                    @lang('general.no-available-data')</p>
                            @endforelse
                        </div>
                    </x-profile-section>
                @endif

                @if ($canViewRecords)
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-profile-section data-section="education" :title="__('general.education')" icon="icons.education">
                        @include('livewire.employee.partials.proposal-slot', ['section' => 'education'])
                            <div class="space-y-4">
                                @forelse($employee->educations as $education)
                                    <div class="border-s-2 border-line ps-4">
                                        <h3 class="text-body font-bold text-ink">
                                            {{ $education->name ?? ($education->specialization ?? $notSpecified) }}</h3>
                                        <p class="mt-1 text-small text-ink-2">
                                            {{ \App\Enums\EducationType::getLocalizedName((int) $education->degree) }} ·
                                            {{ \App\Enums\EducationStatus::getLocalizedName((int) $education->status) }}
                                        </p>
                                        @if ($education->specialization)
                                            <p class="mt-1 text-small text-ink-2">{{ $education->specialization }}
                                            </p>
                                        @endif
                                    </div>
                                @empty
                                    <p class="text-body text-ink-2">@lang('general.no-available-data')</p>
                                @endforelse
                            </div>
                        </x-profile-section>

                        <x-profile-section data-section="experience" :title="__('general.experience')" icon="icons.experience">
                        @include('livewire.employee.partials.proposal-slot', ['section' => 'experience'])
                            <div class="space-y-4">
                                @forelse($employee->experiences as $experience)
                                    <div class="border-s-2 border-line ps-4">
                                        <h3 class="text-body font-bold text-ink">
                                            {{ $experience->job_title ?? $notSpecified }}</h3>
                                        <p class="mt-1 text-small text-ink-2">
                                            {{ $experience->company ?? $notSpecified }}</p>
                                        <p class="mt-1 text-small text-ink-2">
                                            <bdi>{{ $formatDate($experience->start_date) }}</bdi> –
                                            <bdi>{{ $formatDate($experience->end_date) }}</bdi></p>
                                    </div>
                                @empty
                                    <p class="text-body text-ink-2">@lang('general.no-available-data')</p>
                                @endforelse
                            </div>
                        </x-profile-section>
                    </div>
                @endif

                @if ($canViewPrivate)
                    <x-profile-section data-tour="profile.private" :title="__('general.personal-information')" :subtitle="__('general.private-information')" icon="icons.contact" private>
                        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ([[__('general.national-id'), $employee->national_number ?? $notSpecified], [__('general.birthday'), $formatDate($employee->actual_dob)], [__('general.birth-place'), $employee->{'pob_' . app()->getLocale()} ?? $notSpecified], [__('general.nationality'), $employee->nationality !== null ? \App\Enums\Nationality::getLocalizedName((int) $employee->nationality) : $notSpecified], [__('general.marital-status'), $employee->marital_status !== null ? \App\Enums\MaritalStatus::getLocalizedName((int) $employee->marital_status)['value'] : $notSpecified], [__('general.children'), $employee->children ?? 0], [__('general.civil-registry-office'), $employee->civil_registry_office ?? $notSpecified], [__('general.civil-registry-number'), $employee->civil_registry_number ?? $notSpecified], [__('general.address'), $employee->address ?? $notSpecified]] as [$label, $value])
                                <div class="rounded-surface bg-soft p-4">
                                    <dt class="text-small font-semibold text-ink-2">{{ $label }}</dt>
                                    <dd class="mt-1 text-body font-bold text-ink"><bdi>{{ $value }}</bdi></dd>
                                </div>
                            @endforeach
                        </dl>
                    </x-profile-section>
                @endif
            </main>

            <aside class="space-y-4">
                <x-profile-section data-section="work-contact" :title="__('general.work-contact')" icon="icons.contact">
                    @if (in_array('work-contact', $editable, true))
                        <x-slot:action>
                            <x-button variant="outline" size="sm" wire:click="openSection('work-contact')" data-testid="edit-work-contact">
                                <x-icons.edit class="h-3.5 w-3.5" /> <span>@lang('general.edit')</span>
                                <span class="sr-only">{{ __('general.work-contact') }}</span>
                            </x-button>
                        </x-slot:action>
                    @endif
                    <div class="space-y-3 text-body">
                        @foreach ([[__('general.email'), $workEmail, 'icons.envelope', $workEmail ? 'mailto:' . $workEmail : null], [__('general.work-phone'), $employee->work_phone, 'icons.phone', $employee->work_phone ? 'tel:' . $employee->work_phone : null], ['WhatsApp', $employee->whatsapp_work, 'icons.whatsapp', null], ['Telegram', $employee->telegram_work, 'icons.telegram', null]] as [$label, $value, $icon, $href])
                            @if ($value)
                                <div class="flex min-w-0 items-center gap-3 rounded-surface bg-soft p-3">
                                    <x-dynamic-component :component="$icon"
                                        class="h-4 w-4 shrink-0 text-ink-3 sm:h-5 sm:w-5" />
                                    <div class="min-w-0">
                                        <p class="text-small text-ink-2">{{ $label }}</p>
                                        @if ($href)
                                            <a href="{{ $href }}" dir="ltr"
                                                class="block min-w-0 break-all text-start font-semibold text-ink hover:underline">{{ $value }}</a>
                                        @else<p dir="ltr" class="block min-w-0 break-all text-start font-semibold text-ink">
                                                {{ $value }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        @endforeach
                        @if (!$workEmail && !$employee->work_phone && !$employee->whatsapp_work && !$employee->telegram_work)
                            <p class="text-body text-ink-2">@lang('general.no-available-data')</p>
                        @endif
                    </div>
                </x-profile-section>
                <livewire:employee-cards.manage-digital-id :employee="$employee" :key="'digital-id-'.$employee->id" />

                @if ($canViewPrivate)
                    <x-profile-section data-section="personal-contact" :title="__('general.personal-contact')" icon="icons.phone" private>
                        @if (in_array('personal-contact', $editable, true))
                        <x-slot:action>
                            <x-button variant="outline" size="sm" wire:click="openSection('personal-contact')" data-testid="edit-personal-contact">
                                <x-icons.edit class="h-3.5 w-3.5" /> <span>@lang('general.edit')</span>
                                <span class="sr-only">{{ __('general.personal-contact') }}</span>
                            </x-button>
                        </x-slot:action>
                    @endif
                        <div class="space-y-3 text-body">
                            @foreach ([[__('general.email'), $employee->personal_email], [__('general.personal-phone'), $employee->personal_phone]] as [$label, $value])
                                <div class="rounded-surface bg-soft p-3">
                                    <p class="text-small text-ink-2">{{ $label }}</p>
                                    @if ($value)
                                        <p dir="ltr" class="mt-1 break-all text-start font-semibold text-ink">{{ $value }}</p>
                                    @else
                                        <p class="mt-1 font-semibold text-ink">{{ $notSpecified }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </x-profile-section>
                @endif

                @if ($canViewRecords)
                    <x-profile-section data-section="skills" :title="__('general.skills-and-interests')" icon="icons.statistics">
                        @if (in_array('skills', $editable, true))
                        <x-slot:action>
                            <x-button variant="outline" size="sm" wire:click="openSection('skills')" data-testid="edit-skills">
                                <x-icons.edit class="h-3.5 w-3.5" /> <span>@lang('general.edit')</span>
                                <span class="sr-only">{{ __('general.skills-and-interests') }}</span>
                            </x-button>
                        </x-slot:action>
                    @endif
                        <div class="mb-4 flex flex-wrap gap-2">
                            @forelse($skills as $skill)
                                <span
                                    class="rounded-full bg-ink px-3 py-2 text-small font-bold text-on-ink">{{ $skill }}</span>
                            @empty
                                <span class="text-body text-ink-2">@lang('general.no-available-data')</span>
                            @endforelse
                        </div>
                        @if ($interests)
                            <div class="border-t border-line-soft pt-4">
                                <p class="mb-3 text-small font-semibold text-ink-2">@lang('general.interests')</p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($interests as $interest)
                                        <span
                                            class="rounded-full bg-surface px-3 py-2 text-small font-semibold text-ink-2">{{ $interest }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </x-profile-section>
                @endif

                @if ($canViewRecords)
                    <x-profile-section data-section="courses" :title="__('general.courses')" icon="icons.courses">
                        @include('livewire.employee.partials.proposal-slot', ['section' => 'course'])
                        <div class="space-y-4">
                            @forelse($employee->courses->take(5) as $course)
                                <div class="border-s-2 border-line ps-3">
                                    <p class="text-body font-bold text-ink">{{ $course->title ?? $notSpecified }}
                                    </p>
                                    <p class="mt-1 text-small text-ink-2">
                                        {{ $course->organization ? ($courseOrganizations[$course->organization] ?? $course->organization) : ($course->granted_by ?? $notSpecified) }}</p>
                                </div>
                            @empty
                                <p class="text-body text-ink-2">@lang('general.no-available-data')</p>
                            @endforelse
                        </div>
                    </x-profile-section>
                @endif

                @if($canViewBadges || ($isSelf && $badges->isNotEmpty()))
                    <x-profile-section data-section="badges" :title="__('general.badges')" icon="icons.badges">
                        <div class="space-y-3">
                            @forelse($badges->take(5) as $badge)
                                <div class="rounded-surface bg-soft p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <p class="text-body font-bold text-ink">
                                            {{ \App\Enums\BadgeType::getLocalizedName((int) $badge->type) }}</p>
                                        <span class="shrink-0 text-small text-ink-2"><bdi>{{ $formatDate($badge->date) }}</bdi></span>
                                    </div>
                                    @if ($badge->reason)
                                        <p class="mt-2 text-small text-ink-2">{{ $badge->reason }}</p>
                                    @endif
                                </div>
                            @empty
                                <p class="text-body text-ink-2">@lang('general.no-available-data')</p>
                            @endforelse
                        </div>
                    </x-profile-section>
                @endif

                @if ($canViewPrivate && ($employee->relatives->isNotEmpty() || $canPropose))
                    <x-profile-section data-section="family" :title="__('general.family-information')" icon="icons.relatives" private>
                        @include('livewire.employee.partials.proposal-slot', ['section' => 'relative'])
                        <div class="space-y-3">
                            @foreach ($employee->relatives as $relative)
                                <div class="rounded-surface bg-soft p-3">
                                    <p class="text-body font-bold text-ink"><bdi>{{ $relative->name }}</bdi></p>
                                    <p class="mt-1 text-small text-ink-2">{{ \App\Enums\KinshipType::getLocalizedName((int) $relative->relation) }}
                                        @if ($relative->phone)
                                            · <span dir="ltr">{{ $relative->phone }}</span>
                                        @endif
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </x-profile-section>
                @endif
            </aside>
        </div>
    </div>

    {{-- D1 section editing (profile-contract.md section 5): one section, its own fields only.
         Closing with changes asks first (A5-M); a stale save keeps the entries. --}}
    @php
        $sectionTitles = ['personal-contact' => __('general.personal-contact'), 'skills' => __('general.skills-and-interests'), 'work-contact' => __('general.work-contact')];
        $sectionTitle = isset($sectionTitles[$editing]) ? __('employees.profile.edit-section', ['section' => $sectionTitles[$editing]]) : '';
    @endphp
    <x-modal.dialog id="sectionModal" :title="$sectionTitle" wire:model.defer="sectionModal" close-action="requestClose" wire:ignore.self>
        @if ($sectionModal)
            <form wire:submit.prevent="saveSection" class="space-y-4" data-testid="section-form">
                @error('section')
                    <div class="rounded-control border-s-4 border-primary bg-soft p-3 text-small text-ink" role="alert" data-testid="section-stale">
                        <p>{{ $message }}</p>
                        <x-button variant="link" size="sm" class="mt-1" wire:click="reloadSection">@lang('employees.profile.show-current')</x-button>
                    </div>
                @enderror

                @php
                    $fieldLabels = [
                        'personal_phone' => [__('general.personal-phone'), 'tel'],
                        'personal_email' => [__('general.email'), 'email'],
                        'address_ar' => [__('employees.profile.address-ar'), 'text'],
                        'address_en' => [__('employees.profile.address-en'), 'text'],
                        'skills' => [__('general.skills'), 'text'],
                        'interests' => [__('general.interests'), 'text'],
                        'work_phone' => [__('general.work-phone'), 'tel'],
                        'whatsapp_work' => ['WhatsApp', 'text'],
                        'telegram_work' => ['Telegram', 'text'],
                    ];
                @endphp
                <div class="grid gap-4 sm:grid-cols-2">
                    {{-- The section's own columns, never whatever keys the browser sent. --}}
                    @foreach (\App\Livewire\Employee\EmployeeProfile::SECTIONS[$editing] ?? [] as $field)
                        <div @class(['sm:col-span-2' => in_array($field, ['skills', 'interests', 'address_ar', 'address_en'], true)])>
                            <x-forms.input-label for="section_{{ $field }}">{{ $fieldLabels[$field][0] }}</x-forms.input-label>
                            <x-forms.input id="section_{{ $field }}" type="{{ $fieldLabels[$field][1] }}" wire:model="sectionFields.{{ $field }}"
                                :dir="in_array($field, ['personal_phone', 'personal_email', 'work_phone', 'whatsapp_work', 'telegram_work'], true) ? 'ltr' : null" />
                            <x-forms.input-error field="sectionFields.{{ $field }}" input-id="section_{{ $field }}" />
                            @if (in_array($field, ['skills', 'interests'], true))
                                <p class="mt-1 text-caption text-ink-2">@lang('employees.profile.comma-hint')</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                <x-modal.actions>
                    <x-primary-button type="submit" wire:target="saveSection" class="!w-auto">@lang('general.save')</x-primary-button>
                    <x-button variant="quiet" :loading="false" x-on:click="close()">@lang('general.cancel')</x-button>
                </x-modal.actions>
            </form>
        @endif
    </x-modal.dialog>

    @include('livewire.employee.partials.proposal-dialog')
</div>
