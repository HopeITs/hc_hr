@props([
    'previewType' => 'image',
    'oldFile' => null,
    'fileName' => null
])

<div class="mt-2 flex items-center space-x-6">
    @php
        $tempUrl = null;
        if ($oldFile instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
            try {
                $tempUrl = $oldFile->temporaryUrl();
            } catch (\Exception $e) {
                $tempUrl = null;
            }
        }
    @endphp

    @if ($previewType === 'avatar')
        <div class="shrink-0">
            @if ($tempUrl)
                <img class="size-20 rounded-full object-cover" src="{{ $tempUrl }}" alt="{{ __('employees.file-input.new-profile-photo') }}"/>
            @elseif ($oldFile && ! $tempUrl)
                @if (is_string($oldFile))
                    <img class="size-20 rounded-full object-cover" src="{{ $oldFile }}" alt="{{ __('employees.file-input.current-profile-photo') }}"/>
                @else
                    <div class="size-24 rounded-full flex border-[1.5px] border-line bg-soft items-center justify-center">
                        <x-icons.user class="size-14 text-ink-3"/>
                    </div>
                @endif
            @else
                <div class="size-24 rounded-full flex border-[1.5px] border-line bg-soft items-center justify-center">
                    <x-icons.user class="size-14 text-ink-3"/>
                </div>
            @endif
        </div>
    @endif

    @if ($previewType === 'image')
        <div class="shrink-0">
            @if ($tempUrl)
                <img class="w-28 rounded-control object-cover" src="{{ $tempUrl }}" alt="{{ __('employees.file-input.new-photo') }}"/>
            @elseif ($oldFile && ! $tempUrl)
                @if (is_string($oldFile))
                    <img class="w-28 rounded-control object-cover" src="{{ $oldFile }}" alt="{{ __('employees.file-input.current-photo') }}"/>
                @else
                    <div class="w-24 rounded-control bg-surface flex flex-col items-center justify-center">
                        <x-icons.image class="size-12"/>
                        <span class="text-small uppercase text-ink-2">{{ __('employees.file-input.no-image') }}</span>
                    </div>
                @endif
            @else
                <div class="w-24 rounded-control bg-surface flex flex-col items-center justify-center">
                    <x-icons.image class="size-12"/>
                    <span class="text-small uppercase text-ink-2">{{ __('employees.file-input.no-image') }}</span>
                </div>
            @endif
        </div>
    @endif

    @if ($previewType === 'pdf')
        @php
            /**
             * `$fileName` describes the STORED file, so it must never label a
             * freshly picked one — a staged upload names itself. And a staged
             * file whose preview URL could not be signed still has to show
             * that it is there, rather than falling back to "NO FILE".
             */
            $stagedFile = $oldFile instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile ? $oldFile : null;
            $pdfLabel = $stagedFile ? $stagedFile->getClientOriginalName() : ($fileName ?: null);
            $pdfHref = $tempUrl ?: (! $stagedFile && is_string($oldFile) && $oldFile !== '' ? $oldFile : null);
        @endphp

        <div class="shrink-0">
            @if ($pdfHref)
                <a href="{{ $pdfHref }}" target="_blank" class="flex flex-col justify-center">
                    <div class="w-24 rounded-control bg-surface flex flex-col items-center justify-center">
                        <x-icons.pdf-file />
                    </div>
                    <span class="text-small text-ink-2 text-center mt-2 w-24 break-words">
                        <bdi>{{ $pdfLabel ?? __('employees.file-input.file') }}</bdi>
                    </span>
                </a>
            @else
                <div class="w-24 rounded-control bg-surface flex flex-col items-center justify-center">
                    <x-icons.pdf-file />
                    @unless ($pdfLabel)
                        <span class="text-small uppercase text-ink-2">{{ __('employees.file-input.no-file') }}</span>
                    @endunless
                </div>
                @if ($pdfLabel)
                    <span class="block text-small text-ink-2 text-center mt-2 w-24 break-words">
                        <bdi>{{ $pdfLabel }}</bdi>
                    </span>
                @endif
            @endif
        </div>
    @endif

    <div>
        {{--
            No `value`: a file input ignores it (browsers refuse to let script
            set one), so all it ever did was stamp the STORED file's name onto
            the markup and contradict whatever the user had just picked.
        --}}
        <input {{ $attributes->merge([
            'class' => 'block w-full text-body text-ink-2 px-4 file:me-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-body file:font-semibold file:bg-surface file:text-ink hover:file:bg-line',
            'type' => 'file',
        ]) }}/>
        {{--
            Guarded: with no `name` attribute (every wire:model-only usage),
            $errors->first(null) returns the FIRST message in the whole bag,
            which printed an unrelated error under every such input.
        --}}
        {{--
            B2: a picked file lives only in this page's Livewire state (and as a
            temporary upload the server clears after a day). It survives a failed
            save, but not leaving or reloading the page; say so instead of
            implying the browser keeps it.
        --}}
        @if ($oldFile instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
            <p class="mt-2 px-4 text-caption text-ink-2" data-testid="staged-file-hint">
                {{ __('employees.file-input.staged') }}
            </p>
        @endif
        @if ($attributes->get('name'))
            <x-error-span>{{ $errors->first($attributes->get('name')) }}</x-error-span>
        @endif
    </div>
</div>
