@php
    use App\Enums\HiringCampaignStatus as Status;

    $title = $campaign->vacancy?->position?->title ?? '—';
    $request = $campaign->vacancy?->createdFromRequest;
    $fmt = fn ($date) => $date?->translatedFormat('j M Y');

    // The first unfinished step is the one to look at; the rest wait for it.
    $steps = [
        'details' => true,
        'post' => $postReady,
        'form' => $formReady,
        'link' => $linkReady,
    ];
    $current = collect($steps)->search(fn ($done) => ! $done);

    $statusKey = match ($effective) {
        Status::Open => 'open',
        Status::Published => $startsInFuture ? 'scheduled' : 'published',
        Status::Closed => 'closed',
        Status::Expired => 'expired',
        Status::Disabled => 'disabled',
        default => 'draft',
    };
@endphp

<x-slot:title>{{ $title }}</x-slot:title>

<div class="space-y-6" data-campaign-page>
    <livewire:layout.breadcrumbs
        :title="__('recruitment-campaign.page.breadcrumb_title')"
        :crumbs="[
            ['label' => __('recruitment-campaign.campaigns'), 'url' => route('recruitment.campaigns.index')],
            ['label' => __('recruitment-campaign.page.campaign_number', ['id' => $campaign->id])],
        ]"
    />

    <div class="mx-auto w-full max-w-5xl space-y-6">
    {{-- Header: what this is, and the one-line state. --}}
    <section class="rounded-surface border border-secondary-soft bg-white p-5 shadow-e1 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-small font-semibold text-secondary-medium">
                    {{ __('recruitment-campaign.page.campaign_number', ['id' => $campaign->id]) }}
                    @if ($request)
                        <span aria-hidden="true">·</span>
                        <a href="{{ route('recruitment.requests.show', $request->id) }}" wire:navigate
                            class="cursor-pointer text-primary underline-offset-4 hover:underline">
                            <bdi>{{ $request->request_number }}</bdi>
                        </a>
                    @endif
                </p>
                <h1 class="mt-1 text-h3 font-bold text-secondary-dark">{{ $title }}</h1>
                <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-body text-secondary-medium">
                    <div class="flex items-center gap-2">
                        <dt class="sr-only">{{ __('recruitment-campaign.link_mode') }}</dt>
                        <svg class="size-4 text-secondary-light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 0 0-5-3.87M9 20H4v-2a4 4 0 0 1 5-3.87m6-4a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm6 2a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                        <dd>{{ __('recruitment-campaign.modes.'.$campaign->link_mode) }}</dd>
                    </div>
                    <div class="flex items-center gap-2">
                        <dt class="sr-only">{{ __('recruitment-campaign.window') }}</dt>
                        <svg class="size-4 text-secondary-light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/></svg>
                        <dd>
                            @if ($campaign->starts_at || $campaign->ends_at)
                                <bdi>{{ $fmt($campaign->starts_at) ?? __('recruitment-campaign.page.on_launch') }}</bdi>
                                <span aria-hidden="true">→</span>
                                <bdi>{{ $fmt($campaign->ends_at) ?? __('recruitment-campaign.page.no_deadline') }}</bdi>
                            @else
                                {{ __('recruitment-campaign.page.no_window') }}
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-center gap-2 [&>svg]:size-4">
                        <dt class="sr-only">{{ __('recruitment-campaign.page.applications') }}</dt>
                        <svg class="size-5 text-secondary-light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M7 3h7l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/></svg>
                        <dd>{{ trans_choice('recruitment-campaign.page.applications_count', $applicationsCount, ['count' => $applicationsCount]) }}</dd>
                    </div>
                </dl>
            </div>

            <x-recruitment.campaign-status-badge :status="$effective" :label-key="$statusKey" />
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        {{-- Preparation checklist --}}
        <ol class="mx-auto w-full max-w-5xl space-y-4" aria-label="{{ __('recruitment-campaign.page.checklist') }}">

            {{-- 1. Details --}}
            <x-recruitment.campaign-step number="1" id="step-details" :done="true"
                :title="__('recruitment-campaign.page.step_details')"
                :summary="__('recruitment-campaign.page.step_details_help')">
                @if ($editingDetails)
                    <form wire:submit="saveDetails" class="space-y-5">
                        <fieldset>
                            <legend class="text-body font-semibold text-secondary-dark">{{ __('recruitment-campaign.link_mode') }}</legend>
                            @if ($canChangeAudience)
                                <div class="mt-3 grid gap-2 sm:grid-cols-3">
                                    @foreach ($linkModes as $mode)
                                        <label wire:key="details-mode-{{ $mode }}"
                                            class="flex cursor-pointer items-start gap-3 rounded-control border p-3 transition has-[:checked]:border-primary has-[:checked]:bg-secondary-soft/30 border-secondary-light">
                                            <input type="radio" value="{{ $mode }}" wire:model="linkMode"
                                                class="mt-1 border-secondary-light text-primary focus:ring-primary">
                                            <span>
                                                <span class="block text-body font-semibold text-secondary-dark">{{ __('recruitment-campaign.modes.'.$mode) }}</span>
                                                <span class="block text-small text-secondary-medium">{{ __('recruitment-campaign.mode_hints.'.$mode) }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                <x-error-span>{{ $errors->first('linkMode') }}</x-error-span>
                            @else
                                {{-- Locked after publish: a read-only value, not dead radios that
                                     invite a click and do nothing. --}}
                                <div class="mt-2 rounded-control border border-secondary-soft bg-secondary-soft/30 p-3">
                                    <span class="block text-body font-semibold text-secondary-dark">{{ __('recruitment-campaign.modes.'.$campaign->link_mode) }}</span>
                                    <span class="block text-small text-secondary-medium">{{ __('recruitment-campaign.mode_hints.'.$campaign->link_mode) }}</span>
                                </div>
                                <p class="mt-2 text-small text-secondary-medium">{{ __('recruitment-campaign.page.audience_locked_hint') }}</p>
                            @endif
                        </fieldset>

                        <fieldset>
                            <legend class="text-body font-semibold text-neutral-800">{{ __('recruitment-campaign.age_range') }}</legend>
                            <p class="mt-1 text-small text-secondary-medium">{{ __('recruitment-campaign.age_range_hint') }}</p>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <x-forms.input-label for="minimumAge">{{ __('recruitment-campaign.minimum_age') }}</x-forms.input-label>
                                    <input id="minimumAge" type="number" min="0" max="120" inputmode="numeric" wire:model="minimumAge"
                                        class="mt-1 w-full rounded-control border-neutral-300 text-body focus:border-primary focus:ring-primary">
                                    <x-error-span>{{ $errors->first('minimumAge') }}</x-error-span>
                                </div>
                                <div>
                                    <x-forms.input-label for="maximumAge">{{ __('recruitment-campaign.maximum_age') }}</x-forms.input-label>
                                    <input id="maximumAge" type="number" min="0" max="120" inputmode="numeric" wire:model="maximumAge"
                                        class="mt-1 w-full rounded-control border-neutral-300 text-body focus:border-primary focus:ring-primary">
                                    <x-error-span>{{ $errors->first('maximumAge') }}</x-error-span>
                                </div>
                            </div>
                        </fieldset>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-forms.input-label for="startsAt">{{ __('recruitment-campaign.starts_at') }}</x-forms.input-label>
                                <input id="startsAt" type="date" wire:model="startsAt"
                                    class="mt-1 w-full rounded-control border-secondary-light text-body focus:border-primary focus:ring-primary">
                                <x-error-span>{{ $errors->first('startsAt') }}</x-error-span>
                            </div>
                            <div>
                                <x-forms.input-label for="endsAt">{{ __('recruitment-campaign.ends_at') }}</x-forms.input-label>
                                <input id="endsAt" type="date" wire:model="endsAt"
                                    class="mt-1 w-full rounded-control border-secondary-light text-body focus:border-primary focus:ring-primary">
                                <x-error-span>{{ $errors->first('endsAt') }}</x-error-span>
                            </div>
                        </div>
                        <p class="text-small text-secondary-medium">{{ __('recruitment-campaign.window_hint') }}</p>

                        <div class="flex flex-wrap gap-2">
                            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveDetails">
                                {{ __('recruitment-campaign.page.save_details') }}
                            </x-primary-button>
                            <button type="button" wire:click="cancelDetails"
                                class="cursor-pointer rounded-control border border-secondary-light bg-white px-4 py-2 text-body font-semibold text-secondary-dark transition hover:bg-secondary-soft/30">
                                {{ __('recruitment-campaign.cancel') }}
                            </button>
                        </div>
                    </form>
                @else
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-body text-secondary-medium">
                            {{ __('recruitment-campaign.modes.'.$campaign->link_mode) }}
                            <span aria-hidden="true">·</span>
                            {{ __('recruitment-campaign.mode_hints.'.$campaign->link_mode) }}
                            @if ($campaign->minimum_age !== null && $campaign->maximum_age !== null)
                                <span aria-hidden="true">·</span>
                                {{ __('recruitment-campaign.age_range_value', ['minimum' => $campaign->minimum_age, 'maximum' => $campaign->maximum_age]) }}
                            @endif
                        </p>
                        @if ($mayEdit)
                            <button type="button" wire:click="editDetails"
                                class="cursor-pointer rounded-control border border-secondary-light bg-white px-3 py-1.5 text-small font-semibold text-secondary-dark transition hover:bg-secondary-soft/30">
                                {{ __('recruitment-campaign.page.edit') }}
                            </button>
                        @endif
                    </div>
                @endif
            </x-recruitment.campaign-step>

            {{-- 2. Job post --}}
            <x-recruitment.campaign-step number="2" id="step-post" :done="$postReady" :current="$current === 'post'"
                :title="__('recruitment-campaign.page.step_post')"
                :summary="__('recruitment-campaign.page.step_post_help')">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-body text-secondary-medium">
                        @if ($postReady)
                            {{ __('recruitment-campaign.page.published_version', ['version' => $jobPost->currentVersion->version_no]) }}
                        @elseif ($jobPost)
                            {{ __('recruitment-campaign.page.draft_not_published') }}
                        @else
                            {{ __('recruitment-campaign.page.not_started') }}
                        @endif
                    </p>
                    @if ($canManageJobPost)
                        <a href="{{ route('recruitment.campaigns.job-post.edit', $campaign) }}" wire:navigate
                            @class([
                                'inline-flex cursor-pointer items-center gap-2 rounded-control px-4 py-2 text-body font-semibold transition',
                                'bg-primary text-white hover:bg-primary-hover' => $current === 'post',
                                'border border-secondary-light bg-white text-secondary-dark hover:bg-secondary-soft/30' => $current !== 'post',
                            ])>
                            {{ $jobPost ? __('recruitment-campaign.page.open_post') : __('recruitment-campaign.page.create_post') }}
                            <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
                        </a>
                    @endif
                </div>
            </x-recruitment.campaign-step>

            {{-- 3. Application form --}}
            <x-recruitment.campaign-step number="3" id="step-form" :done="$formReady" :current="$current === 'form'"
                :title="__('recruitment-campaign.page.step_form')"
                :summary="__('recruitment-campaign.page.step_form_help')">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-body text-secondary-medium">
                        @if ($formReady)
                            {{ __('recruitment-campaign.page.published_version', ['version' => $form->currentVersion->version_no]) }}
                        @elseif ($form)
                            {{ __('recruitment-campaign.page.draft_not_published') }}
                        @else
                            {{ __('recruitment-campaign.page.not_started') }}
                        @endif
                    </p>
                    @if ($canManageForm)
                        <a href="{{ route('recruitment.campaigns.application-form.edit', $campaign) }}" wire:navigate
                            @class([
                                'inline-flex cursor-pointer items-center gap-2 rounded-control px-4 py-2 text-body font-semibold transition',
                                'bg-primary text-white hover:bg-primary-hover' => $current === 'form',
                                'border border-secondary-light bg-white text-secondary-dark hover:bg-secondary-soft/30' => $current !== 'form',
                            ])>
                            {{ $form ? __('recruitment-campaign.page.open_form') : __('recruitment-campaign.page.create_form') }}
                            <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
                        </a>
                    @endif
                </div>
            </x-recruitment.campaign-step>

            {{-- 4. Link --}}
            <x-recruitment.campaign-step number="4" id="step-link" :done="$linkReady" :current="$current === 'link'"
                :locked="! $postReady || ! $formReady"
                :title="__('recruitment-campaign.page.step_link')"
                :summary="__('recruitment-campaign.page.step_link_help')">
                @if (! $postReady || ! $formReady)
                    <p class="text-body text-secondary-medium">{{ __('recruitment-campaign.page.link_waits') }}</p>
                @elseif ($canSeeLinks)
                    <livewire:recruitment.public-links.manager :campaign="$campaign" :key="'campaign-public-links-'.$campaign->id" />
                @else
                    <p class="text-body text-secondary-medium">{{ __('recruitment-campaign.page.link_no_permission') }}</p>
                @endif
            </x-recruitment.campaign-step>
        </ol>

        {{-- Status panel --}}
        <aside class="order-first lg:order-none lg:sticky lg:top-24 lg:self-start" aria-labelledby="campaign-status-heading">
            <section class="rounded-surface border border-secondary-soft bg-white p-5 shadow-e1">
                <h2 id="campaign-status-heading" class="text-subtitle font-bold text-secondary-dark">{{ __('recruitment-campaign.page.status_title') }}</h2>
                <p class="mt-2 text-body leading-6 text-secondary-medium">
                    {{ __('recruitment-campaign.page.status_'.$statusKey.($statusKey === 'open' && ! $campaign->ends_at ? '_no_end' : ''), [
                        'start' => $fmt($campaign->starts_at),
                        'end' => $fmt($campaign->ends_at) ?? __('recruitment-campaign.page.no_deadline'),
                    ]) }}
                </p>

                @if ($isDraft)
                    <div class="mt-5">
                        <div class="flex items-center justify-between text-small font-semibold text-secondary-dark">
                            <span>{{ __('recruitment-campaign.page.ready_count', ['done' => $readyCount, 'total' => 4]) }}</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-secondary-soft" role="progressbar"
                            aria-valuemin="0" aria-valuemax="4" aria-valuenow="{{ $readyCount }}"
                            aria-label="{{ __('recruitment-campaign.page.ready_count', ['done' => $readyCount, 'total' => 4]) }}">
                            <div class="h-full rounded-full bg-primary transition-all" style="width: {{ $readyCount * 25 }}%"></div>
                        </div>
                        <ul class="mt-4 space-y-2 text-small">
                            @foreach (['post' => $postReady, 'form' => $formReady, 'link' => $linkReady] as $key => $ok)
                                <li class="flex items-center gap-2 {{ $ok ? 'text-secondary-dark' : 'text-secondary-medium' }}" wire:key="ready-{{ $key }}">
                                    @if ($ok)
                                        <svg class="size-4 shrink-0 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                                    @else
                                        <svg class="size-4 shrink-0 text-secondary-light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="8"/></svg>
                                    @endif
                                    <a href="#step-{{ $key }}" class="cursor-pointer hover:underline">
                                        {{ __('recruitment-campaign.page.ready_'.$key) }}
                                        <span class="sr-only">— {{ $ok ? __('recruitment-campaign.page.done') : __('recruitment-campaign.page.to_do') }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @if ($canLaunch)
                        <div class="mt-5 space-y-2">
                            <button type="button" @disabled(! $allReady) data-campaign-launch
                                x-on:click="$dispatch('open-recruitment-confirmation', {
                                    scope: 'campaign-actions',
                                    action: 'launch',
                                    title: @js(__('recruitment-campaign.page.launch')),
                                    message: @js($startsInFuture
                                        ? __('recruitment-campaign.page.launch_confirm_scheduled', ['date' => $fmt($campaign->starts_at)])
                                        : __('recruitment-campaign.page.launch_confirm_now')),
                                    confirmLabel: @js(__('recruitment-campaign.page.launch')),
                                })"
                                class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-control bg-primary px-4 py-2.5 text-body font-bold text-white shadow-e1 transition hover:bg-primary-hover disabled:cursor-not-allowed disabled:bg-secondary-soft disabled:text-secondary-light disabled:shadow-none">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" class="rtl:-scale-x-100 origin-center"/></svg>
                                {{ $startsInFuture ? __('recruitment-campaign.page.launch_scheduled') : __('recruitment-campaign.page.launch') }}
                            </button>
                            @unless ($allReady)
                                <p class="text-small text-secondary-medium">{{ __('recruitment-campaign.page.launch_blocked') }}</p>
                            @endunless
                            @if ($canPublishOnly)
                                <button type="button" wire:click="act('publish')" wire:loading.attr="disabled" wire:target="act"
                                    class="w-full cursor-pointer rounded-control px-4 py-2 text-small font-semibold text-secondary-medium underline-offset-4 transition hover:text-secondary-dark hover:underline">
                                    {{ __('recruitment-campaign.page.publish_only') }}
                                </button>
                            @endif
                        </div>
                    @endif
                @endif

                @if ($available)
                    <div class="mt-5 space-y-2 border-t border-secondary-soft pt-5">
                        @foreach ($available as $action)
                            @php $danger = in_array($action, ['close', 'disable'], true); @endphp
                            <button type="button" wire:key="action-{{ $action }}" data-campaign-action="{{ $action }}"
                                @if ($danger)
                                    x-on:click="$dispatch('open-recruitment-confirmation', {
                                        scope: 'campaign-actions',
                                        action: 'act',
                                        args: [@js($action)],
                                        title: @js(__('recruitment-campaign.act.'.$action)),
                                        message: @js(__('recruitment-campaign.confirm.'.$action)),
                                        confirmLabel: @js(__('recruitment-campaign.act.'.$action)),
                                        tone: 'danger',
                                        requireReason: true,
                                        reasonLabel: @js(__('recruitment-campaign.reason')),
                                        reasonHint: @js(__('recruitment-campaign.reason_hint')),
                                        reasonRequiredMessage: @js(__('recruitment-campaign.reason_required')),
                                    })"
                                @else
                                    x-on:click="$dispatch('open-recruitment-confirmation', {
                                        scope: 'campaign-actions',
                                        action: 'act',
                                        args: [@js($action)],
                                        title: @js(__('recruitment-campaign.act.'.$action)),
                                        message: @js(__('recruitment-campaign.confirm.'.$action)),
                                        confirmLabel: @js(__('recruitment-campaign.act.'.$action)),
                                    })"
                                @endif
                                @class([
                                    'flex w-full cursor-pointer items-center justify-center gap-2 rounded-control px-4 py-2 text-body font-semibold transition',
                                    'border border-primary bg-white text-primary hover:bg-secondary-soft/30' => $danger,
                                    'bg-primary text-white hover:bg-primary-hover' => ! $danger && $loop->first,
                                    'border border-secondary-light bg-white text-secondary-dark hover:bg-secondary-soft/30' => ! $danger && ! $loop->first,
                                ])>
                                @if ($danger)
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 2.7 17.1A2 2 0 0 0 4.4 20h15.2a2 2 0 0 0 1.7-2.9L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                                @endif
                                {{ __('recruitment-campaign.act.'.$action) }}
                            </button>
                        @endforeach
                    </div>
                @elseif (! $isDraft && ! $mayManage)
                    <p class="mt-4 text-small text-secondary-medium">{{ __('recruitment-campaign.nothing_available') }}</p>
                @endif

                @if ($campaign->closedBy && $effective === Status::Closed)
                    <p class="mt-4 text-small text-secondary-medium">
                        {{ __('recruitment-campaign.closed_by') }}:
                        {{ $campaign->closedBy->profile?->name ?? $campaign->closedBy->name }}
                    </p>
                @endif
            </section>
        </aside>
    </div>

        <x-recruitment.confirmation-modal scope="campaign-actions" />
    </div>
</div>
