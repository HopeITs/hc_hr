<x-slot:title>{{ __('recruitment.direct_open.title') }}</x-slot:title>

<div class="mx-auto max-w-5xl space-y-6">
    <livewire:layout.breadcrumbs :title="__('recruitment.direct_open.title')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment.navigation_requests'), 'route' => 'recruitment.requests.index'], ['label' => __('recruitment.direct_open.title')]]" />
    <p class="-mt-4 text-secondary-medium">{{ __('recruitment.direct_open.hint') }}</p>

    <form wire:submit="save" class="space-y-6">
        <section class="space-y-5 rounded-surface border border-secondary-soft bg-white p-6">
            <div>
                <h2 class="text-h4 font-semibold">{{ __('recruitment.direct_open.title') }}</h2>
                <p class="mt-1 text-sm text-secondary-medium">{{ __('recruitment.direct_open.hint') }}</p>
            </div>

            <div>
                <x-forms.input-label for="actualRequester" required>{{ __('recruitment.direct_open.actual_requester_name') }}</x-forms.input-label>
                <x-forms.select2-input id="actualRequester" wire:model.live="documentation.actual_requester_user_id"
                    aria-invalid="{{ $errors->has('actual_requester_user_id') ? 'true' : 'false' }}">
                    <option value="">{{ __('recruitment.choose') }}</option>
                    @foreach ($requesters as $requester)
                        @php
                            $requesterPosition = $requester->profile?->latestActiveContract()?->position;
                            $requesterLabel = ($requester->profile?->name ?? $requester->email)
                                .' · '.($requesterPosition?->title ?? __('recruitment.direct_open.position_unknown'));
                        @endphp
                        <option value="{{ $requester->id }}">{{ $requesterLabel }}</option>
                    @endforeach
                </x-forms.select2-input>
                <p class="mt-1 text-sm text-primary">
                    @error('actual_requester_user_id'){{ $message }}@enderror
                    @error('actual_requester_name'){{ $message }}@enderror
                </p>
                <p class="mt-1 text-sm text-secondary-medium">{{ __('recruitment.direct_open.requester_help') }}</p>
            </div>

            <div>
                <x-forms.input-label for="directOpenReason" required>{{ __('recruitment.direct_open.reason') }}</x-forms.input-label>
                <textarea id="directOpenReason" rows="3" wire:model="documentation.direct_open_reason"
                    class="mt-1 w-full rounded-control border-secondary-light" dir="auto"></textarea>
                <p class="mt-1 text-sm text-primary">@error('direct_open_reason'){{ $message }}@enderror</p>
            </div>
        </section>

        {{-- The same request form a manager fills in: a direct opening shortens
             the approvals, never the facts. --}}
        @include('livewire.recruitment.requests.partials.request-fields')

        @if ($steps !== [])
            <section class="space-y-5 rounded-surface border border-secondary-soft bg-white p-6">
                <div>
                    <h2 class="text-h4 font-semibold">{{ __('recruitment.direct_open.confirmations') }}</h2>
                    <p class="mt-1 text-sm text-secondary-medium">{{ __('recruitment.direct_open.confirmations_hint') }}</p>
                </div>

                {{-- Only decisions made outside the system belong here. The HR
                     action is already recorded by the signed-in user who creates
                     the direct opening. --}}
                @foreach ($steps as $stepKey)
                @php $decision = (int) ($documentation['confirmations'][$stepKey]['decision'] ?? 0); @endphp
                <fieldset class="rounded-control border border-secondary-soft p-4" wire:key="confirm-{{ $stepKey }}">
                    <legend class="px-1 text-sm font-semibold">
                        {{ \App\Enums\WorkflowStepKey::getLocalizedName($stepKey)['value'] }}
                    </legend>

                    <div class="flex flex-wrap gap-4">
                        @foreach ($decisions as $value)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" value="{{ $value }}"
                                    wire:model.live="documentation.confirmations.{{ $stepKey }}.decision"
                                    class="border-secondary-light text-primary">
                                <span>{{ __('recruitment.direct_open.decisions.'.$value) }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-sm text-primary">@error('confirmations.'.$stepKey.'.decision'){{ $message }}@enderror</p>

                    @if ($decision === \App\Enums\ExternalConfirmationDecision::NotApplicable)
                        <div class="mt-4">
                            <x-forms.input-label for="na-{{ $stepKey }}" required>{{ __('recruitment.direct_open.not_applicable_reason') }}</x-forms.input-label>
                            <textarea id="na-{{ $stepKey }}" rows="2"
                                wire:model="documentation.confirmations.{{ $stepKey }}.not_applicable_reason"
                                class="mt-1 w-full rounded-control border-secondary-light" dir="auto"></textarea>
                            <p class="mt-1 text-sm text-primary">@error('confirmations.'.$stepKey.'.not_applicable_reason'){{ $message }}@enderror</p>
                        </div>
                    @elseif ($decision === \App\Enums\ExternalConfirmationDecision::Approved)
                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div wire:key="confirm-person-{{ $stepKey }}-{{ md5(json_encode(($confirmationCandidates[$stepKey] ?? collect())->pluck('id')->all())) }}">
                                <x-forms.input-label for="who-{{ $stepKey }}" required>{{ __('recruitment.direct_open.confirmer_name') }}</x-forms.input-label>
                                <x-forms.select2-input id="who-{{ $stepKey }}"
                                    wire:model.live="documentation.confirmations.{{ $stepKey }}.confirmer_user_id"
                                    aria-invalid="{{ $errors->has('confirmations.'.$stepKey.'.confirmer_user_id') ? 'true' : 'false' }}">
                                    <option value="">{{ __('recruitment.choose') }}</option>
                                    @foreach (($confirmationCandidates[$stepKey] ?? collect()) as $candidate)
                                        @php
                                            $candidatePosition = $candidate->profile?->latestActiveContract()?->position;
                                            $candidateLabel = ($candidate->profile?->name ?? $candidate->email)
                                                .' · '.($candidatePosition?->title ?? __('recruitment.direct_open.position_unknown'));
                                        @endphp
                                        <option value="{{ $candidate->id }}">{{ $candidateLabel }}</option>
                                    @endforeach
                                </x-forms.select2-input>
                                @if (($confirmationCandidates[$stepKey] ?? collect())->isEmpty())
                                    <p class="mt-1 text-xs text-secondary-medium">{{ __('recruitment.direct_open.no_confirmer_candidates') }}</p>
                                @endif
                                <p class="mt-1 text-sm text-primary">
                                    @error('confirmations.'.$stepKey.'.confirmer_user_id'){{ $message }}@enderror
                                </p>
                            </div>
                            <div>
                                <x-forms.input-label for="how-{{ $stepKey }}" required>{{ __('recruitment.direct_open.method') }}</x-forms.input-label>
                                <select id="how-{{ $stepKey }}"
                                    wire:model="documentation.confirmations.{{ $stepKey }}.method"
                                    class="h-[37.6px] w-full rounded-control border-secondary-light">
                                    <option value="">—</option>
                                    @foreach ($methods as $method)
                                        <option value="{{ $method }}">{{ __('recruitment.direct_open.methods.'.$method) }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-sm text-primary">@error('confirmations.'.$stepKey.'.method'){{ $message }}@enderror</p>
                            </div>
                            <div>
                                <x-forms.input-label for="when-{{ $stepKey }}" required>{{ __('recruitment.direct_open.confirmed_at') }}</x-forms.input-label>
                                <input id="when-{{ $stepKey }}" type="date"
                                    wire:model="documentation.confirmations.{{ $stepKey }}.confirmed_at"
                                    class="h-[37.6px] w-full rounded-control border-secondary-light">
                                <p class="mt-1 text-sm text-primary">@error('confirmations.'.$stepKey.'.confirmed_at'){{ $message }}@enderror</p>
                            </div>
                        </div>
                        <div class="mt-4">
                            <x-forms.input-label for="notes-{{ $stepKey }}">{{ __('recruitment.direct_open.notes') }}</x-forms.input-label>
                            <textarea id="notes-{{ $stepKey }}" rows="2"
                                wire:model="documentation.confirmations.{{ $stepKey }}.notes"
                                class="mt-1 w-full rounded-control border-secondary-light" dir="auto"></textarea>
                        </div>
                    @endif
                </fieldset>
                @endforeach
            </section>
        @endif

        <footer class="flex flex-wrap items-center justify-between gap-4 rounded-surface border border-secondary-soft bg-white p-5">
            <p class="text-sm text-secondary-medium">{{ __('recruitment.direct_open.hint') }}</p>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save">
                {{ __('recruitment.direct_open.open') }}
            </x-primary-button>
        </footer>
    </form>
</div>
