@php use App\Enums\RecruitmentEvaluationRecommendation as Recommendation; @endphp
<div>
    @if ($canEvaluate)
        {{--
            Reviewers often write this on a phone straight after the interview.
            `dirty` guards what they typed: leaving the page or reloading the
            saved version asks first. Nothing is kept in browser storage, since
            an evaluation must not outlive the session on a shared device.
        --}}
        <section id="my-evaluation" class="scroll-mt-24 rounded-surface border border-secondary-soft bg-white p-6 shadow-e1"
                 x-data="{ dirty: false }"
                 x-on:recruitment-evaluations-changed.window="dirty = false"
                 x-on:recruitment-evaluation-reloaded.window="dirty = false"
                 x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }"
                 x-on:livewire:navigate.document="if (dirty && ! $event.detail?.history && ! confirm(@js(__('recruitment-applicant.evaluations.unsaved_leave')))) $event.preventDefault()">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 id="my-evaluation-title" tabindex="-1" class="scroll-mt-24 text-h4 font-semibold">{{ __('recruitment-applicant.evaluations.my_title') }}</h2>
                    <p class="mt-1 text-sm text-secondary-medium">{{ __('recruitment-applicant.evaluations.my_hint') }}</p>
                </div>
                @if ($own?->isWithdrawn())
                    <x-recruitment.pill class="text-secondary-medium">{{ __('recruitment-applicant.evaluations.withdrawn') }}</x-recruitment.pill>
                @endif
            </div>

            {{-- Announced after a save, and a lasting answer to "did it go through?" --}}
            <p role="status" class="mt-3 text-sm text-secondary-dark empty:hidden">@if ($own && ! $own->isWithdrawn() && $closedReason === null){{ __('recruitment-applicant.evaluations.saved_on', ['date' => $own->updated_at?->copy()->setTimezone($timezone)->format('Y-m-d H:i')]) }}@endif</p>

            @error('evaluation')
                <div role="alert" class="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-control border border-primary bg-white p-3 text-sm text-primary">
                    <span>{{ $message }}</span>
                    <button type="button" wire:click="reload" wire:confirm="{{ __('recruitment-applicant.evaluations.reload_confirm') }}"
                        class="min-h-11 cursor-pointer font-semibold underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:min-h-0">{{ __('recruitment-applicant.evaluations.reload') }}</button>
                </div>
            @enderror

            @if ($own?->isWithdrawn())
                <p class="mt-4 text-sm text-secondary-medium">{{ __('recruitment-applicant.evaluations.errors.withdrawn') }}</p>
            @elseif ($closedReason !== null)
                <p class="mt-4 rounded-control bg-secondary-soft/30 p-3 text-sm text-secondary-medium">{{ __('recruitment-applicant.evaluations.errors.'.$closedReason) }}</p>
                @if ($own)
                    @include('livewire.recruitment.interviews.partials.evaluation-content', ['evaluation' => $own])
                @endif
            @else
                <form wire:submit="save" class="mt-4 space-y-4" x-on:input="dirty = true" x-on:change="dirty = true">
                    @php $recommendationInvalid = $errors->has('recommendation'); @endphp
                    <fieldset role="radiogroup" aria-required="true"
                              @if ($recommendationInvalid) aria-invalid="true" aria-describedby="recommendation-error" @endif>
                        <legend class="text-body font-medium text-secondary-dark">
                            {{ __('recruitment-applicant.evaluations.recommendation') }}
                            <span class="ms-1 text-primary" aria-hidden="true">*</span>
                            <span class="sr-only">({{ __('general.a11y.required-field') }})</span>
                        </legend>
                        {{-- Selection is drawn by CSS from the checked input, so a tap needs no round trip. --}}
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (Recommendation::values() as $value)
                                <label wire:key="recommendation-{{ $value }}" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-control border border-secondary-light px-3 text-sm transition hover:bg-secondary-soft/30 sm:min-h-9 has-[:checked]:border-secondary-dark has-[:checked]:bg-selection has-[:checked]:font-semibold has-[:checked]:text-secondary-dark has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary">
                                    <input type="radio" wire:model="recommendation" value="{{ $value }}" class="text-primary focus:ring-0">
                                    {{ __('recruitment-applicant.evaluations.recommendations.'.$value) }}
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-1 text-xs text-secondary-medium">{{ __('recruitment-applicant.evaluations.advisory') }}</p>
                        <x-forms.input-error field="recommendation" input-id="recommendation" />
                    </fieldset>

                    @foreach (['pros', 'cons', 'notes'] as $field)
                        {{-- The count appears only near the limit, where it helps. Not a live region: it would speak every keystroke. maxlength stops the overrun. --}}
                        <div wire:key="evaluation-field-{{ $field }}" x-data="{ length: {{ mb_strlen($this->{$field}) }} }" x-on:input="length = $event.target.value.length"
                             x-on:recruitment-evaluation-reloaded.window="$nextTick(() => length = $el.querySelector('textarea').value.length)">
                            <x-forms.input-label :for="'evaluation-'.$field" :value="__('recruitment-applicant.evaluations.'.$field)" />
                            <x-forms.textarea :id="'evaluation-'.$field" wire:model="{{ $field }}" rows="3" maxlength="{{ $maxLength }}" />
                            <p x-cloak x-show="length > {{ (int) floor($maxLength * 0.8) }}" class="mt-1 text-xs text-secondary-medium"
                               x-text="@js(__('recruitment-applicant.evaluations.length', ['count' => '__count__', 'max' => $maxLength])).replace('__count__', length)"></p>
                            <x-forms.input-error :field="$field" :input-id="'evaluation-'.$field" />
                        </div>
                    @endforeach

                    <div class="flex justify-end">
                        <x-primary-button type="submit" wire:target="save">
                            {{ $own ? __('recruitment-applicant.evaluations.update') : __('recruitment-applicant.evaluations.submit') }}
                        </x-primary-button>
                    </div>
                </form>
            @endif

            @if ($own && $own->revisions->count() > 1)
                <details class="mt-4 text-sm">
                    <summary class="cursor-pointer font-medium">{{ __('recruitment-applicant.evaluations.history') }} ({{ $own->revisions->count() }})</summary>
                    @include('livewire.recruitment.interviews.partials.evaluation-history', ['revisions' => $own->revisions])
                </details>
            @endif
        </section>
    @endif
</div>
