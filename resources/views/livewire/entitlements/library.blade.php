{{--
    Permissions Library (meeting 19/11, track 5): the items and the positions
    (or job levels) that need them by default. Nothing here is enforced by the
    system: an item is a record of access granted elsewhere.
--}}
<div>
    <x-slot:title>{{ __('entitlements.title') }}</x-slot:title>

    <x-page-header :title="__('entitlements.title')" :lead="__('entitlements.lead')">
        <x-slot:actions>
            @unless ($editing)
                <x-button size="sm" icon="plus" wire:click="newItem" data-testid="entitlement-new">@lang('entitlements.new')</x-button>
            @endunless
        </x-slot:actions>
    </x-page-header>

    @if ($editing)
        <section class="mb-4 rounded-surface border border-line-soft bg-card p-4 shadow-e1" data-testid="entitlement-form">
            <h2 class="mb-3 text-h4 font-bold text-ink">{{ $item['id'] ? __('entitlements.edit') : __('entitlements.new') }}</h2>
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-forms.input-label for="item-name-en" :value="__('entitlements.name_en')" required />
                    <x-forms.input id="item-name-en" model="item.name_en" dir="ltr" required />
                    <x-forms.input-error field="item.name_en" input-id="item-name-en" />
                </div>
                <div>
                    <x-forms.input-label for="item-name-ar" :value="__('entitlements.name_ar')" required />
                    <x-forms.input id="item-name-ar" model="item.name_ar" dir="rtl" required />
                    <x-forms.input-error field="item.name_ar" input-id="item-name-ar" />
                </div>
                <div>
                    <x-forms.input-label for="item-category" :value="__('entitlements.category')" required />
                    <x-forms.select-input id="item-category" wire:model.live="item.category">
                        @foreach (\App\Models\Entitlement::CATEGORIES as $category)
                            <option value="{{ $category }}">@lang('entitlements.categories.'.$category)</option>
                        @endforeach
                    </x-forms.select-input>
                    <x-forms.input-error field="item.category" input-id="item-category" />
                </div>
                @if ($item['category'] === 'mail_list')
                    <div>
                        <x-forms.input-label for="item-mail-list" :value="__('entitlements.mail_list')" />
                        <x-forms.select-input id="item-mail-list" wire:model="item.mail_list_id">
                            <option value="">—</option>
                            @foreach ($mailLists as $list)
                                <option value="{{ $list->id }}">{{ $list->name }} ({{ $list->address }})</option>
                            @endforeach
                        </x-forms.select-input>
                        <x-forms.input-error field="item.mail_list_id" input-id="item-mail-list" />
                    </div>
                @endif
                <div>
                    <x-forms.input-label for="item-reference" :value="__('entitlements.reference')" />
                    <x-forms.input id="item-reference" model="item.reference" dir="ltr" :hint="__('entitlements.reference_hint')" />
                    <x-forms.input-error field="item.reference" input-id="item-reference" />
                </div>
                <div class="sm:col-span-2">
                    <x-forms.input-label for="item-description" :value="__('entitlements.description')" />
                    <x-forms.textarea id="item-description" wire:model="item.description" rows="2" />
                    <x-forms.input-error field="item.description" input-id="item-description" />
                </div>
                <div class="sm:col-span-2">
                    <x-forms.input id="item-active" type="checkbox" model="item.is_active">@lang('entitlements.active')</x-forms.input>
                </div>
                <div class="flex flex-wrap gap-2 sm:col-span-2">
                    <x-button type="submit" target="save" data-testid="entitlement-save">@lang('general.save')</x-button>
                    <x-button variant="quiet" wire:click="cancelEdit">@lang('general.cancel')</x-button>
                </div>
            </form>
        </section>
    @endif

    @if ($items->isEmpty())
        <x-center-empty-state variant="empty" :title="__('entitlements.empty')" :description="__('entitlements.empty_help')" />
    @else
        <ul class="space-y-3" data-testid="entitlement-list">
            @foreach ($items as $entry)
                <li wire:key="entitlement-{{ $entry->id }}" class="rounded-surface border border-line-soft bg-card p-4 shadow-e1">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="flex flex-wrap items-center gap-2 font-semibold text-ink">
                                <bdi>{{ $entry->name }}</bdi>
                                <x-tag variant="soft" :label="__('entitlements.categories.'.$entry->category)" />
                                @unless ($entry->is_active)
                                    <x-status tone="stop" icon="ban" :label="__('entitlements.inactive')" />
                                @endunless
                            </h2>
                            <p class="mt-1 text-small text-ink-2">
                                {{ trans_choice('entitlements.defaults_count', $entry->defaults_count, ['count' => $entry->defaults_count]) }}
                                · {{ trans_choice('entitlements.holders_count', $entry->open_count, ['count' => $entry->open_count]) }}
                                @if ($entry->mailList)
                                    · <span dir="ltr">{{ $entry->mailList->address }}</span>
                                @elseif ($entry->reference)
                                    · <span dir="ltr">{{ $entry->reference }}</span>
                                @endif
                            </p>
                            @if ($entry->description)
                                <p class="mt-1 text-small text-ink-2">{{ $entry->description }}</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($open?->id === $entry->id)
                                <x-button variant="quiet" size="sm" wire:click="close">@lang('entitlements.hide_defaults')</x-button>
                            @else
                                <x-button variant="outline" size="sm" wire:click="open({{ $entry->id }})" data-testid="entitlement-open-{{ $entry->id }}">@lang('entitlements.defaults')</x-button>
                            @endif
                            <x-button variant="quiet" size="sm" wire:click="edit({{ $entry->id }})"><x-ic name="pen" class="size-4" /> @lang('general.edit')</x-button>
                            <x-button variant="quiet" size="sm" wire:click="toggleActive({{ $entry->id }})">
                                {{ $entry->is_active ? __('entitlements.deactivate') : __('entitlements.activate') }}
                            </x-button>
                            @if ($entry->open_count === 0)
                                <x-button variant="quiet" size="sm" wire:click="deleteItem({{ $entry->id }})" wire:confirm="{{ __('entitlements.delete_confirm') }}"><x-ic name="trash" class="size-4" /> @lang('general.delete')</x-button>
                            @endif
                        </div>
                    </div>

                    @if ($open?->id === $entry->id)
                        <div class="mt-4 border-t border-line-soft pt-4" data-testid="entitlement-defaults">
                            <h3 class="font-semibold text-ink">@lang('entitlements.defaults')</h3>
                            <p class="text-small text-ink-2">@lang('entitlements.defaults_help')</p>

                            @if ($links->isEmpty())
                                <p class="mt-2 text-small text-ink-2">@lang('entitlements.no_defaults')</p>
                            @else
                                <ul class="mt-2 divide-y divide-line-soft">
                                    @foreach ($links as $link)
                                        <li wire:key="link-{{ $link->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                                            <span class="min-w-0 text-ink">
                                                @if ($link->jobLevel)
                                                    <x-tag variant="soft" :label="__('entitlements.job_level')" />
                                                    <bdi>{{ $link->jobLevel->name }}</bdi>
                                                @elseif ($link->position)
                                                    <bdi>{{ $link->position->title }}</bdi>
                                                    <span class="text-small text-ink-2">· {{ $link->position->center?->name ?? $link->position->city?->name }}</span>
                                                @endif
                                            </span>
                                            @if ($mayTouch($link))
                                                <x-button variant="quiet" size="sm" wire:click="removeLink({{ $link->id }})">@lang('entitlements.unlink')</x-button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <x-forms.input-label for="position-search" :value="__('entitlements.add_position')" />
                                    <x-forms.input id="position-search" wire:model.live.debounce.300ms="positionSearch" :placeholder="__('entitlements.search_position')" />
                                    @if ($matches->isNotEmpty())
                                        <ul class="mt-2 divide-y divide-line-soft rounded-control border border-line-soft">
                                            @foreach ($matches as $position)
                                                <li wire:key="match-{{ $position->id }}" class="flex items-center justify-between gap-2 px-3 py-2">
                                                    <span class="min-w-0 text-ink"><bdi>{{ $position->title }}</bdi>
                                                        <span class="text-small text-ink-2">· {{ $position->center?->name ?? $position->city?->name }}</span></span>
                                                    <x-button variant="outline" size="sm" wire:click="addPosition({{ $position->id }})">@lang('entitlements.link')</x-button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @elseif (mb_strlen(trim($positionSearch)) >= 2)
                                        <p class="mt-2 text-small text-ink-2">@lang('entitlements.no_positions')</p>
                                    @endif
                                </div>
                                @if ($canLinkJobLevels)
                                    <div>
                                        <x-forms.input-label for="job-level" :value="__('entitlements.add_job_level')" />
                                        <div class="flex gap-2">
                                            <x-forms.select-input id="job-level" wire:model="jobLevelId">
                                                <option value="">—</option>
                                                @foreach ($jobLevels as $level)
                                                    <option value="{{ $level->id }}">{{ $level->name }}</option>
                                                @endforeach
                                            </x-forms.select-input>
                                            <x-button variant="outline" size="sm" wire:click="addJobLevel">@lang('entitlements.link')</x-button>
                                        </div>
                                        <x-forms.hint id="job-level-hint">@lang('entitlements.job_level_help')</x-forms.hint>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
