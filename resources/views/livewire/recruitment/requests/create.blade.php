@php
    $isEditing = (bool) ($editing ?? false);
    $pageTitle = $isEditing ? __('recruitment.form_ui.edit_title') : __('recruitment.create');
    $offerSubmit = (bool) ($canSubmit ?? false);
@endphp
<x-slot:title>{{ $pageTitle }}</x-slot:title>

<div class="mx-auto max-w-5xl">
    <livewire:layout.breadcrumbs :title="$pageTitle" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment.navigation_requests'), 'route' => 'recruitment.requests.index'], ['label' => $pageTitle]]" />

    <x-page-header :title="$pageTitle" :lead="__('recruitment.form_ui.lead')" />

    {{--
        Unsaved-changes guard: the form's `data` is compared with what it held
        when the page opened, through the app's one registry
        (resources/js/unsaved.js), so leaving by a link, a reload or closing
        the tab asks first. Saving switches it off for the redirect that
        follows; a save that comes back with errors switches it on again.
    --}}
    <form wire:submit="save" novalidate
        class="space-y-6 [&_:is(input,select,textarea)]:scroll-mb-32"
        x-data="{
            saving: false,
            init() {
                const wireId = $wire.$id;
                const read = () => JSON.stringify(window.Livewire?.find(wireId)?.$get('data') ?? null);
                const baseline = read();
                $unsaved.register('recruitment-request:' + wireId, {
                    wireId,
                    dirty: () => ! this.saving && read() !== baseline,
                });
            },
        }"
        x-on:submit="saving = true"
        x-on:input="saving = false"
        x-on:change="saving = false">

        @if ($errors->any())
            {{-- Re-keyed on every render, so it runs again after each failed save. --}}
            <span hidden wire:key="request-errors-{{ \Illuminate\Support\Str::random(8) }}" x-init="saving = false"></span>
        @endif

        @if ($isEditing && filled($changeRequest ?? null))
            <section role="note" aria-labelledby="changes-requested-title"
                class="rounded-surface border border-line-soft border-s-4 border-s-primary bg-card p-5 shadow-e1">
                <div class="flex items-start gap-3">
                    <x-ic name="return" class="mt-0.5 size-5 shrink-0 text-primary" />
                    <div class="min-w-0 flex-1">
                        <p id="changes-requested-title" class="text-body font-bold text-ink">{{ __('recruitment.changes_requested_edit_title') }}</p>
                        <p class="mt-1 text-small text-ink-2">{{ __('recruitment.changes_requested_edit_hint') }}</p>
                        @if (filled($changeRequest['fields'] ?? null))
                            <ul class="mt-3 flex flex-wrap gap-2" aria-label="{{ __('recruitment.form_ui.requested_fields') }}">
                                @foreach ($changeRequest['fields'] as $requestedField)
                                    <li><x-tag variant="soft">{{ $requestedField['label'] }}</x-tag></li>
                                @endforeach
                            </ul>
                        @endif
                        @if (filled($changeRequest['comment'] ?? null))
                            <div class="mt-4 rounded-control border border-line-soft bg-soft p-4">
                                <p class="text-caption font-bold uppercase tracking-wide text-ink-2">{{ __('recruitment.approver_comment') }}</p>
                                <p class="mt-1.5 whitespace-pre-line text-body text-ink" dir="auto">{{ $changeRequest['comment'] }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        @include('livewire.recruitment.requests.partials.request-fields')

        {{-- Sticky action bar: stays reachable on a long form, and sits in the
             flow at the end so it never covers the last field. On phones it
             rides above the app's bottom navigation. --}}
        <footer class="sticky bottom-0 z-20 -mx-4 border-t border-line-soft bg-card px-4 py-3 shadow-e1 max-md:bottom-[calc(4.75rem+env(safe-area-inset-bottom,0px))] sm:mx-0 sm:rounded-surface sm:border sm:px-5">
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                <p class="min-w-0 max-w-[60ch] flex-1 basis-64 text-small text-ink-2">
                    {{ $offerSubmit ? __('recruitment.form_ui.footer_note_submit') : __('recruitment.draft_help') }}
                </p>
                {{-- A disabled fieldset disables both buttons while either action runs. --}}
                <fieldset class="flex w-full flex-wrap items-center gap-2 sm:w-auto max-sm:[&>*]:flex-1"
                    wire:loading.attr="disabled" wire:target="save,saveAndSubmit">
                    <legend class="sr-only">{{ __('recruitment.form_ui.actions') }}</legend>
                    <x-button type="submit" :variant="$offerSubmit ? 'outline' : 'primary'" target="save">
                        {{ __('recruitment.save_draft') }}
                    </x-button>
                    @if ($offerSubmit)
                        <x-button wire:click="saveAndSubmit" x-on:click="saving = true" icon="send">
                            {{ __('recruitment.form_ui.save_and_submit') }}
                        </x-button>
                    @endif
                </fieldset>
            </div>
        </footer>
    </form>
</div>
