{{-- HR: upload the policy documents, and choose which ones a new volunteer must read and agree to (track 7). --}}
<div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8" data-testid="required-policies">
    <x-page-header :title="__('onboarding.required-policies.title')" :lead="__('onboarding.required-policies.lead')">
        <x-slot:crumb><a href="{{ route('policies.index') }}" wire:navigate>← @lang('policies.back')</a></x-slot:crumb>
    </x-page-header>

    <x-card>
        <form wire:submit="save" class="space-y-3" data-testid="policy-upload">
            <h2 class="font-semibold text-ink">
                {{ $replacing ? __('onboarding.required-policies.replace-heading') : __('onboarding.required-policies.upload-heading') }}
            </h2>
            <p class="text-small text-ink-2">@lang('onboarding.required-policies.upload-hint')</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="space-y-1 text-small text-ink-2">
                    <span>@lang('onboarding.required-policies.file')</span>
                    <input type="file" wire:model="upload" accept=".pdf,.docx,.txt"
                        class="block w-full text-small text-ink" data-testid="policy-file">
                </label>
                <label class="space-y-1 text-small text-ink-2">
                    <span>@lang('onboarding.required-policies.doc-title')</span>
                    <input type="text" wire:model="uploadTitle" maxlength="255"
                        placeholder="@lang('onboarding.required-policies.doc-title-hint')"
                        class="block w-full rounded-control border border-line bg-card px-2 py-1 text-ink">
                </label>
            </div>
            @error('upload') <p class="text-small text-primary">{{ $message }}</p> @enderror
            @error('uploadTitle') <p class="text-small text-primary">{{ $message }}</p> @enderror
            <div class="flex flex-wrap items-center gap-2">
                <x-button type="submit" variant="primary" size="sm" wire:loading.attr="disabled" wire:target="save,upload">
                    @lang('onboarding.required-policies.upload')
                </x-button>
                @if ($replacing)
                    <x-button type="button" variant="quiet" size="sm" wire:click="cancelReplace">@lang('onboarding.required-policies.cancel')</x-button>
                @endif
                <span class="text-small text-ink-2" wire:loading wire:target="save">@lang('onboarding.required-policies.reading')</span>
            </div>
        </form>
    </x-card>

    <x-card>
        <ul class="divide-y divide-line-soft">
            @forelse ($documents as $document)
                <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="doc-{{ $document->id }}">
                    <span class="font-medium text-ink">{{ $document->title }}</span>
                    <select class="rounded-control border border-line bg-card px-2 py-1 text-small text-ink" aria-label="@lang('onboarding.required-policies.kind')"
                        wire:change="setKind({{ $document->id }}, $event.target.value)" data-testid="kind-{{ $document->id }}">
                        @foreach (\App\Models\PolicyDocument::KINDS as $kind)
                            <option value="{{ $kind }}" @selected($document->onboarding_kind === $kind)>@lang('onboarding.required-policies.kind-'.$kind)</option>
                        @endforeach
                    </select>
                    <label class="flex items-center gap-2 text-small text-ink-2">
                        <input type="checkbox" class="rounded border-line" @checked($document->required_for_onboarding)
                            wire:change="setRequired({{ $document->id }}, $event.target.checked)"
                            data-testid="required-{{ $document->id }}">
                        @lang('onboarding.required-policies.required')
                    </label>
                    <span class="flex gap-2">
                        <x-button type="button" variant="outline" size="sm" wire:click="startReplace({{ $document->id }})">@lang('onboarding.required-policies.replace')</x-button>
                        <x-button type="button" variant="quiet" size="sm" wire:click="delete({{ $document->id }})"
                            wire:confirm="{{ __('onboarding.required-policies.delete-confirm') }}">@lang('onboarding.required-policies.delete')</x-button>
                    </span>
                </li>
            @empty
                <li class="py-3 text-body text-ink-2">@lang('onboarding.required-policies.none')</li>
            @endforelse
        </ul>
    </x-card>
</div>
