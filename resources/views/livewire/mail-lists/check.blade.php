@php
    $copied = __('mail_lists.copied');
    $copyIcon = '<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h8"/></svg>';
@endphp

<div x-data="{
        copy(text) {
            navigator.clipboard?.writeText(text).then(() => window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: @js($copied) } })));
        },
     }">
    <x-slot:title>{{ __('mail_lists.check.title') }}</x-slot:title>

    <x-page-header :title="__('mail_lists.check.title')" :lead="__('mail_lists.check.lead')">
        <x-slot:actions><x-button variant="outline" size="sm" :href="route('mail-lists.index')">
            <x-ic name="users" class="size-4" /> @lang('mail_lists.title')
        </x-button></x-slot:actions>
    </x-page-header>

    @if($checked->isEmpty())
        <x-center-empty-state variant="empty" :title="__('mail_lists.check.none')" :description="__('mail_lists.check.none_help')" />
    @endif

    <div class="space-y-4" data-testid="mail-list-check">
        @foreach($rows as $row)
            @php($list = $row['list'])
            @php($result = $row['result'])
            <section wire:key="check-{{ $list->id }}" class="rounded-surface border border-line-soft bg-card p-4 shadow-e1">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="truncate text-h4 font-bold text-ink"><bdi>{{ $list->name }}</bdi></h2>
                        <p class="truncate text-small text-ink-2" dir="ltr">{{ $list->address }}</p>
                        <p class="mt-1 text-small text-ink-2">
                            @if($list->rule_type === \App\Models\MailList::RULE_ALL_STAFF) @lang('mail_lists.check.rule_all_staff')
                            @elseif($list->rule_type === \App\Models\MailList::RULE_BOARD) @lang('mail_lists.check.rule_board')
                            @elseif($list->rule_type === \App\Models\MailList::RULE_CUSTOM)
                                @lang('mail_lists.check.rule_custom'):
                                {{ collect([
                                    $list->rule_city_id ? $cities->firstWhere('id', $list->rule_city_id)?->name : null,
                                    $list->rule_program_id ? $programs->firstWhere('id', $list->rule_program_id)?->name : null,
                                    $list->rule_department_id ? $departments->firstWhere('id', $list->rule_department_id)?->name : null,
                                    $list->rule_managers_only ? __('mail_lists.check.managers_only') : null,
                                ])->filter()->implode(' · ') }}
                            @else @lang('mail_lists.check.no_rule')
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($result)
                            @if($result['in_sync'])
                                <x-status tone="done" icon="check" :label="__('mail_lists.check.in_sync')" />
                            @else
                                <x-status tone="attn" icon="clock" :label="__('mail_lists.check.differs')" />
                            @endif
                        @endif
                        @if($canManage)
                            <x-button size="sm" variant="outline" wire:click="editRule({{ $list->id }})">
                                <x-ic name="pen" class="size-4" /> @lang('mail_lists.check.edit_rule')
                            </x-button>
                        @endif
                    </div>
                </div>

                @if($canManage && $editId === $list->id)
                    <form wire:submit.prevent="saveRule" class="mt-4 grid gap-3 rounded-control bg-soft p-3 sm:grid-cols-2" data-testid="rule-form">
                        <label class="block text-small font-semibold text-ink">@lang('mail_lists.check.rule')
                            <select wire:model.live="ruleType" class="mt-1 block w-full rounded-control border border-line bg-card py-2 ps-3 pe-8 text-body text-ink">
                                <option value="">@lang('mail_lists.check.no_rule')</option>
                                <option value="all_staff">@lang('mail_lists.check.rule_all_staff')</option>
                                <option value="board">@lang('mail_lists.check.rule_board')</option>
                                <option value="custom">@lang('mail_lists.check.rule_custom')</option>
                            </select>
                            @error('ruleType') <x-error-span>{{ $message }}</x-error-span> @enderror
                        </label>

                        @if($ruleType === 'custom')
                            <label class="block text-small font-semibold text-ink">@lang('mail_lists.check.city')
                                <select wire:model="ruleCity" class="mt-1 block w-full rounded-control border border-line bg-card py-2 ps-3 pe-8 text-body text-ink">
                                    <option value="">—</option>
                                    @foreach($cities as $city)<option value="{{ $city->id }}">{{ $city->name }}</option>@endforeach
                                </select>
                            </label>
                            <label class="block text-small font-semibold text-ink">@lang('mail_lists.check.program')
                                <select wire:model="ruleProgram" class="mt-1 block w-full rounded-control border border-line bg-card py-2 ps-3 pe-8 text-body text-ink">
                                    <option value="">—</option>
                                    @foreach($programs as $program)<option value="{{ $program->id }}">{{ $program->name }}</option>@endforeach
                                </select>
                            </label>
                            <label class="block text-small font-semibold text-ink">@lang('mail_lists.check.department')
                                <select wire:model="ruleDepartment" class="mt-1 block w-full rounded-control border border-line bg-card py-2 ps-3 pe-8 text-body text-ink">
                                    <option value="">—</option>
                                    @foreach($departments as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach
                                </select>
                            </label>
                            <label class="flex items-center gap-2 self-end text-small font-semibold text-ink">
                                <input type="checkbox" wire:model="ruleManagersOnly" class="size-4 rounded border-line"> @lang('mail_lists.check.managers_only')
                            </label>
                        @endif

                        <div class="flex gap-2 sm:col-span-2">
                            <x-button type="submit" size="sm">@lang('mail_lists.check.save')</x-button>
                            <x-button type="button" size="sm" variant="quiet" wire:click="cancelRule">@lang('mail_lists.check.cancel')</x-button>
                        </div>
                    </form>
                @endif

                @if($result && ! $result['in_sync'])
                    <div class="mt-4 grid gap-6 xl:grid-cols-3">
                        {{-- On HR data, not on the list. --}}
                        <div data-testid="check-missing" x-data="{ all: false }">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="font-bold text-ink">@lang('mail_lists.check.missing') <span class="num text-ink-2">{{ $result['missing']->count() }}</span></h3>
                                @if($canManage && $result['missing']->isNotEmpty())
                                    <x-button size="sm" wire:click="queueMissing({{ $list->id }})">
                                        <x-ic name="plus" class="size-4" /> @lang('mail_lists.check.queue_all')
                                    </x-button>
                                @endif
                            </div>
                            <ul class="mt-2 divide-y divide-line-soft">
                                @foreach($result['missing'] as $i => $person)
                                    <li class="flex items-center justify-between gap-2 py-2" x-show="all || {{ $i }} < 8" wire:key="m-{{ $list->id }}-{{ $person['email'] }}">
                                        <span class="min-w-0"><bdi class="block truncate font-semibold text-ink">{{ $person['name'] }}</bdi><span class="block truncate text-caption text-ink-2" dir="ltr">{{ $person['email'] }}</span></span>
                                        @if($canManage)
                                            <button type="button" wire:click="queueOne({{ $list->id }}, '{{ $person['email'] }}')" title="{{ __('mail_lists.check.queue_one') }}" aria-label="{{ __('mail_lists.check.queue_one') }}"
                                                    class="grid size-8 shrink-0 place-items-center rounded-control text-ink transition hover:bg-hover"><x-ic name="plus" class="size-4" /></button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            @if(count($result['missing']) > 8)
                                <button type="button" x-on:click="all = ! all" class="mt-1 text-small font-semibold underline text-ink-2 hover:text-ink" x-text="all ? @js(__('mail_lists.check.show_less')) : @js(__('mail_lists.check.show_all', ['count' => count($result['missing'])]))"></button>
                            @endif
                        </div>

                        {{-- On the list, not matching its rule. --}}
                        <div data-testid="check-not-matching" x-data="{ all: false }">
                            <h3 class="font-bold text-ink">@lang('mail_lists.check.not_matching') <span class="num text-ink-2">{{ $result['not_matching']->count() }}</span></h3>
                            <p class="text-caption text-ink-2">@lang('mail_lists.check.not_matching_help')</p>
                            <ul class="mt-2 divide-y divide-line-soft">
                                @foreach($result['not_matching'] as $i => $person)
                                    <li class="flex items-center justify-between gap-2 py-2" x-show="all || {{ $i }} < 8" wire:key="n-{{ $list->id }}-{{ $person['id'] }}">
                                        <span class="min-w-0"><bdi class="block truncate font-semibold text-ink">{{ $person['name'] }}</bdi><span class="block truncate text-caption text-ink-2" dir="ltr">{{ $person['email'] }}</span></span>
                                        @if($canManage)
                                            <button type="button" wire:click="removeMember({{ $person['id'] }})" wire:confirm="{{ __('mail_lists.remove_confirm') }}" title="{{ __('mail_lists.remove') }}" aria-label="{{ __('mail_lists.remove') }}"
                                                    class="grid size-8 shrink-0 place-items-center rounded-control text-ink-3 transition hover:bg-hover hover:text-primary"><x-ic name="trash" class="size-4" /></button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            @if(count($result['not_matching']) > 8)
                                <button type="button" x-on:click="all = ! all" class="mt-1 text-small font-semibold underline text-ink-2 hover:text-ink" x-text="all ? @js(__('mail_lists.check.show_less')) : @js(__('mail_lists.check.show_all', ['count' => count($result['not_matching'])]))"></button>
                            @endif
                        </div>

                        {{-- Left the organisation, still on the list. --}}
                        <div data-testid="check-left" x-data="{ all: false }">
                            <h3 class="font-bold text-ink">@lang('mail_lists.check.left') <span class="num text-ink-2">{{ $result['left']->count() }}</span></h3>
                            <p class="text-caption text-ink-2">@lang('mail_lists.check.left_help')</p>
                            <ul class="mt-2 divide-y divide-line-soft">
                                @foreach($result['left'] as $i => $person)
                                    <li class="flex items-center justify-between gap-2 py-2" x-show="all || {{ $i }} < 8" wire:key="l-{{ $list->id }}-{{ $person['id'] }}">
                                        <span class="min-w-0"><bdi class="block truncate font-semibold text-ink">{{ $person['name'] }}</bdi><span class="block truncate text-caption text-ink-2" dir="ltr">{{ $person['email'] }}</span></span>
                                        <span class="flex shrink-0 items-center gap-1">
                                            <button type="button" x-on:click="copy(@js($person['email']))" title="{{ __('mail_lists.copy_email') }}" aria-label="{{ __('mail_lists.copy_email') }}"
                                                    class="grid size-8 place-items-center rounded-control text-ink-3 transition hover:bg-hover hover:text-ink">{!! $copyIcon !!}</button>
                                            @if($canManage)
                                                <button type="button" wire:click="removeMember({{ $person['id'] }})" wire:confirm="{{ __('mail_lists.remove_confirm') }}" title="{{ __('mail_lists.remove') }}" aria-label="{{ __('mail_lists.remove') }}"
                                                        class="grid size-8 place-items-center rounded-control text-ink-3 transition hover:bg-hover hover:text-primary"><x-ic name="trash" class="size-4" /></button>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                            @if(count($result['left']) > 8)
                                <button type="button" x-on:click="all = ! all" class="mt-1 text-small font-semibold underline text-ink-2 hover:text-ink" x-text="all ? @js(__('mail_lists.check.show_less')) : @js(__('mail_lists.check.show_all', ['count' => count($result['left'])]))"></button>
                            @endif
                        </div>
                    </div>
                @endif
            </section>
        @endforeach
    </div>
</div>
