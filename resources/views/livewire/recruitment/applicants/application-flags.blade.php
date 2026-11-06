<div class="mt-4 rounded-control border border-neutral-200 bg-white p-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h4 class="text-sm font-semibold">{{ __('recruitment-applicant.flags.title') }}</h4>
            <p class="mt-1 text-xs text-secondary-medium">{{ __('recruitment-applicant.flags.hint') }}</p>
        </div>
    </div>

    @if ($flags->isNotEmpty())
        <ul class="mt-3 space-y-2">
            @foreach ($flags as $flag)
                <li wire:key="application-flag-{{ $flag->id }}" class="flex flex-wrap items-start justify-between gap-3 rounded-control border border-neutral-200 p-3 text-sm">
                    <div class="min-w-0">
                        <span class="inline-flex items-center gap-1 rounded-control px-2 py-0.5 text-xs font-semibold {{ $flag->type === \App\Models\RecruitmentApplicationFlag::TYPE_RED ? 'border border-primary bg-white text-primary' : 'bg-neutral-100 text-secondary-dark' }}">
                            @if ($flag->type === \App\Models\RecruitmentApplicationFlag::TYPE_RED)
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.7 2.4 17.4A2 2 0 0 0 4.1 20h15.8a2 2 0 0 0 1.7-2.6L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg>
                            @else
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg>
                            @endif
                            {{ $flag->typeLabel() }}
                        </span>
                        <span class="ms-2 font-semibold">{{ $flag->categoryLabel() }}: {{ $flag->reasonLabel() }}</span>
                        @if ($flag->note)
                            <p class="mt-1 whitespace-pre-line text-secondary-medium">{{ $flag->note }}</p>
                        @endif
                        <p class="mt-1 text-xs text-secondary-medium">{{ $flag->creator?->profile?->name ?? $flag->creator?->name ?? '—' }} · {{ $flag->created_at?->format('Y-m-d') }}</p>
                    </div>
                    @if ($mayFlag)
                        <button type="button" wire:click="discardFlag({{ $flag->id }})" wire:confirm="{{ __('recruitment-applicant.flags.discard_confirm') }}" class="cursor-pointer text-xs font-semibold text-primary hover:underline">{{ __('recruitment-applicant.flags.discard') }}</button>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-3 text-sm text-secondary-medium">{{ __('recruitment-applicant.flags.none') }}</p>
    @endif

    @if ($mayFlag)
        <form wire:submit="create" class="mt-4 grid gap-3 border-t border-neutral-200 pt-4 md:grid-cols-2">
            <div>
                <x-forms.input-label for="flagType" required>{{ __('recruitment-applicant.flags.type') }}</x-forms.input-label>
                <select id="flagType" wire:model="type" class="mt-1 w-full rounded-control border-neutral-300 text-sm">
                    @foreach (\App\Models\RecruitmentApplicationFlag::types() as $option)
                        <option value="{{ $option }}">{{ __('recruitment-applicant.flags.types.'.$option) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-forms.input-label for="flagCategory" required>{{ __('recruitment-applicant.flags.category') }}</x-forms.input-label>
                <select id="flagCategory" wire:model.live="category" class="mt-1 w-full rounded-control border-neutral-300 text-sm">
                    @foreach ($categories as $option)
                        <option value="{{ $option }}">{{ __('recruitment-applicant.flags.categories.'.$option) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-forms.input-label for="flagReason" required>{{ __('recruitment-applicant.flags.reason') }}</x-forms.input-label>
                <select id="flagReason" wire:model="reason" class="mt-1 w-full rounded-control border-neutral-300 text-sm">
                    @foreach ($reasons as $option => $translationKey)
                        <option value="{{ $option }}">{{ __('recruitment-applicant.flags.reasons.'.$translationKey) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-forms.input-label for="flagNote">{{ __('recruitment-applicant.flags.note') }}</x-forms.input-label>
                <textarea id="flagNote" wire:model="note" maxlength="1000" rows="2" class="mt-1 w-full rounded-control border-neutral-300 text-sm"></textarea>
                <x-forms.input-error field="flagNote" input-id="flagNote" />
            </div>
            <div class="md:col-span-2">
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="create" class="!w-auto">{{ __('recruitment-applicant.flags.add') }}</x-primary-button>
            </div>
        </form>
    @endif
</div>
