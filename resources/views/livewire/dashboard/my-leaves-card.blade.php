<div class="flex min-h-[330px] w-full flex-col justify-between space-y-4 rounded-surface border border-neutral-200 bg-white shadow-[0px_0px_25px_-14px_rgba(88,_89,_91,_0.30)] sm:min-h-[360px] md:min-h-0">
    <x-dashboard-cards.card-header :title="__('general.my-leaves')">
        <x-slot:actions>
            <a href="{{route('leaves.index')}}" wire:navigate class="flex flex-row gap-2 rounded-[10px] bg-primary px-3 py-2 text-xs font-medium text-white transition">
                {{ __('general.show-more') }}
            </a>
        </x-slot:actions>
    </x-dashboard-cards.card-header>

    <div class="grid grid-cols-1 items-center gap-10 px-8 pb-6 md:grid-cols-2 md:gap-6">

        {{-- flex-wrap + gap (not space-x): below ~sm the number block and the
             breakdown need ~377px but only ~278px is available, so the breakdown
             wraps onto its own line instead of being crushed or overflowing. At
             sm and up everything still sits on one row exactly as before. --}}
        <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
            <div class="flex items-end -mb-3">
                <span class="text-[125px] font-extrabold text-secondary-medium leading-none">{{$remainingDays}}</span>
                <span class="mb-4 text-h2 font-semibold text-secondary-medium">/{{$totalDays}}</span>
            </div>

            {{-- The divider only reads as a divider when the two blocks are
                 side by side; once they stack it is a stray vertical line. --}}
            <div class="hidden sm:block border-l border-secondary-medium h-26"></div>

            {{-- min-w-0, not min-w-full: this is a flex child with two siblings,
                 so `min-width:100%` forced the row past the container width and
                 pushed ~195px of horizontal overflow onto the whole page. --}}
            <div class="flex flex-col justify-between min-w-0">
                <h4 class="font-bold text-secondary-medium text-md">
                    {{ $title ?? __('general.annual-leave') }}
                </h4>
                <div class="w-6 border-b border-3 border-primary"></div>
                <ul class="mt-3 space-y-1 text-secondary-light text-small leading-relaxed">
                    <li class="text-secondary-light text-start">
                        <span>@lang('general.used-leave', ['attr' => $used ]) </span>

                    </li>
                    <li>
                        <span>@lang('general.remaining-leave', ['attr' => $remaining ]) </span>
                    </li>
                    <li>
                        <span>@lang('general.pending-leave', ['attr' => $pending ]) </span>

                    </li>
                    <li>
                        <span>@lang('general.total-leave', ['attr' => $total ]) </span>
                    </li>
                </ul>
            </div>
        </div>


        <div class="flex flex-col items-center justify-center md:items-end md:justify-end">
            <div class="relative w-56 h-40 ms-12 md:mb-2">
                <canvas id="leavesGaugeChart"></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-end">
                    <p class="mb-3 -ml-6 text-h1 font-bold text-primary rtl:-ml-12">
                        {{$percentage}} <span class="text-body rtl:-mr-2 ltr:-ml-2">%</span>
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
@push('js')
<script nonce="{{ Vite::cspNonce() }}">
    // Runs on a normal load and again when wire:navigate swaps this page in.
    // A livewire:navigated listener would outlive the dashboard and fire on
    // every later page, where the canvas is gone.
    (() => {
    const init = () => {
        const canvas = document.getElementById("leavesGaugeChart");
        if (!canvas) return;
        const ctx = canvas.getContext("2d");
        const value = '{{$percentage}}';

        new Chart(ctx, {
            type: "doughnut",
            data: {
                datasets: [{
                    data: [0],
                    backgroundColor: ["transparent"],
                }],
            },
            options: {
                cutout: "70%",
                circumference: 180,
                rotation: 270,
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: {left: 15, right: 15, top: 5, bottom: 5}
                },
                plugins: {legend: {display: false}, tooltip: {enabled: false}},
                animation: false,
            },
            plugins: [{
                id: 'customGauge',
                afterDraw(chart) {
                    const {width, height} = chart.chartArea;
                    const centerX = width / 2;
                    const centerY = height * 0.9;
                    const radius = Math.min(width, height * 2) / 2.25;
                    const thickness = radius * 0.24;
                    const startAngle = Math.PI;
                    const endAngle = 2 * Math.PI;
                    const percentAngle = startAngle + (value / 100) * Math.PI;

                    ctx.save();
                    ctx.lineWidth = thickness;
                    ctx.lineCap = "round";


                    ctx.strokeStyle = "#dcddde";
                    ctx.arc(centerX, centerY, radius, startAngle, endAngle);
                    ctx.stroke();


                    ctx.beginPath();
                    ctx.strokeStyle = "#be1f24";
                    ctx.arc(centerX, centerY, radius, startAngle, percentAngle);
                    ctx.stroke();

                    ctx.restore();
                }
            }]
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    })();
</script>
@endpush
