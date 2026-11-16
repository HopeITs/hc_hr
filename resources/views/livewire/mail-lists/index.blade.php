<div>
    <x-slot:title>{{ __('mail_lists.title') }}</x-slot:title>

    <x-page-header :title="__('mail_lists.title')" :lead="__('mail_lists.lead')" />

    {{-- Only the person who holds the control panel sees this: who was added here and still has to be added there. --}}
    @if($canConfirm)
        <x-card class="mb-6 space-y-3" data-testid="mail-lists-pending">
            <div>
                <h2 class="text-h4 font-bold text-ink">@lang('mail_lists.pending_title')</h2>
                <p class="text-small text-ink-2">@lang('mail_lists.pending_help')</p>
            </div>

            @forelse($pending as $person)
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line-soft pt-3" wire:key="pending-{{ $person['email'] }}">
                    <div class="min-w-0">
                        <p class="font-semibold text-ink"><bdi>{{ $person['name'] ?? $person['email'] }}</bdi></p>
                        @if($person['name'])
                            <p class="text-small text-ink-2" dir="ltr">{{ $person['email'] }}</p>
                        @endif
                        <p class="text-small text-ink-2">
                            @lang('mail_lists.pending_lists'):
                            @foreach($person['lists'] as $address)
                                <code class="mx-0.5 rounded bg-soft px-1" dir="ltr">{{ $address }}</code>
                            @endforeach
                        </p>
                    </div>
                    <x-button size="sm" variant="outline" wire:click="confirmEmail('{{ $person['email'] }}')">@lang('mail_lists.pending_done')</x-button>
                </div>
            @empty
                <p class="text-body text-ink-2">@lang('mail_lists.pending_none')</p>
            @endforelse
        </x-card>
    @endif

    @if($rows->isEmpty())
        <x-center-empty-state variant="empty" :title="__('mail_lists.empty')" :description="__('mail_lists.empty_help')" />
    @else
        <div class="grid gap-4 md:grid-cols-2" data-testid="mail-lists">
            @foreach($rows as $row)
                @php($list = $row['list'])
                <x-card wire:key="list-{{ $list->id }}" class="space-y-3">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h2 class="text-h4 font-bold text-ink"><bdi>{{ $list->name }}</bdi></h2>
                            <p class="text-small text-ink-2" dir="ltr">{{ $list->address }}</p>
                        </div>
                        <span class="rounded-full bg-surface px-2 py-1 text-caption font-bold text-ink-2">
                            {{ trans_choice('mail_lists.members', $row['members']->count(), ['count' => $row['members']->count()]) }}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        {{-- Opens a new message to the list's own address; the mail provider distributes it. --}}
                        <x-button size="sm" :href="'mailto:'.$list->address" :wire:navigate="false">@lang('mail_lists.email_list')</x-button>
                        @if($canManage)
                            <button type="button" wire:click="deleteList({{ $list->id }})" wire:confirm="{{ __('mail_lists.delete_confirm') }}"
                                    class="text-small underline text-ink-2 hover:text-primary">@lang('general.delete')</button>
                        @endif
                    </div>

                    <details class="text-body">
                        <summary class="cursor-pointer text-small font-semibold text-ink">@lang('mail_lists.show_members')</summary>
                        <ul class="mt-2 divide-y divide-line-soft">
                            @foreach($row['members'] as $member)
                                <li class="flex items-center justify-between gap-2 py-1.5" wire:key="member-{{ $member['id'] }}">
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-ink"><bdi>{{ ($member['external'] && ! $canManage) ? __('mail_lists.external') : ($member['name'] ?? $member['email']) }}</bdi></span>
                                        @if($member['name'])
                                            <span class="block truncate text-caption text-ink-2" dir="ltr">{{ $member['email'] }}</span>
                                        @endif
                                        @if($canConfirm && $member['pending'])
                                            <span class="text-caption font-semibold text-ink">@lang('mail_lists.pending_tag')</span>
                                        @endif
                                        @if($canManage && $member['former'])
                                            <span class="text-caption text-ink-2">@lang('mail_lists.former')</span>
                                        @elseif($canManage && ! $member['account'])
                                            <span class="text-caption text-ink-2">@lang('mail_lists.no_account')</span>
                                        @endif
                                    </span>
                                    @if($canConfirm && $member['pending'])
                                        <button type="button" wire:click="confirmMember({{ $member['id'] }})"
                                                class="shrink-0 text-small underline text-ink hover:text-primary">@lang('mail_lists.pending_done_short')</button>
                                    @endif
                                    @if($canManage)
                                        <button type="button" wire:click="removeMember({{ $member['id'] }})"
                                                class="shrink-0 text-small underline text-ink-2 hover:text-primary">@lang('mail_lists.remove')</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        @if($canManage)
                            <form wire:submit.prevent="addMember({{ $list->id }})" class="mt-3 flex items-start gap-2">
                                <div class="min-w-0 flex-1">
                                    <x-forms.input type="email" dir="ltr" wire:model="newEmail.{{ $list->id }}" placeholder="name@hcsyria.org" />
                                    @error("newEmail.{$list->id}") <x-error-span>{{ $message }}</x-error-span> @enderror
                                </div>
                                <x-button type="submit" variant="outline" size="sm">@lang('mail_lists.add')</x-button>
                            </form>
                        @endif
                    </details>
                </x-card>
            @endforeach
        </div>
    @endif
</div>
