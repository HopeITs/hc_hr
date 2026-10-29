<x-slot:title>
    {{ __('general.all-badges') }}
</x-slot:title>

<div x-data @open-badge-modal.window="$wire.openBadgeModal($event.detail.id)">
    <div class="flex items-center justify-between mb-6">
        <livewire:layout.breadcrumbs :title="__('general.all-badges')" :crumbs="[['label' => __('general.all-badges')]]" />
    </div>

    <livewire:badges.badges-table />

    {{-- Modal triggered by the View button in BadgesTable --}}
    <x-modal.dialog id="badgeDetails" :title="$modalTitle" wire:model="showModal">
        <x-slot name="title">
            <span class="text-red-600">{{ $modalTitle }}</span>
        </x-slot>

        <div class="space-y-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-field-caption class="text-red-600">{{ trans_choice('general.employee', 1) }}</x-field-caption>
                    <div class="font-medium text-neutral-900"><bdi>{{ $badge['employee'] ?? '—' }}</bdi></div>
                </div>
                <div>
                    <x-field-caption class="text-red-600">@lang('general.type')</x-field-caption>
                    <div class="inline-flex items-center gap-2">
                        <span class="rounded-full px-2 py-1 text-small font-semibold ring-1 ring-inset ring-neutral-300">
                            {{ $badge['type'] ?? '—' }}
                        </span>
                    </div>
                </div>
                @if(!empty($badge['subtype']))
                    <div>
                        <x-field-caption class="text-red-600">{{ $badge['detail_label'] ?? __('general.badge-detail') }}</x-field-caption>
                        <div>{{ $badge['subtype'] }}</div>
                    </div>
                @endif
                <div>
                    <x-field-caption class="text-red-600">@lang('general.date')</x-field-caption>
                    <div><bdi>{{ $badge['date'] ?? '—' }}</bdi></div>
                </div>
                <div>
                    <x-field-caption class="text-red-600">{{ trans_choice('general.program', 1) }}</x-field-caption>
                    <div>{{ $badge['program'] ?? '—' }}</div>
                </div>
                <div>
                    <x-field-caption class="text-red-600">{{ trans_choice('general.department', 1) }}</x-field-caption>
                    <div>{{ $badge['department'] ?? '—' }}</div>
                </div>
            </div>

            @if(!empty($badge['subtype']))
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    @if(!empty($badge['is_financial_detail']))
                        <div>
                            <x-field-caption class="text-red-600">@lang('general.financial-amount')</x-field-caption>
                            <div>
                                @if(!empty($badge['amount']))
                                    <bdi>{{ number_format((float)$badge['amount'], 2) }} {{ $badge['currency'] ?? '' }}</bdi>
                                @else
                                    —
                                @endif
                            </div>
                        </div>
                    @endif

                    @if(!empty($badge['is_promotion']))
                        <div>
                            <x-field-caption class="text-red-600">@lang('general.new-position')</x-field-caption>
                            <div>{{ $badge['new_position'] ?? '—' }}</div>
                        </div>
                    @endif

                    @if(!empty($badge['is_leave']))
                        <div>
                            <x-field-caption class="text-red-600">@lang('general.leave-calculation')</x-field-caption>
                            <div>{{ $badge['leave_unit'] ?? '—' }}</div>
                        </div>
                        <div>
                            <x-field-caption class="text-red-600">{{ $badge['leave_amount_label'] ?? __('general.leave-quantity') }}</x-field-caption>
                            <div>
                                @if(!empty($badge['amount']))
                                    {{ number_format((float)$badge['amount']) }}
                                @else
                                    —
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <div>
                <x-field-caption class="text-red-600">@lang('general.reason')</x-field-caption>
                <div class="whitespace-pre-line rounded-control border border-neutral-200 bg-neutral-50 p-3 text-body">
                    {{ $badge['reason'] ?? '—' }}
                </div>
            </div>

            @if(!empty($badge['is_salary']))
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <x-field-caption class="text-red-600">@lang('general.currency')</x-field-caption>
                        <div>{{ $badge['currency'] ?? '—' }}</div>
                    </div>
                    <div>
                        <x-field-caption class="text-red-600">@lang('general.current_salary')</x-field-caption>
                        <div>
                            @if(!empty($badge['current_salary']))
                                <bdi>{{ number_format((float)$badge['current_salary'], 2) }} {{ $badge['currency'] ?? '' }}</bdi>
                            @else
                                —
                            @endif
                        </div>
                    </div>
                    <div>
                        <x-field-caption class="text-red-600">@lang('general.new-salary')</x-field-caption>
                        <div>
                            @if(!empty($badge['new_salary']))
                                <bdi>{{ number_format((float)$badge['new_salary'], 2) }} {{ $badge['currency'] ?? '' }}</bdi>
                            @else
                                —
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            <div>
                <x-field-caption class="text-red-600">@lang('general.notes')</x-field-caption>
                <div class="whitespace-pre-line rounded-control border border-neutral-200 bg-neutral-50 p-3 text-body">
                    {{ $badge['notes'] ?? '—' }}
                </div>
            </div>
        </div>

        <div class="mt-6 flex justify-end">
            <x-modal.cancel-btn />
        </div>
    </x-modal.dialog>
</div>
