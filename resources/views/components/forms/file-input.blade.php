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
                <img class="size-20 rounded-full object-cover" src="{{ $tempUrl }}" alt="New profile photo"/>
            @elseif ($oldFile && ! $tempUrl)
                @if (is_string($oldFile))
                    <img class="size-20 rounded-full object-cover" src="{{ $oldFile }}" alt="Current profile photo"/>
                @else
                    <div class="size-24 rounded-full flex border border-1 border-primary items-center justify-center">
                        <x-icons.user class="size-14 text-primary"/>
                    </div>
                @endif
            @else
                <div class="size-24 rounded-full flex border border-1 border-primary items-center justify-center">
                    <x-icons.user class="size-14 text-primary"/>
                </div>
            @endif
        </div>
    @endif

    @if ($previewType === 'image')
        <div class="shrink-0">
            @if ($tempUrl)
                <img class="w-28 rounded-control object-cover" src="{{ $tempUrl }}" alt="New photo"/>
            @elseif ($oldFile && ! $tempUrl)
                @if (is_string($oldFile))
                    <img class="w-28 rounded-control object-cover" src="{{ $oldFile }}" alt="Current photo"/>
                @else
                    <div class="w-24 rounded-control bg-neutral-200 flex flex-col items-center justify-center">
                        <x-icons.image class="size-12"/>
                        <span class="text-small text-secondary-medium">NO IMAGE</span>
                    </div>
                @endif
            @else
                <div class="w-24 rounded-control bg-neutral-200 flex flex-col items-center justify-center">
                    <x-icons.image class="size-12"/>
                    <span class="text-small text-secondary-medium">NO IMAGE</span>
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
                    <div class="w-24 rounded-control bg-neutral-200 flex flex-col items-center justify-center">
                        <x-icons.pdf-file />
                    </div>
                    <span class="text-small text-secondary-medium text-center mt-2 w-24 break-words">
                        {{ $pdfLabel ?? 'FILE' }}
                    </span>
                </a>
            @else
                <div class="w-24 rounded-control bg-neutral-200 flex flex-col items-center justify-center">
                    <x-icons.pdf-file />
                    @unless ($pdfLabel)
                        <span class="text-small text-secondary-medium">NO FILE</span>
                    @endunless
                </div>
                @if ($pdfLabel)
                    <span class="block text-small text-secondary-medium text-center mt-2 w-24 break-words">
                        {{ $pdfLabel }}
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
            'class' => 'block w-full text-body text-slate-500 px-4 file:me-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-body file:font-semibold file:bg-secondary-light file:text-white hover:file:bg-secondary-medium',
            'type' => 'file',
        ]) }}/>
        {{--
            Guarded: with no `name` attribute (every wire:model-only usage),
            $errors->first(null) returns the FIRST message in the whole bag,
            which printed an unrelated error under every such input.
        --}}
        @if ($attributes->get('name'))
            <x-error-span>{{ $errors->first($attributes->get('name')) }}</x-error-span>
        @endif
    </div>
</div>
