{{-- HR-040: HR reviews a manager's item for more than their team. Approve
     publishes it (now or at its scheduled time); reject needs a reason, which
     the manager is told. NewsShow re-checks NewsAccess::canApprove on every call. --}}
<section class="mb-6 rounded-surface border border-ink bg-card p-4 shadow-e1 sm:p-5"
    aria-labelledby="news-review-title" data-test="news-review">
    <div class="flex items-start gap-3">
        <span class="mt-0.5 inline-flex size-9 shrink-0 items-center justify-center rounded-full border border-ink text-ink">
            <x-ic name="inbox" />
        </span>
        <div class="min-w-0 flex-1">
            <h2 id="news-review-title" class="font-extrabold text-ink">{{ __('news.review.title') }}</h2>
            <p class="mt-1 text-small text-ink-2">{{ __('news.review.lead') }}</p>
            @if ($audienceSummary)
                <p class="mt-1 text-small text-ink-2">
                    <span class="font-semibold text-ink">{{ __('news.show.audience') }}:</span> {{ $audienceSummary }}
                </p>
            @endif

            <div class="mt-4">
                <x-forms.input-label for="news-review-note" :value="__('news.review.reason')" />
                <x-forms.textarea id="news-review-note" wire:model="reviewNote" rows="3" maxlength="2000"
                    :hint="__('news.review.reason_hint')"
                    aria-invalid="{{ $errors->has('reviewNote') ? 'true' : 'false' }}" />
                <x-forms.input-error field="reviewNote" input-id="news-review-note" />
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <x-button variant="primary" icon="check" wire:click="confirmApprove">{{ __('news.review.approve') }}</x-button>
                <x-button variant="outline" icon="x" wire:click="confirmReject">{{ __('news.review.reject') }}</x-button>
            </div>
        </div>
    </div>
</section>
