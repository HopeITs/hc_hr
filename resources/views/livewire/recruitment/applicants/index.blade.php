<x-slot:title>{{ __('recruitment-applicant.applicants') }}</x-slot:title>

@php
    use App\Enums\RecruitmentApplicationStatus;

    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
    $panel = 'rounded-surface border border-neutral-200 bg-white shadow-e1';
    $eyebrow = 'text-caption font-semibold uppercase tracking-wider text-secondary-medium';
    // One chip recipe for every quick filter on the page: grey selection, never a red fill.
    $chip = fn (bool $on) => 'inline-flex py-2 cursor-pointer items-center gap-2 rounded-control border px-3 text-body transition duration-150 motion-reduce:transition-none '.$focus.' '
        .($on ? 'border-neutral-300 bg-selection font-semibold text-secondary-dark' : 'border-neutral-200 bg-white text-secondary-dark hover:border-neutral-300');
    $chipCount = fn (bool $on) => 'inline-flex min-w-5 items-center justify-center rounded-full px-1.5 text-caption font-semibold tabular-nums '
        .($on ? 'bg-white text-secondary-dark' : 'bg-neutral-100 text-secondary-medium');

    // The pipeline, in the order an application moves through it.
    $pipeline = [
        RecruitmentApplicationStatus::Submitted,
        RecruitmentApplicationStatus::Shortlisted,
        RecruitmentApplicationStatus::InterviewScheduled,
        RecruitmentApplicationStatus::Interviewed,
        RecruitmentApplicationStatus::Finalist,
        RecruitmentApplicationStatus::Selected,
    ];
    $stageLabel = fn ($status) => $status === RecruitmentApplicationStatus::Finalist
        ? __('recruitment-applicant.statuses.finalist')
        : __('recruitment-applicant.statuses.'.$status);
@endphp

<div class="recruitment-applicants-page space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <livewire:layout.breadcrumbs :title="__('recruitment-applicant.applicants')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment-applicant.applicants')]]" />
            {{-- Where focus returns after a bulk action; the visible title is the breadcrumb above. --}}
            <span id="applicants-title" tabindex="-1" class="sr-only scroll-mt-24">{{ __('recruitment-applicant.applicants') }}</span>
        </div>

        {{-- The campaign is the widest lens on the page, so it sits with the title rather than inside a filter card. --}}
        <div class="w-full sm:w-72">
            <label for="applicant-campaign-filter" class="mb-1 block text-caption font-semibold text-secondary-dark">{{ __('recruitment-applicant.filters.campaign') }}</label>
            <select id="applicant-campaign-filter" wire:model.live="activeCampaignId"
                    class="min-h-11 w-full cursor-pointer rounded-control border border-neutral-300 bg-white ps-3 pe-8 text-body text-neutral-900 shadow-e1 focus:border-primary focus:ring-primary sm:min-h-10">
                <option value="">{{ __('recruitment-applicant.filters.all_campaigns') }}</option>
                @foreach ($campaignOptions as $campaignId => $campaignLabel)
                    <option value="{{ $campaignId }}">{{ $campaignLabel }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Review stage as a pipeline: each step is a filter and shows how many applicants are standing on it. --}}
    <section aria-labelledby="review-stage-title" class="{{ $panel }} p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="review-stage-title" class="{{ $eyebrow }}">{{ __('recruitment-applicant.filters.review_stage') }}</h2>
            <p class="text-small text-secondary-medium">{{ __('recruitment-applicant.filters.review_stage_hint') }}</p>
        </div>

        <div role="group" aria-label="{{ __('recruitment-applicant.filters.review_stage') }}"
             class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-7">
            @php $allOn = $activeStatus === null; @endphp
            <button type="button" wire:click="filterByStatus(null)" aria-pressed="{{ $allOn ? 'true' : 'false' }}"
                    @class([
                        'group relative flex min-h-16 cursor-pointer flex-col items-start justify-between gap-1 rounded-control border px-3 py-2.5 text-start transition duration-150 motion-reduce:transition-none',
                        $focus,
                        'border-neutral-300 bg-selection' => $allOn,
                        'border-neutral-200 bg-white hover:border-neutral-300' => ! $allOn,
                    ])>
                <span class="text-h3 font-semibold tabular-nums text-neutral-900">{{ $allCount }}</span>
                <span class="text-small {{ $allOn ? 'font-semibold text-secondary-dark' : 'text-secondary-medium' }}">{{ __('recruitment-applicant.filters.all') }}</span>
            </button>

            @foreach ($pipeline as $status)
                @php
                    $isActive = $activeStatus === $status;
                    $count = (int) ($counts[$status] ?? 0);
                @endphp
                <button type="button" wire:click="filterByStatus(@js($status))" aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                        wire:key="stage-{{ $status }}"
                        @class([
                            'group relative flex min-h-16 cursor-pointer flex-col items-start justify-between gap-1 rounded-control border px-3 py-2.5 text-start transition duration-150 motion-reduce:transition-none',
                            $focus,
                            'border-neutral-300 bg-selection' => $isActive,
                            'border-neutral-200 bg-white hover:border-neutral-300' => ! $isActive,
                        ])>
                    <span class="flex w-full items-center justify-between gap-2">
                        <span @class(['text-h3 font-semibold tabular-nums', 'text-neutral-900' => $count > 0, 'text-secondary-light' => $count === 0])>{{ $count }}</span>
                        @unless ($loop->last)
                            {{-- The arrow says "then": stages read as a flow, not as a set of unrelated tags. --}}
                            <flux:icon.chevron-right variant="micro" class="size-4 text-secondary-light rtl:rotate-180 max-xl:hidden" aria-hidden="true" />
                        @endunless
                    </span>
                    <span class="text-small {{ $isActive ? 'font-semibold text-secondary-dark' : 'text-secondary-medium' }}">{{ $stageLabel($status) }}</span>
                </button>
            @endforeach
        </div>
    </section>

    {{-- Campaign lenses: votes and evaluations only mean something inside one campaign. --}}
    @if ($endorsementCounts !== null || ($mayViewEvaluationDashboard && $evaluationCounts !== null))
        <div class="grid gap-4 lg:grid-cols-2">
            @if ($endorsementCounts !== null)
                <section aria-labelledby="endorsement-title" class="{{ $panel }} p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 id="endorsement-title" class="{{ $eyebrow }}">{{ __('recruitment-applicant.endorsement.groups_label') }}</h2>
                            <p class="mt-1 text-small text-secondary-medium">{{ __('recruitment-applicant.endorsement.groups_hint') }}</p>
                        </div>
                        @if ($activeEndorsementGroup !== null)
                            <button type="button" wire:click="filterByEndorsementGroup(null)"
                                    class="inline-flex py-2 shrink-0 cursor-pointer items-center rounded-control px-2 text-body font-semibold text-secondary-dark hover:bg-selection {{ $focus }}">
                                {{ __('recruitment-applicant.endorsement.clear_group') }}
                            </button>
                        @endif
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach (['preferred', 'alternative', 'unvoted'] as $group)
                            @php $isActive = $activeEndorsementGroup === $group; @endphp
                            <button type="button" wire:click="filterByEndorsementGroup(@js($group))" aria-pressed="{{ $isActive ? 'true' : 'false' }}" class="{{ $chip($isActive) }}">
                                <span aria-hidden="true" @class([
                                    'size-2 shrink-0 rounded-full',
                                    'bg-neutral-900' => $group === 'preferred',
                                    'bg-neutral-500' => $group === 'alternative',
                                    'border border-neutral-400 bg-white' => $group === 'unvoted',
                                ])></span>
                                {{ __('recruitment-applicant.endorsement.group_'.$group) }}
                                <span class="{{ $chipCount($isActive) }}">{{ $endorsementCounts[$group] ?? 0 }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($mayViewEvaluationDashboard && $evaluationCounts !== null)
                <section aria-labelledby="evaluation-title" class="{{ $panel }} p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 id="evaluation-title" class="{{ $eyebrow }}">{{ __('recruitment-applicant.dashboard.title') }}</h2>
                            <p class="mt-1 text-small text-secondary-medium">{{ __('recruitment-applicant.dashboard.hint') }}</p>
                        </div>
                        @if ($activeEvaluationRecommendation !== null)
                            <button type="button" wire:click="filterByEvaluationRecommendation(null)"
                                    class="inline-flex py-2 shrink-0 cursor-pointer items-center rounded-control px-2 text-body font-semibold text-secondary-dark hover:bg-selection {{ $focus }}">
                                {{ __('recruitment-applicant.dashboard.clear') }}
                            </button>
                        @endif
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($evaluationCounts as $recommendation => $count)
                            @php
                                $isActive = $activeEvaluationRecommendation === $recommendation;
                                $label = $recommendation === 'none'
                                    ? __('recruitment-applicant.dashboard.not_evaluated')
                                    : __('recruitment-applicant.evaluations.recommendations.'.$recommendation);
                            @endphp
                            <button type="button" wire:click="filterByEvaluationRecommendation(@js($recommendation))" aria-pressed="{{ $isActive ? 'true' : 'false' }}" class="{{ $chip($isActive) }}">
                                {{ $label }}
                                <span class="{{ $chipCount($isActive) }}">{{ $count }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    @endif

    @if ($finalistCount !== null && $finalistCount > 0)
        {{-- The one place on the page that asks for a decision, so it gets the page's only red button. --}}
        <section aria-labelledby="finalists-title" class="{{ $panel }} flex flex-wrap items-center justify-between gap-4 p-5">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-secondary-dark" aria-hidden="true">
                    <flux:icon.trophy variant="mini" class="size-5" />
                </span>
                <div class="min-w-0">
                    <h2 id="finalists-title" class="text-h4 font-semibold text-neutral-900">{{ __('recruitment-applicant.finalists.title') }}</h2>
                    <p class="mt-0.5 text-small text-secondary-medium">
                        {{ trans_choice('recruitment-applicant.finalists.count', $finalistCount, ['count' => $finalistCount]) }}
                        {{ $finalistCount === 1 ? __('recruitment-applicant.finalists.single_hint') : __('recruitment-applicant.finalists.comparison_hint') }}
                    </p>
                </div>
            </div>
            @if ($finalistCount === 1 && $mayFinalize)
                <x-primary-button type="button" wire:click="finalizeSingleCandidate" wire:loading.attr="disabled" wire:target="finalizeSingleCandidate">
                    {{ __('recruitment-applicant.finalists.proceed_offer') }}
                </x-primary-button>
            @endif
        </section>
    @endif

    <livewire:recruitment.applicants.bulk-tags />

    <livewire:recruitment.applicants.bulk-review-participants />

    <livewire:recruitment.applicants.bulk-reject />

    <div class="applicants-table">
        <livewire:recruitment.applicants.application-table
            :stage-status="$activeStatus"
            :campaign-filter="$activeCampaignId"
            :endorsement-group="$activeEndorsementGroup"
            :evaluation-recommendation="$activeEvaluationRecommendation"
        />
    </div>

    <livewire:recruitment.applicants.tag-manager />
</div>
