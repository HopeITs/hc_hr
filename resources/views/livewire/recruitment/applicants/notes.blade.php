{{-- Internal only. Nothing in this panel can change an application's status. --}}
<section class="rounded-surface border border-secondary-soft bg-white p-4 sm:p-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="notes-title" tabindex="-1" class="scroll-mt-24 text-h4 font-semibold">{{ __('recruitment-applicant.notes.title') }}</h2>
        <p class="text-sm text-secondary-medium">{{ __('recruitment-applicant.notes.internal_hint') }}</p>
    </div>

    @if ($mayAdd && ($applications->isNotEmpty() || $mayAddUnattached))
        <form wire:submit="addNote" class="mt-4 space-y-3">
            <div>
                <x-forms.input-label for="noteApplication" :required="! $mayAddUnattached">{{ __('recruitment-applicant.notes.about') }}</x-forms.input-label>
                <select id="noteApplication" wire:model="applicationId" @if (! $mayAddUnattached) aria-required="true" @endif
                    @error('applicationId') aria-invalid="true" aria-describedby="noteApplication-error" @enderror
                    class="mt-1 w-full rounded-control border-secondary-light">
                    @if ($mayAddUnattached)
                        <option value="">{{ __('recruitment-applicant.notes.about_applicant') }}</option>
                    @else
                        <option value="">—</option>
                    @endif
                    @foreach ($applications as $application)
                        <option value="{{ $application->id }}">
                            {{ $application->campaign?->vacancy?->position?->title ?? __('recruitment-applicant.no_vacancy') }}
                            &middot; {{ $application->submitted_at?->format('Y-m-d') }}
                        </option>
                    @endforeach
                </select>
                <x-forms.input-error field="applicationId" input-id="noteApplication" />
            </div>
            <div>
                <x-forms.input-label for="noteBody" required>{{ __('recruitment-applicant.notes.body') }}</x-forms.input-label>
                <textarea id="noteBody" wire:model="body" rows="3" maxlength="{{ \App\Services\RecruitmentApplicantNoteService::MAX_BODY }}" aria-required="true"
                    @error('body') aria-invalid="true" aria-describedby="noteBody-error" @enderror
                    class="mt-1 w-full rounded-control border-secondary-light"></textarea>
                <x-forms.input-error field="body" input-id="noteBody" />
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="sensitive" class="rounded border-secondary-light">
                {{ __('recruitment-applicant.notes.sensitive') }}
            </label>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="addNote">
                {{ __('recruitment-applicant.notes.add') }}
            </x-primary-button>
        </form>
    @endif

    <ul class="mt-6 space-y-4">
        @forelse ($notes as $note)
            <li wire:key="note-{{ $note->id }}" class="rounded-control border border-secondary-soft p-4">
                <div class="flex flex-wrap items-start justify-between gap-2 text-sm">
                    <p class="min-w-0 text-secondary-medium">
                        <span class="font-semibold text-secondary-dark">{{ $note->actor?->profile?->name ?? $note->actor?->name ?? '—' }}</span>
                        &middot; {{ $note->created_at?->format('Y-m-d H:i') }}
                        &middot; {{ $note->application?->campaign?->vacancy?->position?->title ?? __('recruitment-applicant.notes.about_applicant') }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($note->note_type === \App\Models\RecruitmentApplicantNote::TYPE_CV_ARCHIVE)
                            <span class="rounded-control bg-secondary-soft/60 px-2 py-0.5 text-xs font-semibold text-secondary-dark">{{ __('recruitment-applicant.notes.type_cv_archive') }}</span>
                        @endif
                        @if ($note->is_sensitive)
                            <span class="rounded-control bg-primary/5 px-2 py-0.5 text-xs font-semibold text-primary">{{ __('recruitment-applicant.notes.sensitive_badge') }}</span>
                        @endif
                        @can('delete', $note)
                            <button type="button" id="note-{{ $note->id }}-delete" wire:click="confirmDelete({{ $note->id }})" class="cursor-pointer text-xs font-semibold text-primary hover:underline">
                                {{ __('recruitment-applicant.notes.delete') }}
                            </button>
                        @endcan
                    </div>
                </div>
                {{-- A pasted link or reference number has no spaces to break at. --}}
                <p class="mt-2 whitespace-pre-line wrap-break-word">{{ $note->body }}</p>

                @if ($deletingNote?->id === $note->id)
                    <div class="mt-3 rounded-control border border-primary/30 bg-primary/5 p-3">
                        <p id="deletionReason-hint" class="text-sm text-primary">{{ __('recruitment-applicant.notes.delete_hint') }}</p>
                        <x-forms.input-label for="deletionReason" class="mt-2" :required="$note->is_sensitive">{{ __('recruitment-applicant.notes.deletion_reason') }}</x-forms.input-label>
                        {{-- Focus lands on the field; the hint is read with it. --}}
                        <textarea id="deletionReason" wire:model="deletionReason" rows="2" @if ($note->is_sensitive) aria-required="true" @endif
                            aria-describedby="deletionReason-hint @error('deletionReason') deletionReason-error @enderror" @error('deletionReason') aria-invalid="true" @enderror
                            class="mt-1 w-full scroll-mt-24 rounded-control border-secondary-light"></textarea>
                        <x-forms.input-error field="deletionReason" input-id="deletionReason" />
                        <div class="mt-3 flex flex-wrap gap-3">
                            <x-primary-button type="button" wire:click="deleteNote" wire:target="deleteNote">
                                {{ __('recruitment-applicant.notes.delete_confirm') }}
                            </x-primary-button>
                            <x-secondary-button wire:click="cancelDelete">
                                {{ __('recruitment-applicant.cancel') }}
                            </x-secondary-button>
                        </div>
                    </div>
                @endif
            </li>
        @empty
            <li class="text-sm text-secondary-medium">{{ __('recruitment-applicant.notes.empty') }}</li>
        @endforelse
    </ul>
</section>
