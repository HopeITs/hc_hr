<x-slot:title>{{ __('recruitment-applicant.applicants') }}</x-slot:title>

<div class="recruitment-applicants-page space-y-6">
    <livewire:layout.breadcrumbs :title="__('recruitment-applicant.applicants')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment-applicant.applicants')]]" />
    {{-- Where focus returns after a bulk action; the visible title is the breadcrumb above. --}}
    <span id="applicants-title" tabindex="-1" class="sr-only scroll-mt-24">{{ __('recruitment-applicant.applicants') }}</span>
    <p class="-mt-4 text-body text-secondary-medium">{{ __('recruitment-applicant.applicants_hint') }}</p>

    @php
        $reviewStatuses = [
            ['status' => null, 'label' => __('recruitment-applicant.filters.all')],
            ['status' => \App\Enums\RecruitmentApplicationStatus::Submitted, 'label' => __('recruitment-applicant.statuses.submitted')],
            ['status' => \App\Enums\RecruitmentApplicationStatus::Shortlisted, 'label' => __('recruitment-applicant.statuses.shortlisted')],
            ['status' => \App\Enums\RecruitmentApplicationStatus::InterviewScheduled, 'label' => __('recruitment-applicant.statuses.interview_scheduled')],
            ['status' => \App\Enums\RecruitmentApplicationStatus::Interviewed, 'label' => __('recruitment-applicant.statuses.interviewed')],
            ['status' => \App\Enums\RecruitmentApplicationStatus::Selected, 'label' => __('recruitment-applicant.statuses.selected')],
        ];
    @endphp
    <section aria-label="{{ __('recruitment-applicant.filters.review_stage') }}" class="rounded-surface border border-neutral-200 bg-white p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="font-semibold">{{ __('recruitment-applicant.filters.review_stage') }}</h2>
                <p class="mt-1 text-sm text-secondary-medium">{{ __('recruitment-applicant.filters.review_stage_hint') }}</p>
            </div>
            <div class="w-full sm:w-72">
                <label for="applicant-campaign-filter" class="mb-1 block text-sm font-semibold text-secondary-dark">{{ __('recruitment-applicant.filters.campaign') }}</label>
                <select id="applicant-campaign-filter" wire:model.live="activeCampaignId" class="w-full cursor-pointer rounded-control border-neutral-300 bg-white text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('recruitment-applicant.filters.all_campaigns') }}</option>
                    @foreach ($campaignOptions as $campaignId => $campaignLabel)
                        <option value="{{ $campaignId }}">{{ $campaignLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex w-full flex-wrap gap-2">
                @foreach ($reviewStatuses as $stage)
                    @php
                        $status = $stage['status'];
                        $label = $stage['label'];
                        $isActive = $activeStatus === $status && $activeEndorsementGroup === null;
                        $count = $status === null ? $counts->sum() : (int) ($counts[$status] ?? 0);
                    @endphp
                    <button type="button" wire:click="filterByStatus(@js($status))"
                        class="inline-flex h-7 min-h-7 cursor-pointer items-center gap-1.5 rounded-control border px-3 py-0 text-sm font-semibold {{ $isActive ? 'border-primary bg-primary text-white' : 'border-neutral-300 bg-white text-secondary-dark hover:bg-neutral-50' }}">
                        <span>{{ $label }}</span>
                        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs {{ $isActive ? 'bg-white/20 text-white' : 'bg-neutral-100 text-secondary-dark' }}">{{ $count }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Endorsement groups — only when a campaign is selected --}}
    @if ($endorsementCounts !== null)
    @php
        $endorsementGroups = [
            ['group' => 'preferred',   'label' => __('recruitment-applicant.endorsement.group_preferred'),   'color' => 'border-primary bg-white text-primary',          'activeColor' => 'border-primary bg-primary text-white'],
            ['group' => 'alternative', 'label' => __('recruitment-applicant.endorsement.group_alternative'), 'color' => 'border-primary/50 bg-primary/5 text-primary',    'activeColor' => 'border-primary bg-primary text-white'],
            ['group' => 'unvoted',     'label' => __('recruitment-applicant.endorsement.group_unvoted'),     'color' => 'border-neutral-300 bg-white text-secondary-dark', 'activeColor' => 'border-secondary-dark bg-secondary-dark text-white'],
        ];
    @endphp
    <section aria-label="{{ __('recruitment-applicant.endorsement.groups_label') }}" class="rounded-surface border border-neutral-200 bg-white p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold">{{ __('recruitment-applicant.endorsement.groups_label') }}</h2>
                <p class="mt-1 text-sm text-secondary-medium">{{ __('recruitment-applicant.endorsement.groups_hint') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($endorsementGroups as $eg)
                    @php
                        $isActive = $activeEndorsementGroup === $eg['group'];
                        $count = $endorsementCounts[$eg['group']] ?? 0;
                    @endphp
                    <button type="button" wire:click="filterByEndorsementGroup(@js($eg['group']))"
                        class="inline-flex h-7 min-h-7 cursor-pointer items-center gap-1.5 rounded-control border px-3 py-0 text-sm font-semibold {{ $isActive ? $eg['activeColor'] : $eg['color'] }}">
                        <span>{{ $eg['label'] }}</span>
                        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs {{ $isActive ? 'bg-white/20' : 'bg-black/5' }}">{{ $count }}</span>
                    </button>
                @endforeach
                @if ($activeEndorsementGroup !== null)
                    <button type="button" wire:click="filterByEndorsementGroup(null)"
                        class="inline-flex h-7 min-h-7 cursor-pointer items-center rounded-control border border-neutral-300 bg-white px-3 py-0 text-sm text-secondary-dark hover:bg-neutral-50">
                        {{ __('recruitment-applicant.endorsement.clear_group') }}
                    </button>
                @endif
            </div>
        </div>
    </section>
    @endif

    <livewire:recruitment.applicants.bulk-tags />

    <livewire:recruitment.applicants.bulk-reject />

    <livewire:recruitment.applicants.application-table
        :stage-status="$activeStatus"
        :campaign-filter="$activeCampaignId"
        :endorsement-group="$activeEndorsementGroup"
    />

    <livewire:recruitment.applicants.tag-manager />
</div>
