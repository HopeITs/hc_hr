{{--
    The amendments of one contract (ملحق عقد), newest first: what each changed
    and from when, and the steps of the one in flight. Rendered on the
    contract page only when there is something to show or something to do.
--}}
<div>
    @if($amendments->isNotEmpty() || $canAmend)
        <x-card :title="__('contracts.amendment.list-title')" data-tour="contract-details.amendments">
            <x-slot:icon>
                @if($canAmend)
                    <x-button variant="outline" size="sm" :href="route('contracts.amend', $contract->id)" wire:navigate>
                        <x-ic name="plus" class="size-4" />
                        @lang('contracts.amendment.add')
                    </x-button>
                @endif
            </x-slot:icon>

            @if($amendments->isEmpty())
                <p class="text-small text-ink-2">@lang('contracts.amendment.none')</p>
            @else
                <ol class="divide-y divide-line-soft">
                    @foreach($amendments as $amendment)
                        @php
                            $lines = $changes[$amendment->id] ?? [];
                            $signed = $amendment->getFirstMedia('signed');
                        @endphp
                        <li class="py-3 first:pt-0 last:pb-0" wire:key="amendment-{{ $amendment->id }}">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="font-semibold text-ink">
                                    @lang('contracts.amendment.number', ['number' => $amendment->number])
                                </span>
                                <x-status :status="$amendment->statusEnum()" />
                                <span class="num text-small text-ink-2">
                                    @lang('contracts.amendment.effective-from-summary', ['date' => $amendment->effective_from->format('d/m/Y')])
                                </span>
                            </div>

                            @if($lines !== [])
                                <dl class="mt-2 grid gap-1 text-small sm:grid-cols-[auto_minmax(0,1fr)] sm:gap-x-4">
                                    @foreach($lines as [$label, $from, $to])
                                        <dt class="text-ink-2">{{ $label }}</dt>
                                        <dd class="num text-ink">
                                            @if($from !== '' || $to !== '')
                                                <span class="text-ink-2">{{ $from }}</span>
                                                <span aria-hidden="true" class="text-ink-3">{{ app()->getLocale() === 'ar' ? '←' : '→' }}</span>
                                                <span class="sr-only">@lang('contracts.amendment.becomes')</span>
                                                <span class="font-semibold">{{ $to }}</span>
                                            @endif
                                        </dd>
                                    @endforeach
                                </dl>
                            @endif

                            @if(filled($amendment->reason))
                                <p class="mt-2 text-small text-ink-2">{{ $amendment->reason }}</p>
                            @endif

                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                @can('decideAmendment', $amendment)
                                    <x-button size="sm" wire:click="askApprove({{ $amendment->id }})">
                                        @lang('contracts.amendment.approve')
                                    </x-button>
                                    <x-button size="sm" variant="quiet" wire:click="askDiscard({{ $amendment->id }})">
                                        @lang('contracts.amendment.discard')
                                    </x-button>
                                @endcan

                                @if($signed)
                                    @can('exportAmendment', $amendment)
                                        <x-button size="sm" variant="link"
                                                  :href="route('contracts.amendments.signed', [$contract->id, $amendment->id])">
                                            <x-ic name="file" class="size-4" />
                                            @lang('contracts.amendment.signed-copy')
                                        </x-button>
                                    @endcan
                                @else
                                    @can('attachSignedAmendment', $amendment)
                                        <label class="inline-flex cursor-pointer items-center gap-1.5 text-small font-semibold text-ink underline-offset-2 hover:underline">
                                            <x-ic name="plus" class="size-4" />
                                            @lang('contracts.amendment.attach-signed')
                                            <input type="file" class="sr-only" accept=".pdf,.jpg,.jpeg,.png,.webp"
                                                   wire:model="signedFiles.{{ $amendment->id }}" />
                                        </label>
                                        <span wire:loading wire:target="signedFiles.{{ $amendment->id }}" class="text-caption text-ink-2">
                                            @lang('general.saving')
                                        </span>
                                    @endcan
                                @endif
                            </div>
                            <x-error-span>{{ $errors->first('signedFiles.'.$amendment->id) }}</x-error-span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-card>
    @endif
</div>
