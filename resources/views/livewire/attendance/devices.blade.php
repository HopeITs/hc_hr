@php
    // Status = icon + words + fill shape (DESIGN.md). A device that stopped talking waits on HR.
    $tone = ['online' => ['done', 'check'], 'late' => ['wait', 'clock'], 'silent' => ['attn', 'alert'], 'never' => ['wait', 'dot']];
    // HR keeps devices named and in the right center; the technical side is IT's, who work as super-admin.
    $isIt = auth()->user()?->activeRoleIs('super-admin') ?? false;
    $centerOptions = $centers->map(fn ($c) => ['id' => $c->id, 'label' => $c->name.' · '.$c->city?->name]);
@endphp

<div class="space-y-6">
    <x-slot:title>{{ __('attendance.devices.title') }}</x-slot:title>

    <x-page-header :title="__('attendance.devices.title')" :lead="__('attendance.devices.lead')">
        <x-slot:actions>
            <x-button variant="quiet" :href="route('attendance.upload')">@lang('attendance.devices.file-fallback')</x-button>
            <x-button variant="outline" :href="route('attendance.timesheets')">@lang('attendance.upload.then')</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- 1. What waits on HR: a device that called but is not added yet. --}}
    @if($sightings->isNotEmpty())
        <section class="space-y-3 rounded-surface border-2 border-primary bg-card p-5" data-testid="device-sightings" aria-labelledby="sightings-title">
            <div>
                <h2 id="sightings-title" class="text-h4 font-bold text-ink">@lang('attendance.devices.sightings-title')</h2>
                <p class="text-small text-ink-2">@lang('attendance.devices.sightings-help')</p>
            </div>
            @foreach($sightings as $sighting)
                <form wire:submit.prevent="register('{{ $sighting->serial }}')" wire:key="sighting-{{ $sighting->serial }}"
                      class="flex flex-wrap items-start gap-3 border-t border-line-soft pt-3">
                    <div class="min-w-44">
                        <p class="font-semibold text-ink num" dir="ltr">{{ $sighting->serial }}</p>
                        <p class="text-small text-ink-2">@lang('attendance.devices.last-call') <bdi class="num">{{ $sighting->last_seen_at?->diffForHumans() }}</bdi></p>
                    </div>
                    <div>
                        <x-forms.select-input wire:model="newCenter.{{ $sighting->serial }}" aria-label="{{ __('attendance.devices.center') }}">
                            <option value="">@lang('attendance.devices.choose-center')</option>
                            @foreach($centerOptions as $c)<option value="{{ $c['id'] }}">{{ $c['label'] }}</option>@endforeach
                        </x-forms.select-input>
                        @error("newCenter.{$sighting->serial}") <p class="mt-1 text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <x-forms.input wire:model="newName.{{ $sighting->serial }}" placeholder="{{ __('attendance.devices.name-optional') }}" aria-label="{{ __('attendance.devices.name') }}" class="w-48" />
                    <x-button type="submit" size="sm">@lang('attendance.devices.add')</x-button>
                    <x-button type="button" variant="quiet" size="sm" :loading="false" wire:click="forgetSighting('{{ $sighting->serial }}')">@lang('attendance.devices.ignore')</x-button>
                </form>
            @endforeach
        </section>
    @endif

    {{-- 2. The devices: where each one is and whether it reaches the system. --}}
    <section data-testid="device-list" aria-labelledby="devices-title" class="space-y-3">
        <h2 id="devices-title" class="text-h4 font-bold text-ink">@lang('attendance.devices.list-title')</h2>

        @if($devices->isEmpty())
            <x-card><p class="text-body text-ink-2">@lang('attendance.devices.none')</p></x-card>
        @else
            <ul class="divide-y divide-line-soft overflow-hidden rounded-surface border border-line-soft bg-card shadow-e1">
                @foreach($devices as $device)
                    @php($health = $device->isBlocked() ? 'blocked' : $device->health())
                    <li wire:key="device-{{ $device->id }}" data-health="{{ $device->health() }}" class="p-4 sm:px-5">
                        @if($editingId === $device->id)
                            <form wire:submit.prevent="saveDevice" class="flex flex-wrap items-start gap-3">
                                <x-forms.input wire:model="editName" aria-label="{{ __('attendance.devices.name') }}" placeholder="{{ __('attendance.devices.name-optional') }}" class="w-56" />
                                <div>
                                    <x-forms.select-input wire:model="editCenter" aria-label="{{ __('attendance.devices.center') }}">
                                        <option value="">@lang('attendance.devices.choose-center')</option>
                                        @foreach($centerOptions as $c)<option value="{{ $c['id'] }}">{{ $c['label'] }}</option>@endforeach
                                    </x-forms.select-input>
                                    @error('editCenter') <p class="mt-1 text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                                </div>
                                <x-button type="submit" size="sm">@lang('general.save')</x-button>
                                <x-button variant="quiet" size="sm" :loading="false" wire:click="cancelEdit">@lang('general.cancel')</x-button>
                            </form>
                        @else
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                                <div class="min-w-0 flex-1">
                                    <p class="font-bold text-ink"><bdi>{{ $device->center?->name ?? __('attendance.devices.no-center') }}</bdi>@if($device->center?->city)<span class="font-normal text-ink-2"> · {{ $device->center->city->name }}</span>@endif</p>
                                    @if($device->name)<p class="text-small text-ink-2"><bdi>{{ $device->name }}</bdi></p>@endif
                                </div>

                                @if($health === 'blocked')
                                    <x-status tone="stop" icon="ban" :label="__('attendance.devices.blocked')" />
                                @else
                                    <x-status :tone="$tone[$health][0]" :icon="$tone[$health][1]" :label="__('attendance.devices.health.'.$health)" />
                                @endif

                                <div class="flex items-center gap-1">
                                    <x-button variant="quiet" size="sm" :loading="false" wire:click="edit({{ $device->id }})">@lang('general.edit')</x-button>
                                    @if($isIt)
                                    {{-- Everything HR rarely needs lives behind "More". --}}
                                    <details class="relative" data-testid="device-more">
                                        <summary class="grid size-11 cursor-pointer list-none place-items-center rounded-control text-ink-2 hover:bg-hover" aria-label="{{ __('attendance.devices.more') }}"><x-ic name="more" class="size-5" /></summary>
                                        <div class="absolute end-0 z-20 mt-1 w-72 space-y-3 rounded-surface border border-line-soft bg-card p-4 shadow-e3">
                                            <dl class="space-y-1 text-small">
                                                <div class="flex justify-between gap-3"><dt class="text-ink-2">@lang('attendance.devices.serial')</dt><dd class="font-semibold text-ink num" dir="ltr">{{ $device->serial }}</dd></div>
                                                <div class="flex justify-between gap-3"><dt class="text-ink-2">@lang('attendance.devices.last-seen')</dt><dd class="text-ink num">{{ $device->last_seen_at?->diffForHumans() ?? '—' }}</dd></div>
                                            </dl>
                                            <div class="border-t border-line-soft pt-3 text-small">
                                                <p class="font-semibold text-ink">@lang('attendance.devices.key-title')</p>
                                                @if($device->hasAgentKey())
                                                    <p class="text-ink-2"><span class="num" dir="ltr">zk_••••{{ $device->agent_key_hint }}</span> ·
                                                        @if($device->agent_last_sync_at) @lang('attendance.devices.agent-last') <bdi class="num">{{ $device->agent_last_sync_at->diffForHumans() }}</bdi> @else @lang('attendance.devices.agent-never') @endif</p>
                                                    <button type="button" wire:click="createKey({{ $device->id }})" wire:confirm="{{ __('attendance.devices.key-regenerate-confirm') }}" class="underline text-ink hover:text-primary">@lang('attendance.devices.key-regenerate')</button>
                                                    <button type="button" wire:click="revokeKey({{ $device->id }})" wire:confirm="{{ __('attendance.devices.key-revoke-confirm') }}" class="ms-3 underline text-ink-2 hover:text-primary">@lang('attendance.devices.key-revoke')</button>
                                                @else
                                                    <p class="text-ink-2">@lang('attendance.devices.key-when')</p>
                                                    <button type="button" wire:click="createKey({{ $device->id }})" class="underline text-ink hover:text-primary">@lang('attendance.devices.key-create')</button>
                                                @endif
                                            </div>
                                            <div class="flex gap-4 border-t border-line-soft pt-3 text-small">
                                                <button type="button" wire:click="toggleBlock({{ $device->id }})" class="underline text-ink-2 hover:text-primary">{{ $device->isBlocked() ? __('attendance.devices.unblock') : __('attendance.devices.block') }}</button>
                                                <button type="button" wire:click="removeDevice({{ $device->id }})" wire:confirm="{{ __('attendance.devices.remove-confirm') }}" class="underline text-ink-2 hover:text-primary">@lang('attendance.devices.remove')</button>
                                            </div>
                                        </div>
                                    </details>
                                    @else
                                        <x-button variant="quiet" size="sm" :loading="false" wire:click="removeDevice({{ $device->id }})" wire:confirm="{{ __('attendance.devices.remove-confirm') }}">@lang('attendance.devices.remove')</x-button>
                                    @endif
                                </div>
                            </div>

                            {{-- One line in plain words: when the last punch arrived, or what to check. --}}
                            <p class="mt-1 text-small text-ink-2">
                                @if($health === 'silent')
                                    <span class="font-semibold text-ink">{{ __('attendance.devices.silent-since', ['time' => $device->last_seen_at->diffForHumans()]) }}</span>
                                    {{ $isIt ? __('attendance.devices.silent-help') : __('attendance.devices.tell-it') }}
                                @elseif($health === 'never')
                                    {{ $isIt ? __('attendance.devices.never-help') : __('attendance.devices.never-help-hr') }}
                                @elseif($device->last_punch_at)
                                    @lang('attendance.devices.last-punch'): <bdi class="num">{{ $device->last_punch_at->format('d/m/Y H:i') }}</bdi>
                                @else
                                    @lang('attendance.devices.no-punch-yet')
                                @endif
                            </p>
                        @endif

                        {{-- A key just made: shown once, here, and never again. --}}
                        @if($newKey && $keyForId === $device->id)
                            <div class="mt-3 rounded-surface bg-soft p-4" data-testid="new-key">
                                <p class="font-semibold text-ink">@lang('attendance.devices.key-shown-once')</p>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <code class="select-all rounded bg-card px-3 py-2 text-body font-semibold text-ink" dir="ltr">{{ $newKey }}</code>
                                    <x-button size="sm" variant="outline" :loading="false" x-data="{}" x-on:click="navigator.clipboard.writeText($el.dataset.key)" data-key="{{ $newKey }}">@lang('attendance.devices.key-copy')</x-button>
                                    <x-button size="sm" variant="quiet" wire:click="hideKey">@lang('attendance.devices.key-hide')</x-button>
                                </div>
                                <p class="mt-2 text-small text-ink-2">@lang('attendance.devices.key-use')</p>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

    </section>

    @if($isIt)
    {{-- 3. Reference: how to set a device up, and adding one that never calls. Closed unless there is nothing yet. --}}
    <details class="rounded-surface border border-line-soft bg-card" data-testid="device-setup" @if($devices->isEmpty() && $sightings->isEmpty()) open @endif>
        <summary class="flex min-h-12 cursor-pointer items-center gap-2 px-5 font-bold text-ink"><x-ic name="info" class="size-4 text-ink-2" />@lang('attendance.devices.setup-title')</summary>
        <div class="space-y-3 border-t border-line-soft p-5">
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
        </div>
    </details>

    <details class="rounded-surface border border-line-soft bg-card" data-testid="device-add" @if($errors->has('addSerial') || $errors->has('addCenter')) open @endif>
        <summary class="flex min-h-12 cursor-pointer items-center gap-2 px-5 font-bold text-ink"><x-ic name="plus" class="size-4 text-ink-2" />@lang('attendance.devices.add-title')</summary>
        <div class="space-y-3 border-t border-line-soft p-5">
            <p class="text-small text-ink-2">@lang('attendance.devices.add-help')</p>
            <form wire:submit.prevent="addBySerial" class="flex flex-wrap items-start gap-3">
                <div>
                    <x-forms.input wire:model="addSerial" placeholder="{{ __('attendance.devices.serial') }}" aria-label="{{ __('attendance.devices.serial') }}" dir="ltr" class="w-52" />
                    @error('addSerial') <p class="mt-1 text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-forms.select-input wire:model="addCenter" aria-label="{{ __('attendance.devices.center') }}">
                        <option value="">@lang('attendance.devices.choose-center')</option>
                        @foreach($centerOptions as $c)<option value="{{ $c['id'] }}">{{ $c['label'] }}</option>@endforeach
                    </x-forms.select-input>
                    @error('addCenter') <p class="mt-1 text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                </div>
                <x-forms.input wire:model="addName" placeholder="{{ __('attendance.devices.name-optional') }}" aria-label="{{ __('attendance.devices.name') }}" class="w-48" />
                <x-button type="submit" size="sm">@lang('attendance.devices.add')</x-button>
            </form>
        </div>
    </details>
    @endif
</div>
