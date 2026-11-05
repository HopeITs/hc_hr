@php
    use App\Enums\ContractType as ContractTypeEnum;

    $typeOptions = [
        ContractTypeEnum::FullTime => ContractTypeEnum::getLocalizedName(ContractTypeEnum::FullTime)['value'],
        ContractTypeEnum::PartTime => ContractTypeEnum::getLocalizedName(ContractTypeEnum::PartTime)['value'],
    ];
@endphp

{{--
    Amend mode only (ContractAmend): when the change takes effect, whether the
    work becomes full or part time, and why. The contract's assignment is not
    shown at all — an amendment never moves a person to another position.
--}}
<x-contract.section
    name="amendment"
    :index="$amendmentIndex ?? 3"
    :title="__('contracts.amendment.section')"
    summary="amendmentSummary"
>
    <p class="mt-4 flex gap-2 rounded-control border border-line-soft border-s-4 border-s-ink
              bg-soft px-3 py-2 text-small leading-relaxed text-ink">
        <svg class="mt-0.5 size-4 shrink-0 text-ink" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M10 1.5a8.5 8.5 0 100 17 8.5 8.5 0 000-17zM9 5.5h2v6H9v-6zm0 7.5h2v2H9v-2z"/>
        </svg>
        <span>@lang('contracts.amendment.draft-hint')</span>
    </p>

    <div class="mt-4 grid gap-x-6 gap-y-4 md:grid-cols-2">
        <div>
            <x-forms.input-label for="composer_effective_from" required>@lang('contracts.amendment.effective-from')</x-forms.input-label>
            {{-- Any day of the term, past ones included: the date is the one on the signed sheet. --}}
            <x-forms.date-input
                id="composer_effective_from"
                x-model="effectiveFrom"
                x-bind:min="termStart"
                x-bind:max="termEnd"
                aria-describedby="composer_effective_from_hint"
            />
            <x-forms.hint id="composer_effective_from_hint">@lang('contracts.amendment.effective-from-hint')</x-forms.hint>
            <x-error-span>{{ $errors->first('effective_from') }}</x-error-span>
        </div>

        @if(! $isService)
            <div>
                <x-forms.input-label>@lang('general.type')</x-forms.input-label>
                <div class="mt-1 grid grid-cols-2 gap-2" role="radiogroup" aria-label="@lang('general.type')">
                    @foreach ($typeOptions as $value => $label)
                        <label class="cursor-pointer">
                            <input type="radio" class="sr-only peer" name="composer_type" value="{{ $value }}" x-model="type" />
                            <span class="flex min-h-[2.625rem] items-center justify-center rounded-control
                                         border-2 border-line-soft bg-card px-2 py-2 text-body text-ink
                                         shadow-e1 transition hover:border-ink-3
                                         peer-checked:border-ink peer-checked:bg-surface peer-checked:text-ink
                                         peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2
                                         peer-focus-visible:outline-focus">
                                <span class="truncate font-semibold">{{ $label }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <x-error-span>{{ $errors->first('type') }}</x-error-span>
            </div>
        @endif

        <div class="md:col-span-2">
            <x-forms.input-label for="composer_amendment_reason">@lang('contracts.amendment.reason')</x-forms.input-label>
            <x-forms.textarea id="composer_amendment_reason" rows="2" x-model="amendmentReason" />
            <x-error-span>{{ $errors->first('reason') }}</x-error-span>
        </div>
    </div>

    <p class="mt-4 text-small text-ink-2">@lang('contracts.amendment.leave-hint')</p>

    @foreach (['terms', 'contract', 'salary'] as $key)
        @if($errors->has($key))
            <x-error-span>{{ $errors->first($key) }}</x-error-span>
        @endif
    @endforeach
</x-contract.section>
