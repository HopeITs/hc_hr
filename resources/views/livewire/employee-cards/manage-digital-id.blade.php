{{--
    Digital-ID card panel (ManageDigitalId). One root element in every state
    (Livewire wraps @if blocks in comment markers): for viewers who may neither
    manage nor report the card it stays empty and hidden.
--}}
<div {{ $visible ? '' : 'hidden' }} data-testid="digital-id-panel">
@if ($visible)
    @php
        $statusLabel = ! $isActive
            ? __('digital_id.panel.status_inactive')
            : ($isEnabled ? __('digital_id.panel.status_valid') : __('digital_id.panel.status_disabled'));
        // Valid = done; disabled or inactive = stopped (icon + words, never colour).
        [$statusTone, $statusIcon] = $isActive && $isEnabled ? ['done', 'check'] : ['stop', $isActive ? 'ban' : 'pause'];
    @endphp

        <x-profile-section :title="__('digital_id.panel.title')" :subtitle="__('digital_id.panel.subtitle')" icon="icons.employee">
            <div class="space-y-4 text-body">
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-surface bg-neutral-50 p-3">
                        <dt class="text-small text-secondary-light">@lang('digital_id.panel.status')</dt>
                        <dd class="mt-1">
                            <x-status :tone="$statusTone" :icon="$statusIcon" :label="$statusLabel" />
                        </dd>
                    </div>

                    <div class="rounded-surface bg-neutral-50 p-3">
                        <dt class="text-small text-secondary-light">@lang('digital_id.panel.type')</dt>
                        <dd class="mt-1 font-semibold text-secondary-dark">
                            {{ $isPlastic ? __('digital_id.panel.plastic', ['index' => $index]) : __('digital_id.panel.cardboard') }}
                        </dd>
                    </div>

                    @if ($issuedAt)
                        <div class="rounded-surface bg-neutral-50 p-3 sm:col-span-2">
                            <dt class="text-small text-secondary-light">@lang('digital_id.panel.last_issued')</dt>
                            <dd class="mt-1 font-semibold text-secondary-dark"><bdi>{{ $issuedAt->locale(app()->getLocale())->translatedFormat('d M Y') }}</bdi></dd>
                        </div>
                    @endif
                </dl>

                @if ($link)
                    <div x-data="{ copied: false }" class="space-y-2">
                        <p class="text-small font-semibold text-secondary-light">@lang('digital_id.panel.link')</p>
                        {{-- aria-label, not <label for>: x-profile-section renders its slot twice, so ids would repeat. --}}
                        <input type="text" readonly value="{{ $link }}" dir="ltr" aria-label="{{ __('digital_id.panel.link') }}"
                            x-on:focus="$el.select()"
                            class="block w-full min-w-0 rounded-control border border-neutral-200 bg-white px-3 py-2 text-start font-mono text-small text-secondary-dark">
                        <p class="text-small text-secondary-light">@lang('digital_id.panel.link_help')</p>
                        <div class="flex flex-wrap gap-2">
                            <x-secondary-button class="!w-auto"
                                x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($link) }}).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                                <span x-show="! copied">@lang('digital_id.panel.copy')</span>
                                <span x-show="copied" x-cloak>@lang('digital_id.panel.copied')</span>
                            </x-secondary-button>
                            <a href="{{ $link }}" target="_blank" rel="noopener noreferrer"
                                class="inline-flex items-center rounded-control border border-neutral-200 bg-white px-4 py-2 font-semibold text-secondary-dark transition hover:border-primary hover:text-primary">
                                @lang('digital_id.panel.open')
                            </a>
                        </div>
                    </div>
                @elseif ($missingLink)
                    <p class="flex items-start gap-2 rounded-control border-s-4 border-ink bg-soft p-3 text-small font-semibold text-ink">
                        <x-ic name="warning" class="mt-0.5 size-4" />{{ __($missingLink) }}
                    </p>
                @endif

                @if ($canManage)
                    <div class="flex flex-wrap gap-2 border-t border-neutral-100 pt-4">
                        @if ($isActive && $link)
                            @php
                                $reissueTitle = $isPlastic ? __('digital_id.confirm.reissue_title') : __('digital_id.confirm.issue_plastic_title');
                                $reissueMessage = $isPlastic
                                    ? __('digital_id.confirm.reissue', ['index' => $index, 'next' => $index + 1])
                                    : __('digital_id.confirm.issue_plastic');
                                $reissueLabel = $isPlastic ? __('digital_id.actions.reissue') : __('digital_id.actions.issue_plastic');
                            @endphp
                            <x-primary-button type="button" class="!w-auto" :showSpinner="false"
                                x-on:click="if (await $confirm({ title: {{ \Illuminate\Support\Js::from($reissueTitle) }}, message: {{ \Illuminate\Support\Js::from($reissueMessage) }}, confirmLabel: {{ \Illuminate\Support\Js::from($reissueLabel) }}, danger: true })) $wire.reissue({{ $index }})">
                                {{ $reissueLabel }}
                            </x-primary-button>
                        @endif

                        @if ($isEnabled)
                            <x-secondary-button class="!w-auto"
                                x-on:click="if (await $confirm({ title: {{ \Illuminate\Support\Js::from(__('digital_id.confirm.disable_title')) }}, message: {{ \Illuminate\Support\Js::from(__('digital_id.confirm.disable')) }}, confirmLabel: {{ \Illuminate\Support\Js::from(__('digital_id.actions.disable')) }}, danger: true })) $wire.disable()">
                                @lang('digital_id.actions.disable')
                            </x-secondary-button>
                        @elseif ($isActive)
                            <x-secondary-button class="!w-auto"
                                x-on:click="if (await $confirm({ title: {{ \Illuminate\Support\Js::from(__('digital_id.confirm.enable_title')) }}, message: {{ \Illuminate\Support\Js::from(__('digital_id.confirm.enable')) }}, confirmLabel: {{ \Illuminate\Support\Js::from(__('digital_id.actions.enable')) }}, danger: false })) $wire.enable()">
                                @lang('digital_id.actions.enable')
                            </x-secondary-button>
                        @endif
                    </div>
                @elseif ($canReport)
                    <div class="space-y-3 border-t border-neutral-100 pt-4">
                        <p class="text-small text-secondary-light">@lang('digital_id.panel.self_help')</p>
                        <x-secondary-button class="!w-auto"
                            x-on:click="if (await $confirm({ title: {{ \Illuminate\Support\Js::from(__('digital_id.confirm.report_lost_title')) }}, message: {{ \Illuminate\Support\Js::from(__('digital_id.confirm.report_lost')) }}, confirmLabel: {{ \Illuminate\Support\Js::from(__('digital_id.actions.report_lost')) }}, danger: false })) $wire.reportLost()">
                            @lang('digital_id.actions.report_lost')
                        </x-secondary-button>
                    </div>
                @endif
            </div>
        </x-profile-section>
@endif
</div>
