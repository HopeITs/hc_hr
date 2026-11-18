@php
    $tone = ['online' => ['done', 'check'], 'late' => ['wait', 'clock'], 'silent' => ['attn', 'alert'], 'never' => ['wait', 'dot']];
@endphp

<div class="space-y-6">
    <x-slot:title>{{ __('attendance.devices.title') }}</x-slot:title>

    <x-page-header :title="__('attendance.devices.title')" :lead="__('attendance.devices.lead')">
        <x-slot:actions>
            <x-button variant="outline" :href="route('attendance.timesheets')">@lang('attendance.upload.then')</x-button>
            <x-button variant="quiet" :href="route('attendance.upload')">@lang('attendance.devices.file-fallback')</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- A device that called but is not registered: one click to add it. --}}
    @if($sightings->isNotEmpty())
        <x-card class="space-y-3" data-testid="device-sightings">
            <div>
                <h2 class="text-h4 font-bold text-ink">@lang('attendance.devices.sightings-title')</h2>
                <p class="text-small text-ink-2">@lang('attendance.devices.sightings-help')</p>
            </div>
            @foreach($sightings as $sighting)
                <form wire:submit.prevent="register('{{ $sighting->serial }}')" wire:key="sighting-{{ $sighting->serial }}"
                      class="flex flex-wrap items-start gap-3 border-t border-line-soft pt-3">
                    <div class="min-w-48">
                        <p class="font-semibold text-ink num" dir="ltr">{{ $sighting->serial }}</p>
                        <p class="text-small text-ink-2">
                            @lang('attendance.devices.last-call'): <bdi class="num">{{ $sighting->last_seen_at?->diffForHumans() }}</bdi>
                            @if($sighting->last_ip) · <span dir="ltr">{{ $sighting->last_ip }}</span> @endif
                        </p>
                    </div>
                    <div>
                        <x-forms.select-input wire:model="newCenter.{{ $sighting->serial }}" aria-label="{{ __('attendance.devices.center') }}">
                            <option value="">@lang('attendance.devices.choose-center')</option>
                            @foreach($centers as $center)
                                <option value="{{ $center->id }}">{{ $center->name }} · {{ $center->city?->name }}</option>
                            @endforeach
                        </x-forms.select-input>
                        @error("newCenter.{$sighting->serial}") <p class="mt-1 text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <x-forms.input wire:model="newName.{{ $sighting->serial }}" placeholder="{{ __('attendance.devices.name') }}" aria-label="{{ __('attendance.devices.name') }}" class="w-48" />
                    <x-button type="submit" size="sm">@lang('attendance.devices.add')</x-button>
                    <button type="button" wire:click="forgetSighting('{{ $sighting->serial }}')" class="self-center text-small underline text-ink-2 hover:text-primary">@lang('attendance.devices.ignore')</button>
                </form>
            @endforeach
        </x-card>
    @endif

    {{-- The registered devices, the quiet ones first. --}}
    <x-card class="!p-0 overflow-hidden" data-testid="device-list">
        <div class="border-b border-line-soft bg-soft px-4 py-3">
            <h2 class="text-h4 font-bold text-ink">@lang('attendance.devices.list-title')</h2>
        </div>

        @if($devices->isEmpty())
            <p class="p-6 text-body text-ink-2">@lang('attendance.devices.none')</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-max text-start text-body">
                    <thead>
                        <tr class="text-small text-ink-2">
                            <th scope="col" class="px-4 py-2 text-start font-semibold">@lang('attendance.devices.device')</th>
                            <th scope="col" class="px-4 py-2 text-start font-semibold">@lang('attendance.devices.center')</th>
                            <th scope="col" class="px-4 py-2 text-start font-semibold">@lang('attendance.devices.state')</th>
                            <th scope="col" class="px-4 py-2 text-start font-semibold">@lang('attendance.devices.key-title')</th>
                            <th scope="col" class="px-4 py-2 text-start font-semibold">@lang('attendance.devices.last-seen')</th>
                            <th scope="col" class="px-4 py-2 text-start font-semibold">@lang('attendance.devices.last-punch')</th>
                            <th scope="col" class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($devices as $device)
                            @php($health = $device->health())
                            <tr class="border-t border-line-soft align-top" wire:key="device-{{ $device->id }}" data-health="{{ $health }}">
                                @if($editingId === $device->id)
                                    <td class="px-4 py-3" colspan="6">
                                        <form wire:submit.prevent="saveDevice" class="flex flex-wrap items-start gap-3">
                                            <x-forms.input wire:model="editName" aria-label="{{ __('attendance.devices.name') }}" class="w-56" />
                                            <div>
                                                <x-forms.select-input wire:model="editCenter" aria-label="{{ __('attendance.devices.center') }}">
                                                    <option value="">@lang('attendance.devices.choose-center')</option>
                                                    @foreach($centers as $center)
                                                        <option value="{{ $center->id }}">{{ $center->name }} · {{ $center->city?->name }}</option>
                                                    @endforeach
                                                </x-forms.select-input>
                                                @error('editCenter') <p class="mt-1 text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                                            </div>
                                            <x-button type="submit" size="sm">@lang('general.save')</x-button>
                                            <x-button variant="quiet" size="sm" :loading="false" wire:click="cancelEdit">@lang('general.cancel')</x-button>
                                        </form>
                                    </td>
                                @else
                                    <td class="px-4 py-3">
                                        <span class="block font-semibold text-ink"><bdi>{{ $device->name ?: '—' }}</bdi></span>
                                        <span class="block text-caption text-ink-3 num" dir="ltr">{{ $device->serial }}</span>
                                    </td>
                                    <td class="px-4 py-3"><bdi>{{ $device->center?->name ?? '—' }}</bdi>@if($device->center?->city)<span class="block text-caption text-ink-3">{{ $device->center->city->name }}</span>@endif</td>
                                    <td class="px-4 py-3">
                                        @if($device->isBlocked())
                                            <x-status tone="attn" icon="ban" :label="__('attendance.devices.blocked')" />
                                        @else
                                            <x-status :tone="$tone[$health][0]" :icon="$tone[$health][1]" :label="__('attendance.devices.health.'.$health)" />
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-small">
                                        @if($device->hasAgentKey())
                                            <span class="block font-semibold text-ink num" dir="ltr">zk_••••{{ $device->agent_key_hint }}</span>
                                            <span class="block text-caption text-ink-2">
                                                @if($device->agent_last_sync_at)
                                                    @lang('attendance.devices.agent-last'): <bdi class="num">{{ $device->agent_last_sync_at->diffForHumans() }}</bdi>
                                                @else
                                                    @lang('attendance.devices.agent-never')
                                                @endif
                                            </span>
                                            <button type="button" wire:click="createKey({{ $device->id }})" wire:confirm="{{ __('attendance.devices.key-regenerate-confirm') }}" class="text-small underline text-ink hover:text-primary">@lang('attendance.devices.key-regenerate')</button>
                                            <button type="button" wire:click="revokeKey({{ $device->id }})" wire:confirm="{{ __('attendance.devices.key-revoke-confirm') }}" class="ms-2 text-small underline text-ink-2 hover:text-primary">@lang('attendance.devices.key-revoke')</button>
                                        @else
                                            <span class="block text-ink-2">@lang('attendance.devices.key-none')</span>
                                            <button type="button" wire:click="createKey({{ $device->id }})" class="text-small underline text-ink hover:text-primary">@lang('attendance.devices.key-create')</button>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-small text-ink-2 num whitespace-nowrap">{{ $device->last_seen_at?->diffForHumans() ?? '—' }}</td>
                                    <td class="px-4 py-3 text-small text-ink-2 num whitespace-nowrap"><bdi>{{ $device->last_punch_at?->format('d/m/Y H:i') ?? '—' }}</bdi></td>
                                    <td class="px-4 py-3 text-end whitespace-nowrap">
                                        <button type="button" wire:click="edit({{ $device->id }})" class="px-2 text-small underline text-ink hover:text-primary">@lang('general.edit')</button>
                                        <button type="button" wire:click="toggleBlock({{ $device->id }})" class="px-2 text-small underline text-ink-2 hover:text-primary">{{ $device->isBlocked() ? __('attendance.devices.unblock') : __('attendance.devices.block') }}</button>
                                        <button type="button" wire:click="removeDevice({{ $device->id }})" wire:confirm="{{ __('attendance.devices.remove-confirm') }}" class="px-2 text-small underline text-ink-2 hover:text-primary">@lang('attendance.devices.remove')</button>
                                    </td>
                                @endif
                            </tr>
                            {{-- A key just made: shown once, here, and never again. --}}
                            @if($newKey && $keyForId === $device->id)
                                <tr wire:key="device-key-{{ $device->id }}" class="bg-soft" data-testid="new-key">
                                    <td colspan="7" class="px-4 py-3">
                                        <p class="font-semibold text-ink">@lang('attendance.devices.key-shown-once')</p>
                                        <div class="mt-2 flex flex-wrap items-center gap-2">
                                            <code class="select-all rounded bg-card px-3 py-2 text-body font-semibold text-ink" dir="ltr">{{ $newKey }}</code>
                                            <x-button size="sm" variant="outline" :loading="false" x-data="{}" x-on:click="navigator.clipboard.writeText($el.dataset.key)" data-key="{{ $newKey }}">@lang('attendance.devices.key-copy')</x-button>
                                            <x-button size="sm" variant="quiet" wire:click="hideKey">@lang('attendance.devices.key-hide')</x-button>
                                        </div>
                                        <p class="mt-2 text-small text-ink-2">@lang('attendance.devices.key-use')</p>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    {{-- Centers with no device yet --}}
    @if($withoutDevice->isNotEmpty())
        <x-card data-testid="centers-without-device">
            <h2 class="text-h4 font-bold text-ink">@lang('attendance.devices.without-title')</h2>
            <p class="mt-1 text-small text-ink-2">@lang('attendance.devices.without-help')</p>
            <p class="mt-3 flex flex-wrap gap-2">
                @foreach($withoutDevice as $center)
                    <x-tag variant="soft" :label="$center->name.' · '.$center->city?->name" />
                @endforeach
            </p>
        </x-card>
    @endif

    {{-- What to type on the device --}}
    <x-card class="space-y-3" data-testid="device-setup">
        <h2 class="text-h4 font-bold text-ink">@lang('attendance.devices.setup-title')</h2>
        <p class="text-body text-ink-2">@lang('attendance.devices.setup-intro')</p>
        <dl class="grid max-w-xl gap-x-6 gap-y-2 sm:grid-cols-2">
            <div><dt class="text-small text-ink-2">@lang('attendance.devices.setup-mode')</dt><dd class="font-semibold text-ink">ADMS</dd></div>
            <div><dt class="text-small text-ink-2">@lang('attendance.devices.setup-domain')</dt><dd class="font-semibold text-ink">@lang('attendance.devices.on')</dd></div>
            <div><dt class="text-small text-ink-2">@lang('attendance.devices.setup-address')</dt><dd class="font-semibold text-ink num" dir="ltr">{{ $host ?: '—' }}</dd></div>
            <div><dt class="text-small text-ink-2">@lang('attendance.devices.setup-port')</dt><dd class="font-semibold text-ink num">443</dd></div>
            <div><dt class="text-small text-ink-2">@lang('attendance.devices.setup-https')</dt><dd class="font-semibold text-ink">@lang('attendance.devices.on')</dd></div>
        </dl>
        <p class="text-small text-ink-2">@lang('attendance.devices.setup-where')</p>
        <p class="text-small text-ink-2">@lang('attendance.devices.setup-after')</p>
    </x-card>
</div>
