<div
    class="w-full h-full rounded-surface bg-white border border-neutral-200 shadow-[0px_0px_25px_-14px_rgba(88,_89,_91,_0.30)] hover:shadow-e2 transition-all duration-300 p-9 flex flex-col">

    <div class="flex items-center justify-between mb-4">
        <h2 class="text-h4 font-semibold text-secondary-medium flex items-center gap-2">
            @lang('general.employees-per-job-level')
        </h2>

    </div>

    <div class="relative flex-1 min-h-[200px]">
        <canvas id="jobLevelEmployeesChart" class="w-full h-full"></canvas>
    </div>

</div>

@push('scripts')
    <script nonce="{{ Vite::cspNonce() }}">
        // Runs on a normal load and again when wire:navigate swaps this page in,
        // where DOMContentLoaded has already fired and would never call back.
        (() => {
        const init = () => {

            const ctx = document.getElementById("jobLevelEmployeesChart");
            if (!ctx) return;

            const jobLevels = @json($jobLevelLabels);
            const employees = @json($employeeCounts);

            const primaryColor = "#be1f24";
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
                            backgroundColor: "#58595b",
                            titleColor: "#fff",
                            bodyColor: "#fff",
                            padding: 10,
                            cornerRadius: 8,
                            displayColors: false,
                            callbacks: {
                                label: (ctx) => `${ctx.parsed.y} Employees`
                            }
                        }
                    },

                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                color: "#6d6e71",
                                font: { size: 12, family: "SomarSans" },
                                padding: 10
                            }
                        },

                        y: {
                            beginAtZero: true,
                            grid: {
                                color: gridColor,
                                drawBorder: false
                            },
                            ticks: {
                                color: "#6d6e71",
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

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
        })();
    </script>
@endpush
