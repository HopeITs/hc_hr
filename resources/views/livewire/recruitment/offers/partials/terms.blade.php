{{-- One version's terms. The salary goes through OfferTerms::salary(), which prints a placeholder unless $canSeeSalary. --}}
@php use App\Support\Recruitment\OfferTerms; $compact ??= false; @endphp
<dl class="{{ $compact ? 'mt-2 grid grid-cols-2 gap-2 md:grid-cols-4' : 'mt-6 grid grid-cols-1 gap-4 md:grid-cols-3' }} text-sm">
    <div>
        <dt class="text-secondary-medium">{{ __('recruitment-applicant.offers.salary') }}</dt>
        <dd class="font-medium">{{ OfferTerms::salary($version, $canSeeSalary) }}</dd>
    </div>
    <div>
        <dt class="text-secondary-medium">{{ __('recruitment-applicant.offers.start_date') }}</dt>
        <dd class="font-medium">{{ $version->start_date?->format('Y-m-d') ?? '—' }}</dd>
    </div>
    <div>
        <dt class="text-secondary-medium">{{ __('recruitment-applicant.offers.probation_days') }}</dt>
        <dd class="font-medium">{{ $version->probation_days === null ? '—' : __('recruitment-applicant.offers.days', ['count' => (int) $version->probation_days]) }}</dd>
    </div>
    <div>
        <dt class="text-secondary-medium">{{ __('recruitment-applicant.offers.contract_type') }}</dt>
        <dd>{{ $version->contractType?->name ?? '—' }}</dd>
    </div>
    @unless ($compact)
        <div>
            <dt class="text-secondary-medium">{{ __('recruitment-applicant.offers.employment_type') }}</dt>
            <dd>{{ OfferTerms::employmentType($version->terms_json['employment_type'] ?? null) }}</dd>
        </div>
        <div>
            <dt class="text-secondary-medium">{{ __('recruitment-applicant.offers.place') }}</dt>
            <dd>{{ OfferTerms::place($version->terms_json['place'] ?? null) }}</dd>
        </div>
        <div>
            <dt class="text-secondary-medium">{{ __('recruitment-applicant.offers.end_date') }}</dt>
            <dd>{{ $version->end_date?->format('Y-m-d') ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-secondary-medium">{{ __('recruitment-applicant.offers.weekly_hours') }}</dt>
            <dd>{{ $version->weekly_hours ?? '—' }}</dd>
        </div>
        @if ($version->terms_json['notes'] ?? null)
            <div class="md:col-span-3">
                <dt class="text-secondary-medium">{{ __('recruitment-applicant.offers.other_terms') }}</dt>
                <dd class="whitespace-pre-line">{{ $version->terms_json['notes'] }}</dd>
            </div>
        @endif
    @endunless
</dl>
