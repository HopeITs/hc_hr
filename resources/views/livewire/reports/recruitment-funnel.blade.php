{{-- Recruitment funnel: aggregates inside the viewer's recruitment scope; no applicant is named. --}}
@php $d = fn ($v) => $v === null ? '-' : $v; @endphp
<div class="space-y-4">
    <x-slot:title>{{ __('Recruitment funnel') }}</x-slot:title>

    <x-page-header :title="__('Recruitment funnel')" :lead="__('From request to hire: how many applicants reach each stage, and how long it takes.')">
        <x-slot:actions>
            @foreach ($periods as $p)
                <x-button type="button" size="sm" :variant="$r['days'] === $p ? 'primary' : 'outline'" wire:click="$set('days', {{ $p }})">
                    {{ $p === 0 ? __('All time') : __(':n days', ['n' => $p]) }}
                </x-button>
            @endforeach
            <x-button type="button" size="sm" variant="outline" wire:click="export">{{ __('Export to Excel') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-card :title="__('Requests submitted')">
            <span class="num text-3xl font-extrabold text-ink" data-testid="funnel-requests">{{ number_format($r['requests']['submitted']) }}</span>
            <p class="mt-1 text-small text-ink-2">{{ __(':a approved · :b rejected · :c in review', ['a' => $r['requests']['approved'], 'b' => $r['requests']['rejected'], 'c' => $r['requests']['inReview']]) }}</p>
        </x-card>
        <x-card :title="__('Days from request to vacancy')"><span class="num text-3xl font-extrabold text-ink">{{ $d($r['requests']['daysToApply']) }}</span></x-card>
        <x-card :title="__('Days from application to hire')"><span class="num text-3xl font-extrabold text-ink" data-testid="funnel-to-hire">{{ $d($r['times']['toHire']) }}</span></x-card>
        <x-card :title="__('Offer acceptance')">
            <span class="num text-3xl font-extrabold text-ink" data-testid="funnel-acceptance">{{ $r['offerAcceptancePercent'] === null ? '-' : $r['offerAcceptancePercent'].'%' }}</span>
            <p class="mt-1 text-small text-ink-2">{{ __(':n offers expired', ['n' => $r['offersExpired']]) }}</p>
        </x-card>
    </div>

    <x-card :title="__('Where applicants get to')">
        @foreach ($r['stages'] as $stage)
            <div class="mb-3 last:mb-0" data-testid="funnel-stage">
                <div class="flex items-baseline justify-between gap-2 text-small">
                    <span class="text-ink">{{ $stage['label'] }}</span>
                    <span class="num text-ink-2">{{ $stage['count'] }} · {{ $stage['percent'] }}%@if ($stage['fromPrevious'] !== null) · {{ __(':n% of the stage before', ['n' => $stage['fromPrevious']]) }}@endif</span>
                </div>
                <div class="mt-1 h-3 rounded-full bg-surface"><div class="h-3 rounded-full bg-ink" style="width: {{ $stage['percent'] }}%"></div></div>
            </div>
        @endforeach
    </x-card>

    <div class="grid gap-4 lg:grid-cols-3">
        <x-card :title="__('Days from application to first interview')"><span class="num text-3xl font-extrabold text-ink">{{ $d($r['times']['toInterview']) }}</span></x-card>
        <x-card :title="__('Days from application to offer')"><span class="num text-3xl font-extrabold text-ink">{{ $d($r['times']['toOffer']) }}</span></x-card>
        <x-card :title="__('Applications by current status')"><x-report.bars :rows="$r['byStatus']" /></x-card>
    </div>

    <x-card :title="__('Positions with the most applications')">
        @if ($r['byPosition']->isEmpty())
            <p class="text-small text-ink-3">{{ __('Nothing to show for this selection.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-start text-small">
                    <thead class="text-ink-2">
                        <tr>
                            <th scope="col" class="py-2 text-start">{{ __('Position') }}</th>
                            <th scope="col" class="py-2 text-end">{{ __('Applications') }}</th>
                            <th scope="col" class="py-2 text-end">{{ __('Interviewed') }}</th>
                            <th scope="col" class="py-2 text-end">{{ __('Offer sent') }}</th>
                            <th scope="col" class="py-2 text-end">{{ __('Hired') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-soft">
                        @foreach ($r['byPosition'] as $row)
                            <tr>
                                <td class="py-2 text-ink">{{ $row['label'] }}</td>
                                <td class="num py-2 text-end">{{ $row['applications'] }}</td>
                                <td class="num py-2 text-end">{{ $row['interviewed'] }}</td>
                                <td class="num py-2 text-end">{{ $row['offers'] }}</td>
                                <td class="num py-2 text-end">{{ $row['hired'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</div>
