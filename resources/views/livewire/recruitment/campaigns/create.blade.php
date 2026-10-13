<x-slot:title>{{ __('recruitment-campaign.new_campaign') }}</x-slot:title>

<div class="space-y-6">
    <livewire:layout.breadcrumbs
        :title="__('recruitment-campaign.new_campaign')"
        :crumbs="[
            ['label' => __('recruitment-campaign.campaigns'), 'url' => route('recruitment.campaigns.index')],
            ['label' => __('recruitment-campaign.new_campaign')],
        ]"
    />

    <form wire:submit="save" class="mx-auto max-w-3xl space-y-6">
        <p class="text-body text-secondary-medium">{{ __('recruitment-campaign.new_campaign_hint') }}</p>

        {{-- 1. The seat --}}
        <section class="rounded-surface border border-secondary-soft bg-white p-5 shadow-e1 sm:p-6" aria-labelledby="create-vacancy-heading">
            <h2 id="create-vacancy-heading" class="text-subtitle font-bold text-secondary-dark">
                {{ __('recruitment-campaign.vacancy') }} <span class="text-primary" aria-hidden="true">*</span>
            </h2>
            @if ($vacancies->isEmpty())
                <div class="mt-4 flex items-start gap-3 rounded-control border border-secondary-soft bg-secondary-soft/30 p-4 text-body text-secondary-dark">
                    <svg class="mt-0.5 size-5 shrink-0 text-secondary-medium" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v5m0 3h.01"/></svg>
                    <p>{{ __('recruitment-campaign.no_open_vacancies') }}</p>
                </div>
            @else
                <div class="mt-4 grid gap-3" role="radiogroup" aria-labelledby="create-vacancy-heading">
                    @foreach ($vacancies as $vacancy)
                        <label wire:key="vacancy-{{ $vacancy->id }}"
                            class="flex cursor-pointer items-start gap-3 rounded-control border border-secondary-light p-4 transition hover:bg-secondary-soft/30 has-[:checked]:border-primary has-[:checked]:bg-secondary-soft/30">
                            <input type="radio" name="vacancyId" value="{{ $vacancy->id }}" wire:model="vacancyId"
                                class="mt-1 border-secondary-light text-primary focus:ring-primary">
                            <span class="min-w-0 flex-1">
                                <span class="block text-body font-bold text-secondary-dark">{{ $vacancy->position?->title ?? '—' }}</span>
                                <span class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-small text-secondary-medium">
                                    @if ($vacancy->position?->city)
                                        <span>{{ $vacancy->position->city->name }}</span>
                                    @endif
                                    @if ($vacancy->position?->program)
                                        <span>{{ $vacancy->position->program->name ?? '' }}</span>
                                    @endif
                                    @if ($vacancy->createdFromRequest)
                                        <bdi>{{ $vacancy->createdFromRequest->request_number }}</bdi>
                                    @endif
                                    <span>{{ __('recruitment-campaign.seats', ['open' => $vacancy->needed_count - $vacancy->filled_count]) }}</span>
                                    @if ($vacancy->campaigns_count > 0)
                                        <span>{{ trans_choice('recruitment-campaign.existing_campaigns', $vacancy->campaigns_count, ['count' => $vacancy->campaigns_count]) }}</span>
                                    @endif
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <x-error-span>{{ $errors->first('vacancyId') ?: $errors->first('vacancy') }}</x-error-span>
            @endif
        </section>

        {{-- 2. Who may apply --}}
        <section class="rounded-surface border border-secondary-soft bg-white p-5 shadow-e1 sm:p-6" aria-labelledby="create-mode-heading">
            <h2 id="create-mode-heading" class="text-subtitle font-bold text-secondary-dark">{{ __('recruitment-campaign.link_mode') }}</h2>
            <p class="mt-1 text-body text-secondary-medium">{{ __('recruitment-campaign.link_mode_hint') }}</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-3" role="radiogroup" aria-labelledby="create-mode-heading">
                @foreach ($linkModes as $mode)
                    <label wire:key="mode-{{ $mode }}"
                        class="flex cursor-pointer items-start gap-3 rounded-control border border-secondary-light p-4 transition hover:bg-secondary-soft/30 has-[:checked]:border-primary has-[:checked]:bg-secondary-soft/30">
                        <input type="radio" value="{{ $mode }}" wire:model="linkMode" class="mt-1 border-secondary-light text-primary focus:ring-primary">
                        <span>
                            <span class="block text-body font-bold text-secondary-dark">{{ __('recruitment-campaign.modes.'.$mode) }}</span>
                            <span class="mt-1 block text-small text-secondary-medium">{{ __('recruitment-campaign.mode_hints.'.$mode) }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <x-error-span>{{ $errors->first('link_mode') }}</x-error-span>
        </section>

        {{-- 3. Applicant age --}}
        <section class="rounded-surface border border-secondary-soft bg-white p-5 shadow-e1 sm:p-6" aria-labelledby="create-age-heading">
            <h2 id="create-age-heading" class="text-subtitle font-bold text-secondary-dark">{{ __('recruitment-campaign.age_range') }}</h2>
            <p class="mt-1 text-body text-secondary-medium">{{ __('recruitment-campaign.age_range_hint') }}</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-forms.input-label for="minimumAge">{{ __('recruitment-campaign.minimum_age') }}</x-forms.input-label>
                    <input id="minimumAge" type="number" min="0" max="120" inputmode="numeric" wire:model="minimumAge"
                        class="mt-1 w-full rounded-control border-secondary-light text-body focus:border-primary focus:ring-primary">
                    <x-error-span>{{ $errors->first('minimum_age') }}</x-error-span>
                </div>
                <div>
                    <x-forms.input-label for="maximumAge">{{ __('recruitment-campaign.maximum_age') }}</x-forms.input-label>
                    <input id="maximumAge" type="number" min="0" max="120" inputmode="numeric" wire:model="maximumAge"
                        class="mt-1 w-full rounded-control border-secondary-light text-body focus:border-primary focus:ring-primary">
                    <x-error-span>{{ $errors->first('maximum_age') }}</x-error-span>
                </div>
            </div>
        </section>

        {{-- 4. When --}}
        <section class="rounded-surface border border-secondary-soft bg-white p-5 shadow-e1 sm:p-6" aria-labelledby="create-window-heading">
            <h2 id="create-window-heading" class="text-subtitle font-bold text-secondary-dark">{{ __('recruitment-campaign.window') }}</h2>
            <p class="mt-1 text-body text-secondary-medium">{{ __('recruitment-campaign.window_hint') }}</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-forms.input-label for="startsAt">{{ __('recruitment-campaign.starts_at') }}</x-forms.input-label>
                    <input id="startsAt" type="date" wire:model="startsAt"
                        class="mt-1 w-full rounded-control border-secondary-light text-body focus:border-primary focus:ring-primary">
                    <x-error-span>{{ $errors->first('starts_at') }}</x-error-span>
                </div>
                <div>
                    <x-forms.input-label for="endsAt">{{ __('recruitment-campaign.ends_at') }}</x-forms.input-label>
                    <input id="endsAt" type="date" wire:model="endsAt"
                        class="mt-1 w-full rounded-control border-secondary-light text-body focus:border-primary focus:ring-primary">
                    <x-error-span>{{ $errors->first('ends_at') }}</x-error-span>
                </div>
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <a href="{{ route('recruitment.campaigns.index') }}" wire:navigate
                class="cursor-pointer rounded-control border border-secondary-light bg-white px-4 py-2 text-body font-semibold text-secondary-dark transition hover:bg-secondary-soft/30">
                {{ __('recruitment-campaign.cancel') }}
            </a>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save" :disabled="$vacancies->isEmpty()">
                {{ __('recruitment-campaign.create') }}
            </x-primary-button>
        </div>
        <p class="text-end text-small text-secondary-medium">{{ __('recruitment-campaign.create_next_hint') }}</p>
    </form>
</div>
