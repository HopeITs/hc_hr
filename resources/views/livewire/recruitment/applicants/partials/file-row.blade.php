{{-- One applicant file. Expects $file, and the profile's $archiveFile and
     $archiveNeedsReason; the archive panel opens inside the row it concerns.
     $panelHeading follows the heading level of the list around it. --}}
@php
    use Illuminate\Support\Facades\Lang;

    $scan = $file->scan_status;
    $typeKey = 'recruitment-applicant.file_types.'.$file->file_type;
    $typeLabel = Lang::has($typeKey) ? __($typeKey) : str($file->file_type)->headline();
    $panelHeading ??= 'h4';
@endphp
<li wire:key="file-{{ $file->id }}" id="file-{{ $file->id }}" tabindex="-1" class="flex scroll-mt-24 flex-wrap items-center justify-between gap-3 py-2">
    <div class="min-w-0">
        <span class="font-medium">{{ $typeLabel }}</span>
        <span class="text-secondary-medium">&middot; {{ $file->uploaded_at?->format('Y-m-d') }}</span>
        @if ($scan !== \App\Enums\RecruitmentFileScanStatus::Clean)
            <span class="recruitment-badge ms-1 rounded-control border px-2 py-0.5 text-xs font-semibold {{ $scan === \App\Enums\RecruitmentFileScanStatus::Pending ? 'border-neutral-300 bg-neutral-100 text-secondary-dark' : 'border-primary bg-white text-primary' }}">{{ __('recruitment-applicant.scan.'.$scan) }}</span>
        @endif
        @if ($file->isArchived())
            <span class="ms-1 rounded-control bg-neutral-100 px-2 py-0.5 text-xs font-semibold text-secondary-dark">
                {{ __('recruitment-applicant.cv_archive.archived_on', ['date' => $file->archived_at->format('Y-m-d'), 'name' => $file->archivedBy?->profile?->name ?? $file->archivedBy?->name ?? '—']) }}
            </span>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-3">
        {{-- Every row has the same two words; the hidden part says which file. --}}
        @can('download', $file)
            <a href="{{ route('recruitment.applicant-files.download', $file->id) }}" class="font-semibold text-primary hover:underline">
                {{ __('recruitment-applicant.download') }}<span class="sr-only">: {{ $typeLabel }}, {{ $file->uploaded_at?->format('Y-m-d') }}</span>
            </a>
        @endcan
        @can('archive', $file)
            <button type="button" id="archive-{{ $file->id }}" wire:click="beginArchive({{ $file->id }})" class="cursor-pointer font-semibold text-secondary-medium hover:underline">
                {{ __('recruitment-applicant.cv_archive.archive') }}<span class="sr-only">: {{ $typeLabel }}, {{ $file->uploaded_at?->format('Y-m-d') }}</span>
            </button>
        @endcan
    </div>

    @if ($archiveFile?->id === $file->id)
        <div wire:key="archive-panel-{{ $file->id }}" class="w-full rounded-control border border-neutral-200 bg-neutral-50 p-4">
            <{{ $panelHeading }} id="archive-panel-{{ $file->id }}" tabindex="-1" class="scroll-mt-24 font-semibold">{{ __('recruitment-applicant.cv_archive.archive_title') }}</{{ $panelHeading }}>
            <p id="archiveReason-hint" class="mt-1 text-secondary-medium">
                {{ $archiveNeedsReason ? __('recruitment-applicant.cv_archive.archive_hint_no_vacancy') : __('recruitment-applicant.cv_archive.archive_hint') }}
            </p>
            <div class="mt-4">
                <x-forms.input-label for="archiveReason" :required="$archiveNeedsReason">{{ __('recruitment-applicant.cv_archive.reason') }}</x-forms.input-label>
                <textarea id="archiveReason" wire:model="archiveReason" rows="3" @if ($archiveNeedsReason) aria-required="true" @endif
                    aria-describedby="archiveReason-hint @error('archiveReason') archiveReason-error @enderror" @error('archiveReason') aria-invalid="true" @enderror
                    class="mt-1 w-full rounded-control border-neutral-300"></textarea>
                <x-forms.input-error field="archiveReason" input-id="archiveReason" />
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-primary-button type="button" wire:click="archiveCv" wire:loading.attr="disabled" wire:target="archiveCv">
                    {{ __('recruitment-applicant.cv_archive.archive_confirm') }}
                </x-primary-button>
                <x-secondary-button wire:click="cancelArchive">
                    {{ __('recruitment-applicant.cancel') }}
                </x-secondary-button>
            </div>
        </div>
    @endif
</li>
