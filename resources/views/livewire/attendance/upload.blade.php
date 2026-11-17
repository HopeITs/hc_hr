<div class="space-y-6">
    <x-slot:title>{{ __('attendance.upload.title') }}</x-slot:title>

    <x-page-header :title="__('attendance.upload.title')" :lead="__('attendance.upload.lead')">
        <x-slot:actions>
            <x-button variant="outline" :href="route('attendance.timesheets')">@lang('attendance.upload.then')</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        <label for="attendance-file" class="block text-body font-semibold text-ink">@lang('attendance.upload.choose')</label>
        <input id="attendance-file" type="file" wire:model="file" accept=".xls,.xlsx,.csv,.txt"
               class="mt-2 block w-full max-w-xl rounded-control border border-line bg-card px-3 py-2 text-body text-ink">
        <p class="mt-2 text-small text-ink-2">@lang('attendance.upload.file-help')</p>
        <div wire:loading wire:target="file" class="mt-2 text-small text-ink-2">…</div>
        @error('file') <p class="mt-2 text-body font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
    </x-card>

    @if($preview)
        <x-card data-testid="attendance-preview">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="text-h4 font-bold text-ink" dir="auto">{{ $preview['name'] }}</div>
                    <div class="mt-1 text-body text-ink-2">
                        <span class="num">{{ number_format($preview['total']) }}</span> @lang('attendance.upload.punches')
                        · <span class="num">{{ count($preview['numbers']) }}</span> @lang('attendance.upload.device-numbers')
                        @if($preview['from'])
                            · @lang('attendance.upload.period'): <bdi class="num">{{ $preview['from'] }} → {{ $preview['to'] }}</bdi>
                        @endif
                    </div>
                    @if($preview['invalid'] > 0)
                        <div class="mt-1 text-small text-ink-2">{{ __('attendance.upload.skipped', ['count' => $preview['invalid']]) }}</div>
                    @endif
                    <div class="mt-1 text-small text-ink-2">
                        @if($preview['unmatched'] > 0)
                            {{ __('attendance.upload.summary-unmatched', ['count' => $preview['unmatched']]) }}
                        @else
                            {{ __('attendance.upload.summary-matched', ['matched' => count($preview['numbers']), 'total' => count($preview['numbers'])]) }}
                        @endif
                    </div>
                </div>
                <div class="flex gap-2">
                    <x-button variant="outline" wire:click="cancel">@lang('attendance.upload.cancel')</x-button>
                    <x-button wire:click="import" wire:loading.attr="disabled" wire:target="import">{{ __('attendance.upload.import', ['count' => number_format($preview['total'])]) }}</x-button>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-start text-body">
                    <thead>
                        <tr class="border-b border-line-soft text-small text-ink-2">
                            <th class="py-2 pe-4 text-start font-semibold">@lang('attendance.upload.device-no')</th>
                            <th class="py-2 pe-4 text-start font-semibold">@lang('attendance.upload.employee')</th>
                            <th class="py-2 pe-4 text-start font-semibold">@lang('attendance.upload.punches')</th>
                            <th class="py-2 text-start font-semibold">@lang('attendance.upload.status')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($preview['numbers'], 0, 40) as $row)
                            <tr class="border-b border-line-soft">
                                <td class="py-2 pe-4 font-semibold num">{{ $row['device_no'] }}</td>
                                <td class="py-2 pe-4"><bdi>{{ $row['name'] ?? '—' }}</bdi></td>
                                <td class="py-2 pe-4 num">{{ $row['punches'] }}</td>
                                <td class="py-2">
                                    @if($row['name'])
                                        <x-status tone="done" icon="check" :label="__('attendance.upload.matched-by-id')" />
                                    @else
                                        <x-status tone="attn" icon="alert" :label="__('attendance.upload.not-matched')" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if(count($preview['numbers']) > 40)
                    <p class="mt-2 text-small text-ink-3">… {{ count($preview['numbers']) - 40 }}</p>
                @endif
            </div>
        </x-card>
    @endif

    @if($importedId)
        <x-card>
            <p class="text-body font-semibold text-ink">{{ __('attendance.upload.imported', ['new' => $imports->firstWhere('id', $importedId)?->punches_new ?? 0]) }}</p>
            <div class="mt-3"><x-button :href="route('attendance.timesheets')">@lang('attendance.upload.then')</x-button></div>
        </x-card>
    @endif

    <x-card data-testid="attendance-unlinked">
        <h2 class="text-h4 font-bold text-ink">@lang('attendance.upload.unlinked-title')</h2>
        <p class="mt-1 text-body text-ink-2">@lang('attendance.upload.unlinked-lead')</p>

        @if($unlinked->isEmpty())
            <p class="mt-4 text-body text-ink-2">@lang('attendance.upload.none-unlinked')</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-start text-body">
                    <thead>
                        <tr class="border-b border-line-soft text-small text-ink-2">
                            <th class="py-2 pe-4 text-start font-semibold">@lang('attendance.upload.device-no')</th>
                            <th class="py-2 pe-4 text-start font-semibold">@lang('attendance.upload.punches')</th>
                            <th class="py-2 pe-4 text-start font-semibold">@lang('attendance.upload.seen')</th>
                            <th class="py-2 text-start font-semibold">@lang('attendance.upload.employee-id')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($unlinked as $row)
                            <tr class="border-b border-line-soft align-top" wire:key="unlinked-{{ $row->device_no }}">
                                <td class="py-2 pe-4 font-semibold num">{{ $row->device_no }}</td>
                                <td class="py-2 pe-4 num">{{ $row->punches }}</td>
                                <td class="py-2 pe-4 text-small text-ink-2 num"><bdi>{{ \Illuminate\Support\Str::of($row->first_at)->substr(0, 10) }} → {{ \Illuminate\Support\Str::of($row->last_at)->substr(0, 10) }}</bdi></td>
                                <td class="py-2">
                                    <form wire:submit.prevent="linkNumber('{{ $row->device_no }}')" class="flex items-start gap-2">
                                        <div>
                                            <input type="text" inputmode="numeric" wire:model="employeeIds.{{ $row->device_no }}"
                                                   aria-label="{{ __('attendance.upload.employee-id') }}"
                                                   class="w-32 rounded-control border border-line bg-card px-3 py-2 text-body text-ink num">
                                            @error('employeeIds.'.$row->device_no) <p class="mt-1 text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                                        </div>
                                        <x-button type="submit" variant="outline" size="sm">@lang('attendance.upload.link')</x-button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    @if($imports->isNotEmpty())
        <x-card>
            <h2 class="text-h4 font-bold text-ink">@lang('attendance.upload.recent')</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-start text-body">
                    <thead>
                        <tr class="border-b border-line-soft text-small text-ink-2">
                            <th class="py-2 pe-4 text-start font-semibold">@lang('attendance.upload.file')</th>
                            <th class="py-2 pe-4 text-start font-semibold">@lang('attendance.upload.period')</th>
                            <th class="py-2 pe-4 text-start font-semibold">@lang('attendance.upload.punches')</th>
                            <th class="py-2 pe-4 text-start font-semibold">@lang('attendance.upload.new')</th>
                            <th class="py-2 text-start font-semibold">@lang('attendance.upload.by')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($imports as $import)
                            <tr class="border-b border-line-soft">
                                <td class="py-2 pe-4 font-semibold" dir="auto">{{ $import->file_name }}</td>
                                <td class="py-2 pe-4 num"><bdi>{{ $import->period_start?->toDateString() }} → {{ $import->period_end?->toDateString() }}</bdi></td>
                                <td class="py-2 pe-4 num">{{ number_format($import->punches_total) }}</td>
                                <td class="py-2 pe-4 num">{{ number_format($import->punches_new) }}</td>
                                <td class="py-2"><bdi>{{ $import->user?->profile?->name ?? '—' }}</bdi></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
</div>
