{{--
    Last-third review of a trial contract (meeting 19/11, track 4). The answer
    is a request to HR with a mandatory reason; nothing here changes the
    contract. No pay figure is read or shown.
--}}
@php
    $employee = $review->employee;
    $contract = $review->contract;
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('d M Y') : '—';
@endphp

<div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8" data-testid="probation-review">
    <x-page-header :title="__('probation.title')" :lead="__('probation.lead', ['name' => $employee->name])" />

    <x-card>
        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
            <div>
                <dt class="text-small text-ink-2">{{ __('probation.fields.employee') }}</dt>
                <dd class="font-semibold text-ink">{{ $employee->name }}</dd>
            </div>
            <div>
                <dt class="text-small text-ink-2">{{ __('probation.fields.position') }}</dt>
                <dd class="font-semibold text-ink">{{ $contract->position?->name ?? __('general.na') }}</dd>
            </div>
            <div>
                <dt class="text-small text-ink-2">{{ __('probation.fields.last-third') }}</dt>
                <dd class="num font-semibold text-ink">{{ $date($lastThirdStart) }}</dd>
            </div>
            <div>
                <dt class="text-small text-ink-2">{{ __('probation.fields.ends') }}</dt>
                <dd class="num font-semibold text-ink">{{ $date($contract->end_date) }}</dd>
            </div>
        </dl>
    </x-card>

    @if ($review->isDecided())
        <x-card data-testid="probation-decided">
            <div class="space-y-3">
                <x-status tone="done" icon="check-circle" :label="__('probation.status.requested')" />
                <p class="text-body text-ink">
                    <span class="font-semibold">{{ __('probation.fields.decision') }}:</span>
                    {{ __('probation.decision.'.$review->decision) }}
                </p>
                <p class="text-body text-ink">
                    <span class="font-semibold">{{ __('probation.fields.reason') }}:</span>
                    {{ $review->reason }}
                </p>
                <p class="text-small text-ink-2">
                    {{ __('probation.by', [
                        'name' => $review->decidedBy?->profile?->name ?? $review->decidedBy?->email ?? __('general.unknown'),
                        'date' => $date($review->decided_at),
                    ]) }}
                </p>
                <p class="text-small text-ink-2">{{ __('probation.nothing-applied') }}</p>
            </div>
        </x-card>
    @elseif ($canDecide)
        <x-card>
            <form wire:submit="send" class="space-y-5" data-testid="probation-form">
                <p class="text-small text-ink-2">{{ __('probation.form-hint') }}</p>

                <x-forms.radio-group :legend="__('probation.fields.decision')" error="decision" required>
                    @foreach (\App\Models\ProbationReview::DECISIONS as $option)
                        <x-forms.radio-input wire:model="decision" :value="$option">
                            {{ __('probation.decision.'.$option) }}
                        </x-forms.radio-input>
                    @endforeach
                </x-forms.radio-group>
                <x-error-span>{{ $errors->first('decision') }}</x-error-span>

                <div>
                    <x-forms.input-label for="probation-reason" required>
                        {{ __('probation.fields.reason') }}
                    </x-forms.input-label>
                    <x-forms.textarea id="probation-reason" wire:model="reason" rows="4" />
                    <x-error-span>{{ $errors->first('reason') }}</x-error-span>
                </div>

                <div class="flex justify-end">
                    <x-button type="submit" variant="primary">{{ __('probation.send') }}</x-button>
                </div>
            </form>
        </x-card>
    @else
        <x-card data-testid="probation-waiting">
            <x-status tone="wait" icon="clock" :label="__('probation.status.waiting')" />
            <p class="mt-2 text-small text-ink-2">{{ __('probation.waiting-hint') }}</p>
        </x-card>
    @endif
</div>
