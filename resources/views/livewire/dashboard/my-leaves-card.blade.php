{{--
    The viewer's leave balance, as a dashboard tile. It follows its own size
    (the tile is the size container "tile", resources/css/dashboard.css): one
    row is a single line (type, days left of the total, a bar, Show more); from
    two rows the full card, whose number scales with the tile and whose gauge
    appears once there is room for it.
--}}
<div class="hc-leaves flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    <div class="hc-leaves-compact items-center gap-4 px-5 py-3">
        <div class="min-w-0 flex-1">
            <p class="truncate text-small font-bold text-ink-2">{{ $title ?: __('general.annual-leave') }}</p>
            <p class="num text-h4 font-extrabold leading-tight text-ink">
                {{ $remainingDays }}<span class="text-body font-semibold text-ink-2">/{{ $totalDays }}</span>
            </p>
        </div>
        <span class="hc-leaves-bar" aria-hidden="true"><span style="inline-size: {{ max(0, min(100, 100 - (int) $percentage)) }}%"></span></span>
        <a href="{{ route('leaves.index') }}" wire:navigate class="hc-btn hc-btn--outline hc-btn--sm shrink-0">{{ __('general.show-more') }}</a>
    </div>

    <div class="hc-leaves-full min-h-0 flex-1 flex-col">
        <x-dashboard-cards.card-header :title="__('general.my-leaves')">
            <x-slot:actions>
                <a href="{{ route('leaves.index') }}" wire:navigate class="hc-btn hc-btn--outline hc-btn--sm">{{ __('general.show-more') }}</a>
            </x-slot:actions>
        </x-dashboard-cards.card-header>

        <div class="flex min-h-0 flex-1 items-center gap-6 px-6 pb-4">
            {{-- flex-wrap + gap: on a narrow tile the breakdown wraps under the number instead of overflowing. --}}
            <div class="flex min-w-0 flex-wrap items-end gap-x-6 gap-y-2">
                <div class="flex items-end">
                    <span class="hc-leaves-big num font-extrabold leading-none text-ink">{{ $remainingDays }}</span>
                    <span class="mb-1 text-h3 font-semibold text-ink-2">/{{ $totalDays }}</span>
                </div>

                <div class="flex min-w-0 flex-col">
                    <h3 class="text-body font-bold text-ink">{{ $title ?: __('general.annual-leave') }}</h3>
                    <ul class="mt-1 space-y-0.5 text-small leading-relaxed text-ink-2">
                        <li>@lang('general.used-leave', ['attr' => $used])</li>
                        <li>@lang('general.remaining-leave', ['attr' => $remaining])</li>
                        <li>@lang('general.pending-leave', ['attr' => $pending])</li>
                        <li>@lang('general.total-leave', ['attr' => $total])</li>
                    </ul>
                </div>
            </div>

            <div class="hc-leaves-gauge relative ms-auto shrink-0">
                <canvas id="leavesGaugeChart" aria-hidden="true"></canvas>
                <p class="absolute inset-x-0 bottom-2 text-center text-h2 font-bold text-ink">
                    <span class="num">{{ $percentage }}</span><span class="text-body">%</span>
                </p>
            </div>
        </div>
    </div>
</div>

{{-- @script, not @push: the card is a lazy dashboard tile, which arrives in a
     Livewire update that never reaches the layout's script stack. --}}
@script
<script>
    const init = () => {
        const canvas = document.getElementById('leavesGaugeChart');
        if (! canvas || ! window.Chart) return;
        const ctx = canvas.getContext('2d');
        const value = Number(@js((int) $percentage));
        const css = getComputedStyle(document.documentElement);
        const track = css.getPropertyValue('--color-surface').trim() || '#E6E7E8';
        const brand = css.getPropertyValue('--color-primary').trim() || '#BE1F24';

        new Chart(ctx, {
            type: 'doughnut',
            data: { datasets: [{ data: [0], backgroundColor: ['transparent'] }] },
            options: {
                cutout: '70%',
                circumference: 180,
                rotation: 270,
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { left: 15, right: 15, top: 5, bottom: 5 } },
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                animation: false,
            },
            plugins: [{
                id: 'customGauge',
                afterDraw(chart) {
                    const { width, height } = chart.chartArea;
                    const centerX = width / 2;
                    const centerY = height * 0.9;
                    const radius = Math.min(width, height * 2) / 2.25;
                    const startAngle = Math.PI;
                    const percentAngle = startAngle + (value / 100) * Math.PI;

                    ctx.save();
                    ctx.lineWidth = radius * 0.24;
                    ctx.lineCap = 'round';

                    ctx.strokeStyle = track;
                    ctx.arc(centerX, centerY, radius, startAngle, 2 * Math.PI);
                    ctx.stroke();

                    ctx.beginPath();
                    ctx.strokeStyle = brand;
                    ctx.arc(centerX, centerY, radius, startAngle, percentAngle);
                    ctx.stroke();

                    ctx.restore();
                },
            }],
        });
    };

    // Runs just before the card replaces its placeholder: wait a frame for the canvas.
    requestAnimationFrame(init);
</script>
@endscript
