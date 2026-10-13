{{-- Internal labels. Nothing here can change an application's status. --}}
<div class="mt-4">
    <h4 id="tags-{{ $applicationId }}-title" tabindex="-1" class="scroll-mt-24 text-sm font-semibold">{{ __('recruitment-applicant.tags.title') }}</h4>

    <ul class="mt-2 flex flex-wrap gap-2">
        @forelse ($assignments as $assignment)
            <li wire:key="assignment-{{ $assignment->id }}"
                title="{{ __('recruitment-applicant.tags.applied_by', [
                    'name' => $assignment->creator?->profile?->name ?? $assignment->creator?->name ?? '—',
                    'date' => $assignment->created_at?->format('Y-m-d H:i') ?? '—',
                ]) }}"
                class="inline-flex max-w-full items-center gap-1 rounded-control px-2 py-0.5 text-xs font-semibold wrap-anywhere {{ $assignment->tag->classes() }}">
                {{ $assignment->tag->name }}
                <span class="font-normal opacity-75">&middot; {{ $assignment->creator?->profile?->name ?? $assignment->creator?->name ?? '—' }}, {{ $assignment->created_at?->format('Y-m-d') }}</span>
                @if ($mayTag)
                    <button type="button" wire:click="detach({{ $assignment->tag_id }})" wire:loading.attr="disabled"
                        class="-my-1 -me-1 ms-0.5 inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-full hover:bg-black/10 disabled:opacity-50" aria-label="{{ __('recruitment-applicant.tags.remove', ['name' => $assignment->tag->name]) }}"><svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg></button>
                @endif
            </li>
        @empty
            <li class="text-sm text-secondary-medium">{{ __('recruitment-applicant.tags.none') }}</li>
        @endforelse
    </ul>

    @if ($mayTag)
        <div class="mt-3 flex flex-wrap items-end gap-2">
            @if ($available->isNotEmpty())
                <div>
                    <label for="tag-{{ $applicationId }}" class="sr-only">{{ __('recruitment-applicant.tags.tag') }}</label>
                    <select id="tag-{{ $applicationId }}" wire:model="tagId"
                        @error('tagId') aria-invalid="true" aria-describedby="tag-{{ $applicationId }}-error" @enderror
                        class="rounded-control border-neutral-300 text-sm">
                        <option value="">{{ __('recruitment-applicant.tags.choose') }}</option>
                        @foreach ($available as $tag)
                            <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-secondary-button wire:click="attach" wire:target="attach">
                    {{ __('recruitment-applicant.tags.add') }}
                </x-secondary-button>
            @endif

            @if ($mayCreate)
                <form wire:submit="createAndAttach" class="flex flex-wrap items-end gap-2">
                    <label for="new-tag-{{ $applicationId }}" class="sr-only">{{ __('recruitment-applicant.tags.new_name') }}</label>
                    <input id="new-tag-{{ $applicationId }}" type="text" wire:model="newTagName" maxlength="{{ config('recruitment.tags.max_length') }}"
                        @error('tagName') aria-invalid="true" aria-describedby="new-tag-{{ $applicationId }}-error" @enderror
                        placeholder="{{ __('recruitment-applicant.tags.new_name') }}" class="rounded-control border-neutral-300 text-sm">
                    <label for="new-tag-color-{{ $applicationId }}" class="sr-only">{{ __('recruitment-applicant.tags.color') }}</label>
                    <select id="new-tag-color-{{ $applicationId }}" wire:model="newTagColor"
                        @error('tagColor') aria-invalid="true" aria-describedby="new-tag-color-{{ $applicationId }}-error" @enderror
                        class="rounded-control border-neutral-300 text-sm">
                        @foreach ($palette as $color)
                            <option value="{{ $color }}">{{ __('recruitment-applicant.tags.colors.'.$color) }}</option>
                        @endforeach
                    </select>
                    <x-secondary-button type="submit" wire:target="createAndAttach">
                        {{ __('recruitment-applicant.tags.create_and_add') }}
                    </x-secondary-button>
                </form>
            @endif
        </div>
        <x-forms.input-error field="tagId" :input-id="'tag-'.$applicationId" />
        <x-forms.input-error field="tagName" :input-id="'new-tag-'.$applicationId" />
        <x-forms.input-error field="tagColor" :input-id="'new-tag-color-'.$applicationId" />
    @endif
</div>
