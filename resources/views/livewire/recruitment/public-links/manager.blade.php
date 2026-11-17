@php $fmt = fn ($date) => $date?->translatedFormat('j M Y'); @endphp

<div class="space-y-4" data-public-link-manager>
    @if ($activeLink)
        {{-- The link exists: show it, or say plainly why it cannot be shown. --}}
        <div class="rounded-control border border-secondary-soft bg-secondary-soft/30 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="flex items-center gap-2 text-body font-semibold text-secondary-dark">
                    <svg class="size-4 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/></svg>
                    {{ __($acceptsSubmissions ? 'recruitment.public_link.active_until' : 'recruitment.public_link.view_only_until', ['date' => $fmt($activeLink->expires_at)]) }}
                </p>
                <span @class([
                    'rounded-full px-2.5 py-0.5 text-small font-semibold',
                    'bg-primary text-white' => $acceptsSubmissions,
                    'bg-neutral-200 text-neutral-700' => ! $acceptsSubmissions,
                ])>{{ __($acceptsSubmissions ? 'recruitment.public_link.active' : 'recruitment.public_link.view_only') }}</span>
            </div>

            @if (! $acceptsSubmissions)
                <p class="mt-2 text-small text-neutral-600">{{ __('recruitment.public_link.view_only_notice') }}</p>
            @endif

            @if ($shareUrl)
                <div class="mt-3 flex flex-col gap-2 sm:flex-row" x-data="{ copied: false }">
                    <label for="campaign-share-url" class="sr-only">{{ __('recruitment.public_link.copy_now') }}</label>
                    <input x-ref="url" id="campaign-share-url" type="text" readonly value="{{ $shareUrl }}" dir="ltr"
                        x-on:focus="$el.select()"
                        class="min-w-0 flex-1 rounded-control border border-secondary-light bg-white px-3 py-2 text-body text-secondary-dark">
                    <button type="button"
                        x-on:click="navigator.clipboard.writeText($refs.url.value); copied = true; setTimeout(() => copied = false, 1800)"
                        class="inline-flex cursor-pointer items-center justify-center gap-2 rounded-control bg-primary px-4 py-2 text-body font-semibold text-white transition hover:bg-primary-hover">
                        <svg x-show="!copied" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                        <svg x-cloak x-show="copied" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                        <span x-show="!copied">{{ __('recruitment.public_link.copy') }}</span>
                        <span x-cloak x-show="copied">{{ __('recruitment.public_link.copied') }}</span>
                    </button>
                    <a href="{{ $shareUrl }}" target="_blank" rel="noopener"
                        class="inline-flex cursor-pointer items-center justify-center gap-2 rounded-control border border-secondary-light bg-white px-4 py-2 text-body font-semibold text-secondary-dark transition hover:bg-secondary-soft/30">
                        {{ __('recruitment.public_link.open_page') }}
                        <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5h5v5m0-5-9 9M19 14v5H5V5h5"/></svg>
                    </a>
                </div>
            @else
                <p class="mt-2 text-small text-secondary-medium">{{ __('recruitment.public_link.hash_only_notice') }}</p>
            @endif
        </div>

        @if ($campaign->ends_at && $activeLink->expires_at?->lt($campaign->ends_at))
            <p class="flex items-start gap-2 text-small text-secondary-medium" data-link-expires-early>
                <svg class="mt-0.5 size-4 shrink-0 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>
                {{ __('recruitment.public_link.expires_early', ['date' => $fmt($campaign->ends_at)]) }}
            </p>
        @endif

        @unless ($linkCurrent)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-control border border-primary bg-white p-4" role="status">
                <p class="flex items-start gap-2 text-body text-secondary-dark">
                    <svg class="mt-0.5 size-4 shrink-0 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M5.1 15a7 7 0 0 0 12.7 2M18.9 9A7 7 0 0 0 6.2 7"/></svg>
                    {{ __('recruitment.public_link.stale') }}
                </p>
                @if ($mayManage)
                    <button type="button" wire:click="refreshVersions" wire:loading.attr="disabled" wire:target="refreshVersions"
                        class="cursor-pointer rounded-control bg-primary px-4 py-2 text-body font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                        {{ __('recruitment.public_link.refresh') }}
                    </button>
                @endif
            </div>
        @endunless
    @else
        <p class="text-body text-secondary-medium">
            {{ __('recruitment.public_link.will_expire', ['date' => $fmt($campaign->ends_at) ?? $fmt(now()->addDays(30))]) }}
        </p>
    @endif

    @if ($mayManage)
        <div x-data="{ custom: $wire.entangle('customExpiry') }" class="space-y-3">
            <div x-cloak x-show="custom" class="max-w-sm">
                <x-forms.input-label for="public-link-expiry">{{ __('recruitment.public_link.expires_at') }}</x-forms.input-label>
                <input id="public-link-expiry" type="date" wire:model="expiresAt"
                    class="mt-1 w-full rounded-control border border-secondary-light bg-white px-3 py-2 text-body focus:border-primary focus:ring-primary">
                <x-error-span>{{ $errors->first('expiresAt') }}</x-error-span>
                <button type="button" wire:click="useCampaignExpiry" class="mt-1 cursor-pointer text-small font-semibold text-primary underline-offset-4 hover:underline">
                    {{ __('recruitment.public_link.use_campaign_expiry') }}
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($activeLink)
                    <button type="button"
                        x-on:click="$dispatch('open-recruitment-confirmation', {
                            scope: 'public-link-manager',
                            action: 'generate',
                            title: @js(__('recruitment.public_link.regenerate')),
                            message: @js(__('recruitment.public_link.regenerate_confirm')),
                            confirmLabel: @js(__('recruitment.public_link.regenerate')),
                            tone: 'danger',
                        })"
                        class="cursor-pointer rounded-control border border-secondary-light bg-white px-4 py-2 text-body font-semibold text-secondary-dark transition hover:bg-secondary-soft/30">
                        {{ __('recruitment.public_link.regenerate') }}
                    </button>
                    <button type="button"
                        x-on:click="$dispatch('open-recruitment-confirmation', {
                            scope: 'public-link-manager',
                            action: 'disable',
                            args: [{{ $activeLink->id }}],
                            title: @js(__('recruitment.public_link.disable')),
                            message: @js(__('recruitment.public_link.disable_confirm')),
                            confirmLabel: @js(__('recruitment.public_link.disable')),
                            tone: 'danger',
                        })"
                        class="cursor-pointer rounded-control border border-primary bg-white px-4 py-2 text-body font-semibold text-primary transition hover:bg-secondary-soft/30">
                        {{ __('recruitment.public_link.disable') }}
                    </button>
                @else
                    <button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" data-generate-link
                        class="inline-flex cursor-pointer items-center gap-2 rounded-control bg-primary px-4 py-2 text-body font-semibold text-white transition hover:bg-primary-hover disabled:cursor-wait disabled:opacity-60">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/></svg>
                        {{ __('recruitment.public_link.generate') }}
                    </button>
                @endif
                <button type="button" x-show="!custom" x-on:click="custom = true"
                    class="cursor-pointer px-2 py-2 text-small font-semibold text-secondary-medium underline-offset-4 hover:text-secondary-dark hover:underline">
                    {{ __('recruitment.public_link.custom_expiry') }}
                </button>
            </div>
        </div>
    @endif

    @if ($isInvitationOnly)
        <div class="border-t border-secondary-soft pt-5">
            <h4 class="text-body font-bold text-secondary-dark">{{ __('recruitment.public_link.invites') }}</h4>
            <p class="mt-1 text-small text-secondary-medium">{{ __('recruitment.public_link.invites_help') }}</p>

            @if ($mayManage)
                <form wire:submit="addInvite" class="mt-4 grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-start">
                    <div>
                        <x-forms.input-label for="invite-name" required>{{ __('recruitment.public_link.full_name') }}</x-forms.input-label>
                        <input id="invite-name" type="text" wire:model="inviteName" autocomplete="off"
                            class="mt-1 w-full rounded-control border border-secondary-light px-3 py-2 text-body focus:border-primary focus:ring-primary">
                        <x-error-span>{{ $errors->first('inviteName') }}</x-error-span>
                    </div>
                    <div>
                        <x-forms.input-label for="invite-email" required>{{ __('recruitment.public_link.email') }}</x-forms.input-label>
                        <input id="invite-email" type="email" wire:model="inviteEmail" dir="ltr" autocomplete="off"
                            class="mt-1 w-full rounded-control border border-secondary-light px-3 py-2 text-body focus:border-primary focus:ring-primary">
                        <x-error-span>{{ $errors->first('inviteEmail') }}</x-error-span>
                    </div>
                    <button type="submit" wire:loading.attr="disabled" wire:target="addInvite"
                        class="cursor-pointer rounded-control border border-primary bg-white px-4 py-2 text-body font-semibold text-primary transition hover:bg-secondary-soft/30 md:mt-[1.65rem]">
                        {{ __('recruitment.public_link.add_invite') }}
                    </button>
                </form>
            @endif

            <div class="mt-4 overflow-x-auto rounded-control border border-secondary-soft">
                <table class="w-full text-start text-body">
                    <thead class="bg-secondary-soft/30 text-small text-secondary-medium">
                        <tr>
                            <th class="px-3 py-2 text-start font-semibold">{{ __('recruitment.public_link.full_name') }}</th>
                            <th class="px-3 py-2 text-start font-semibold">{{ __('recruitment.public_link.email') }}</th>
                            <th class="px-3 py-2 text-start font-semibold">{{ __('recruitment.status') }}</th>
                            <th class="px-3 py-2"><span class="sr-only">{{ __('recruitment.public_link.cancel_invite') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-secondary-soft">
                        @forelse ($invites as $invite)
                            <tr wire:key="campaign-invite-{{ $invite->id }}">
                                <td class="px-3 py-2 text-secondary-dark">{{ $invite->full_name }}</td>
                                <td class="px-3 py-2 text-secondary-dark"><bdi dir="ltr">{{ $invite->email_normalized }}</bdi></td>
                                <td class="px-3 py-2 text-secondary-dark">
                                    {{ __('recruitment.public_link.invite_status_'.$invite->status) }}
                                    @if ($invite->hasDeliveryFailed())
                                        <span class="block text-small text-primary">{{ __('recruitment-applicants.invite_status_failed') }} — {{ __('recruitment-applicants.invite_failure.'.$invite->failure_reason) }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-end">
                                    @if ($mayManage)
                                        <button type="button" wire:click="cancelInvite({{ $invite->id }})"
                                            class="cursor-pointer text-small font-semibold text-primary underline-offset-4 hover:underline">
                                            {{ __('recruitment.public_link.cancel_invite') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-5 text-center text-secondary-medium">{{ __('recruitment.public_link.no_invites') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <x-recruitment.confirmation-modal scope="public-link-manager" />
</div>
