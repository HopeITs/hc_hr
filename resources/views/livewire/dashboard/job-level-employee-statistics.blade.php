<div
    class="flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm p-6">

    <div class="flex items-center justify-between mb-4">
        <h2 class="text-h4 font-semibold text-secondary-medium flex items-center gap-2">
            @lang('general.employees-per-job-level')
        </h2>

    </div>

    @if ($loadFailed)
        <x-ui.load-error class="flex-1" />
    @elseif (empty($jobLevelLabels))
        <x-center-empty-state variant="empty" class="flex-1 min-h-40! border-0 shadow-none" />
    @else
    <div class="relative flex-1 min-h-[200px]">
        <canvas id="jobLevelEmployeesChart" class="w-full h-full"></canvas>
    </div>
    @endif

</div>

{{-- @script, not @push: on the dashboard this card is a lazy tile, which arrives
     in a Livewire update that never reaches the layout's script stack. --}}
@script
    <script>
        const init = () => {

            const ctx = document.getElementById("jobLevelEmployeesChart");
            if (!ctx) return;

            const jobLevels = @json($jobLevelLabels);
            const employees = @json($employeeCounts);
            // Tooltip text per count, pluralised on the server so Arabic gets
            // its full set of plural forms.
            const countLabels = @json(collect($employeeCounts)->unique()->mapWithKeys(fn ($count) => [$count => trans_choice('dashboard.employees-count', $count)]));
            const isRtl = document.documentElement.dir === 'rtl';

            const primaryColor = "#BE1F24";
            const gridColor    = "#dcddde";

            new Chart(ctx, {
                type: "bar",
                data: {
                    labels: jobLevels,
                    datasets: [
                        {

                            data: employees,
                            backgroundColor: primaryColor,
                            borderColor: primaryColor,
                            borderWidth: 0.05,
                            borderRadius: 6,
                            barPercentage: 0.06,
                            categoryPercentage: 0.25
                        },

                        {

                            type: "line",
                            data: employees,
                            borderWidth: 0,
                            pointBackgroundColor: primaryColor,
                            pointBorderColor: primaryColor,
                            pointRadius: 2.52,
                            pointHoverRadius: 4.2,
                            tension: 0
                        }
                    ]
                },

                options: {
                    responsive: true,
                    maintainAspectRatio: false,

                    plugins: {
                        legend: { display: false },

                        tooltip: {
                            enabled: true,
                            backgroundColor: "#1f2937",
                            titleColor: "#fff",
                            bodyColor: "#fff",
                            padding: 10,
                            cornerRadius: 8,
                            displayColors: false,
                            rtl: isRtl,
                            textDirection: isRtl ? 'rtl' : 'ltr',
                            callbacks: {
                                label: (ctx) => countLabels[ctx.parsed.y] ?? String(ctx.parsed.y)
                            }
                        }
                    },

                    scales: {
                        x: {
                            reverse: isRtl,
                            grid: { display: false },
                            ticks: {
                                color: "#6b7280",
                                font: { size: 12, family: "SomarSans" },
                                padding: 10
                            }
                        },

                        y: {
                            position: isRtl ? 'right' : 'left',
                            beginAtZero: true,
                            grid: {
                                color: gridColor,
                                drawBorder: false
                            },
                            ticks: {
                                color: "#6b7280",
                                font: { size: 12, family: "SomarSans" },
                                padding: 6,
                                callback: (value) =>
                                    Number.isInteger(value) ? value : null
                            }
                        }
                    }
                }

            });

        };

        // A lazy tile runs this just before its card replaces the placeholder: wait a frame for the canvas.
        requestAnimationFrame(init);
    </script>
@endscript
