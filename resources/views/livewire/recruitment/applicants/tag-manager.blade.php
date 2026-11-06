<div>
    @if ($mayManage)
        @php
            $field = 'mt-1 block min-h-11 w-full rounded-control border border-neutral-300 bg-white px-3 text-body text-neutral-900 shadow-e1 focus:border-primary focus:ring-primary sm:min-h-10';
            $quiet = 'inline-flex py-2 cursor-pointer items-center rounded-control px-3 text-body font-semibold transition duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
        @endphp
        <details class="group rounded-surface border border-neutral-200 bg-white shadow-e1">
            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 rounded-surface p-5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary [&::-webkit-details-marker]:hidden">
                <span class="flex min-w-0 items-start gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-secondary-dark" aria-hidden="true">
                        <flux:icon.tag variant="mini" class="size-5" />
                    </span>
                    <span class="min-w-0">
                        <span class="flex items-center gap-2">
                            <span class="text-h4 font-semibold text-neutral-900">{{ __('recruitment-applicant.tags.manage_title') }}</span>
                            <span class="inline-flex min-w-6 items-center justify-center rounded-full border border-neutral-200 px-2 py-0.5 text-caption font-semibold tabular-nums text-secondary-dark">{{ $tags->count() }}</span>
                        </span>
                        <span class="mt-0.5 block text-small text-secondary-medium">{{ __('recruitment-applicant.tags.manage_hint') }}</span>
                    </span>
                </span>
                <flux:icon.chevron-down variant="mini" class="size-5 shrink-0 text-secondary-medium transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" aria-hidden="true" />
            </summary>

          <div class="border-t border-neutral-100 p-5">
            <form wire:submit="create" class="grid gap-3 rounded-control bg-neutral-50 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                <div class="sm:col-span-2 lg:col-span-1">
                    <x-forms.input-label for="tagName" required>{{ __('recruitment-applicant.tags.new_name') }}</x-forms.input-label>
                    <input id="tagName" type="text" wire:model="tagName" maxlength="{{ config('recruitment.tags.max_length') }}" aria-required="true"
                        @error('tagName') aria-invalid="true" aria-describedby="tagName-error" @enderror
                        class="{{ $field }}">
                </div>
                <div>
                    <x-forms.input-label for="tagKind">{{ __('recruitment-applicant.tags.kind') }}</x-forms.input-label>
                    <select id="tagKind" wire:model="tagKind"
                        @error('tagKind') aria-invalid="true" aria-describedby="tagKind-error" @enderror
                        class="{{ $field }} cursor-pointer pe-8">
                        @foreach ($kinds as $kind)
                            <option value="{{ $kind }}">{{ __('recruitment-applicant.tags.kinds.'.$kind) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-forms.input-label for="tagColor">{{ __('recruitment-applicant.tags.color') }}</x-forms.input-label>
                    <select id="tagColor" wire:model="tagColor"
                        @error('tagColor') aria-invalid="true" aria-describedby="tagColor-error" @enderror
                        class="{{ $field }} cursor-pointer pe-8">
                        @foreach ($palette as $color)
                            <option value="{{ $color }}">{{ __('recruitment-applicant.tags.colors.'.$color) }}</option>
                        @endforeach
                    </select>
                </div>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="create">{{ __('recruitment-applicant.tags.create') }}</x-primary-button>
            </form>
            <x-forms.input-error field="tagName" input-id="tagName" />
            <x-forms.input-error field="tagKind" input-id="tagKind" />
            <x-forms.input-error field="tagColor" input-id="tagColor" />

            <ul class="mt-4 divide-y divide-neutral-100 text-small">
                @forelse ($tags as $tag)
                    <li wire:key="tag-{{ $tag->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-1">
                        @if ($renamingId === $tag->id)
                            <form wire:submit="rename" class="flex flex-wrap items-end gap-2">
                                <label for="renameName" class="sr-only">{{ __('recruitment-applicant.tags.new_name') }}</label>
                                <input id="renameName" type="text" wire:model="renameName" maxlength="{{ config('recruitment.tags.max_length') }}" aria-required="true"
                                    @error('renameName') aria-invalid="true" aria-describedby="renameName-error" @enderror
                                    class="min-h-11 rounded-control border border-neutral-300 bg-white px-3 text-small shadow-e1 focus:border-primary focus:ring-primary sm:min-h-9">
                                <label for="renameKind" class="sr-only">{{ __('recruitment-applicant.tags.kind') }}</label>
                                <select id="renameKind" wire:model="renameKind"
                                    @error('renameKind') aria-invalid="true" aria-describedby="renameKind-error" @enderror
                                    class="min-h-11 rounded-control border border-neutral-300 bg-white px-3 text-small shadow-e1 focus:border-primary focus:ring-primary sm:min-h-9">
                                    @foreach ($kinds as $kind)
                                        <option value="{{ $kind }}">{{ __('recruitment-applicant.tags.kinds.'.$kind) }}</option>
                                    @endforeach
                                </select>
                                <label for="renameColor" class="sr-only">{{ __('recruitment-applicant.tags.color') }}</label>
                                <select id="renameColor" wire:model="renameColor"
                                    @error('renameColor') aria-invalid="true" aria-describedby="renameColor-error" @enderror
                                    class="min-h-11 rounded-control border border-neutral-300 bg-white px-3 text-small shadow-e1 focus:border-primary focus:ring-primary sm:min-h-9">
                                    @foreach ($palette as $color)
                                        <option value="{{ $color }}">{{ __('recruitment-applicant.tags.colors.'.$color) }}</option>
                                    @endforeach
                                </select>
                                <x-primary-button type="submit">{{ __('recruitment-applicant.tags.save') }}</x-primary-button>
                                <x-secondary-button wire:click="cancelRename">{{ __('recruitment-applicant.cancel') }}</x-secondary-button>
                                <x-forms.input-error field="renameName" input-id="renameName" class="w-full" />
                                <x-forms.input-error field="renameKind" input-id="renameKind" class="w-full" />
                                <x-forms.input-error field="renameColor" input-id="renameColor" class="w-full" />
                            </form>
                        @else
                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                <x-recruitment.pill :tone="$tag->classes()" :class="'max-w-full wrap-anywhere '.($tag->trashed() ? 'line-through opacity-60' : '')">
                                    @if ($tag->kind === \App\Models\RecruitmentTag::KIND_GREEN_FLAG)
                                        <svg class="me-1 inline size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg>
                                    @elseif ($tag->kind === \App\Models\RecruitmentTag::KIND_RED_FLAG)
                                        <svg class="me-1 inline size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.7 2.4 17.4A2 2 0 0 0 4.1 20h15.8a2 2 0 0 0 1.7-2.6L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg>
                                    @endif
                                    {{ $tag->kindLabel() }} — {{ $tag->name }}
                                </x-recruitment.pill>
                                <span class="text-secondary-medium">{{ trans_choice('recruitment-applicant.tags.usage', $tag->visible_count, ['count' => $tag->visible_count]) }}</span>
                                @if ($tag->trashed())
                                    <span class="text-xs text-secondary-medium">&middot; {{ __('recruitment-applicant.tags.deleted') }}</span>
                                @endif
                            </div>
                            {{-- Each button names its tag for a screen reader; the row shows it once. --}}
                            <div class="flex gap-1">
                                @if ($tag->trashed())
                                    <button type="button" id="tag-{{ $tag->id }}-restore" wire:click="restore({{ $tag->id }})" class="{{ $quiet }} text-secondary-dark hover:bg-selection">{{ __('recruitment-applicant.tags.restore') }}<span class="sr-only">: {{ $tag->name }}</span></button>
                                @else
                                    <button type="button" id="tag-{{ $tag->id }}-rename" wire:click="beginRename({{ $tag->id }})" class="{{ $quiet }} text-secondary-dark hover:bg-selection">{{ __('recruitment-applicant.tags.rename') }}<span class="sr-only">: {{ $tag->name }}</span></button>
                                    <button type="button" wire:click="delete({{ $tag->id }})" wire:confirm="{{ __('recruitment-applicant.tags.delete_confirm') }}"
                                        class="{{ $quiet }} text-primary hover:bg-selection">{{ __('recruitment-applicant.tags.delete') }}<span class="sr-only">: {{ $tag->name }}</span></button>
                                @endif
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="rounded-control border border-dashed border-neutral-300 px-4 py-5 text-center text-secondary-medium">{{ __('recruitment-applicant.tags.none_defined') }}</li>
                @endforelse
            </ul>
          </div>
        </details>
    @endif
</div>
