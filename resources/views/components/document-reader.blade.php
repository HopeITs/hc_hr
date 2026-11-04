{{--
    "Read from a scanned file" panel (ReadsScannedDocuments). The files bind to
    the component's $documentScans; the button that reads them is the `action`
    slot (wire:click on a Livewire form, an Alpine call in the contract
    composer), and the default slot is the notice after a reading.

      <x-document-reader :title="…" :hint="…">
          <x-slot:action><x-button wire:click="readIdCard" …>…</x-button></x-slot:action>
          …notice…
      </x-document-reader>
--}}
@props(['title', 'hint'])

<section {{ $attributes->merge(['class' => 'rounded-surface border border-line-soft bg-soft p-4']) }}
    aria-labelledby="document-reader-title">
    <div class="flex flex-wrap items-start gap-x-4 gap-y-3">
        <x-ic name="file" class="mt-0.5 size-5 shrink-0 text-ink-2" />
        <div class="min-w-0 flex-1 basis-60">
            <h2 id="document-reader-title" class="text-body font-bold text-ink">{{ $title }}</h2>
            <p class="mt-0.5 text-small text-ink-2">{{ $hint }}</p>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-3">
        <label class="sr-only" for="document-scans">@lang('documents.reader.files')</label>
        <input type="file" id="document-scans" wire:model="documentScans" multiple
            accept="image/jpeg,image/png,image/webp,application/pdf"
            class="min-w-0 flex-1 basis-64 text-small text-ink-2 file:me-3 file:rounded-control file:border file:border-line
                   file:bg-card file:px-3 file:py-1.5 file:text-small file:font-semibold file:text-ink hover:file:bg-hover" />
        {{ $action }}
    </div>

    <p wire:loading wire:target="documentScans" class="mt-2 text-small text-ink-2">@lang('documents.reader.uploading')</p>
    <x-error-span>{{ $errors->first('documentScans') ?: $errors->first('documentScans.*') }}</x-error-span>

    {{ $slot }}
</section>
