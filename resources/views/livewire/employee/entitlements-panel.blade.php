{{--
    Permissions Library items of one person (meeting 19/11, track 5). Only HR of
    the person's city and their direct manager see it. Status is icon + words +
    fill shape: to do = ink outline, done = surface, revoked = dashed.
--}}
@php
    use App\Models\EmployeeEntitlement;

    $locale = app()->getLocale();
    $date = fn ($value) => $value?->timezone(config('app.timezone'))->translatedFormat('d M Y');
    $by = fn ($user) => $user?->profile?->nameForLocale($locale) ?? $user?->email ?? __('general.unknown');

    $tones = [
        EmployeeEntitlement::STATE_REQUIRED => ['attn', 'alert'],
        EmployeeEntitlement::STATE_GRANTED => ['done', 'check-circle'],
        EmployeeEntitlement::STATE_REVOKED => ['stop', 'ban'],
    ];
@endphp

<div>
    @if ($allowed)
        {{-- Not <x-profile-section>: that renders its body twice (phone and desktop), and this one holds a form. --}}
        <section data-section="entitlements" data-testid="entitlements-panel" aria-labelledby="entitlements-title"
            class="rounded-surface border border-line-soft bg-card p-4 shadow-e1 sm:p-9">
            <div class="mb-3 flex items-center gap-3">
                <div class="rounded-surface bg-surface p-2 text-ink sm:p-3"><x-icons.check-plus class="h-4 w-4 !me-0 sm:h-6 sm:w-6" /></div>
                <h2 id="entitlements-title" class="text-h4 font-extrabold text-ink">@lang('entitlements.panel_title')</h2>
            </div>
            <p class="mb-3 text-small text-ink-2">@lang('entitlements.panel_lead')</p>

            @if ($rows->isEmpty())
                <p class="text-body text-ink-2">@lang('entitlements.panel_empty')</p>
            @else
                <ul class="divide-y divide-line-soft">
                    @foreach ($rows as $row)
                        @php [$tone, $icon] = $tones[$row->state]; @endphp
                        <li wire:key="ent-row-{{ $row->id }}" data-state="{{ $row->state }}" class="grid gap-2 py-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 font-medium text-ink">
                                    <bdi>{{ $row->entitlement?->name }}</bdi>
                                    <x-status :tone="$tone" :icon="$icon" :label="__('entitlements.state.'.$row->state)" />
                                    <x-tag variant="soft" :label="__('entitlements.categories.'.$row->entitlement?->category)" />
                                    @if ($row->source === EmployeeEntitlement::SOURCE_MANUAL)
                                        <x-tag variant="soft" :label="__('entitlements.source.manual')" />
                                    @endif
                                    @if ($row->needs_review_at)
                                        <x-tag variant="act" :label="__('entitlements.needs_review')" />
                                    @endif
                                </p>
                                <p class="mt-1 text-small text-ink-2">
                                    @if ($row->state === EmployeeEntitlement::STATE_GRANTED)
                                        {{ __('entitlements.granted_by', ['date' => $date($row->granted_at), 'name' => $by($row->grantedBy)]) }}
                                    @elseif ($row->state === EmployeeEntitlement::STATE_REVOKED)
                                        {{ __('entitlements.revoked_by', ['date' => $date($row->revoked_at), 'name' => $by($row->revokedBy)]) }}
                                    @endif
                                    @if ($row->needs_review_at)
                                        @lang('entitlements.needs_review_help')
                                    @endif
                                    @if ($row->entitlement?->mailList)
                                        <span dir="ltr">{{ $row->entitlement->mailList->address }}</span>
                                    @elseif ($row->entitlement?->reference)
                                        <span dir="ltr">{{ $row->entitlement->reference }}</span>
                                    @endif
                                </p>
                                @if ($row->note)
                                    <p class="mt-1 text-small text-ink-2">{{ $row->note }}</p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-start gap-2 sm:justify-end">
                                @if ($row->state === EmployeeEntitlement::STATE_REQUIRED)
                                    <x-button variant="outline" size="sm" icon="check" wire:click="markGranted({{ $row->id }})" data-testid="ent-grant-{{ $row->id }}">@lang('entitlements.mark_granted')</x-button>
                                @endif
                                @if ($row->needs_review_at)
                                    <x-button variant="quiet" size="sm" wire:click="keep({{ $row->id }})">@lang('entitlements.keep')</x-button>
                                @endif
                                @if ($row->state !== EmployeeEntitlement::STATE_REVOKED)
                                    <x-button variant="quiet" size="sm" wire:click="revoke({{ $row->id }})" wire:confirm="{{ __('entitlements.revoke_confirm') }}">@lang('entitlements.revoke')</x-button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($available->isNotEmpty())
                <form wire:submit="add" class="mt-4 grid gap-2 border-t border-line-soft pt-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                    <div>
                        <x-forms.input-label for="ent-add" :value="__('entitlements.add_item')" />
                        <x-forms.select-input id="ent-add" wire:model="addId">
                            <option value="">—</option>
                            @foreach ($available as $entitlement)
                                <option value="{{ $entitlement->id }}">{{ $entitlement->name }}</option>
                            @endforeach
                        </x-forms.select-input>
                        <x-forms.input-error field="addId" input-id="ent-add" />
                    </div>
                    <div>
                        <x-forms.input-label for="ent-note" :value="__('entitlements.note')" />
                        <x-forms.input id="ent-note" model="note" />
                    </div>
                    <x-button type="submit" variant="outline" target="add" data-testid="ent-add-submit">@lang('entitlements.add')</x-button>
                </form>
            @endif
        </section>
    @endif
</div>
