<div class="w-full h-full rounded-surface bg-white border border-neutral-200 shadow-e1 hover:shadow-e2 transition-all duration-300 p-9 flex flex-col">

    <div class="flex items-center justify-between mb-4">
        <h2 class="text-h4 font-semibold text-secondary-medium flex items-center gap-2">
            @lang('general.employees-statistics')
        </h2>

        <div class="range-select-box">
            <select
                id="employeeChartRange"
                class="text-small border border-neutral-300 rounded-surface ps-3 pe-8 py-1.5 bg-white shadow-e1 text-neutral-700">
                <option value="monthly">@lang('general.monthly')</option>
                <option value="quarterly">@lang('general.quarterly')</option>
                <option value="yearly">@lang('general.yearly')</option>
            </select>
        </div>
    </div>

    <div class=" max-h-[125px]">
        <canvas id="employeeChart"></canvas>
    </div>

    <div id="chartLegend" class="flex items-center gap-6 mt-6 text-body font-medium ms-3 text-neutral-600"></div>

</div>

<script nonce="{{ Vite::cspNonce() }}">
    // Runs on a normal load and again when wire:navigate swaps this page in,
    // where DOMContentLoaded has already fired and would never call back.
    (() => {
    const init = async () => {

        const canvas = document.getElementById("employeeChart");
        const select = document.getElementById("employeeChartRange");
        if (select && window.$ && $.fn && $.fn.select2) {
            $(select).select2({
                width: '150px',
                minimumResultsForSearch: Infinity, // only 3 options — no search box
                dir: document.documentElement.dir || 'ltr',
            });
        }
        if (!canvas) return;
        if (typeof Chart === "undefined") return console.error("Chart.js not loaded.");

        const ctx = canvas.getContext("2d");

        const styles = getComputedStyle(document.documentElement);
        const primary   = styles.getPropertyValue("--color-primary").trim() || "#be1f24";
        const secondary = styles.getPropertyValue("--color-secondary-medium").trim() || "#6d6e71";
        const neutral   = styles.getPropertyValue("--color-secondary-light").trim() || "#939598";

        async function fetchData(mode = "monthly") {
            // Locale-prefixed, so the endpoint answers in the page's language.
            const res = await fetch(`${@js(LaravelLocalization::localizeUrl('/employee-statistics'))}/${mode}`);
            if (!res.ok) return { labels: [], new: [], ended: [], terminated: [] };
            return res.json();
        }

        let data = await fetchData("monthly");

        const chart = new Chart(ctx, {
            type: "line",
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: @js(__('dashboard.employee-chart.new')),
                        data: data.new,
                        borderColor: primary,
                        fill: false,
                        tension: 0.3,
                        borderWidth: 0.7,
                        pointBackgroundColor: primary,
                        pointRadius: 2.1,
                        pointHoverRadius: 4.2
                    },
                    {
                        label: @js(__('dashboard.employee-chart.ended')),
                        data: data.ended,
                        borderColor: secondary,
                        fill: false,
                        tension: 0.3,
                        borderWidth: 0.7,
                        pointBackgroundColor: secondary,
                        pointRadius: 2.1,
                        pointHoverRadius: 4.2
                    },
                    {
                        label: @js(__('dashboard.employee-chart.terminated')),
                        data: data.terminated,
                        borderColor: neutral,
                        fill: false,
                        tension: 0.3,
                        borderWidth: 0.7,
                        pointBackgroundColor: neutral,
                        pointRadius: 2.1,
                        pointHoverRadius: 4.2
                    },
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        mode: "index",
                        intersect: false,
                        backgroundColor: "#fff",
                        titleColor: "#58595b",
                        bodyColor: "#58595b",
                        borderColor: "rgba(88,89,91,0.1)",
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 6,
                        displayColors: true
                    }

                },
                scales: {
                    x: {
                        grid: {
                            color: "rgba(220,221,222,0.5)",
                            drawBorder: false,
                            drawOnChartArea: true
                        },
                        ticks: {
                            color: "#6d6e71",
                            font: {
                                size: 12,
                                family: "SomarSans",
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: "rgba(220,221,222,0.5)",
                            drawBorder: false,
                            drawOnChartArea: true
                        },
                        ticks: {
                            stepSize: 1,
                            color: "#6d6e71",
                            font: {
                                size: 12,
                                family: "SomarSans",
                            },
                            callback: value => Number.isInteger(value) ? value : ""
                        }
                    }
                }
            }
        });

        function renderLegend(chartInstance) {
            const legendBox = document.getElementById("chartLegend");
            legendBox.innerHTML = "";

            chartInstance.data.datasets.forEach((ds, index) => {
                const item = document.createElement("div");
                item.className = "flex items-center gap-1 cursor-pointer select-none";

                const isHidden = chartInstance.isDatasetVisible(index) === false;

                const dot = document.createElement("span");
                dot.className = "w-[7.7px] h-[7.7px] rounded-full inline-block";
                dot.style.backgroundColor = isHidden ? "transparent" : ds.borderColor;
                dot.style.border = `1.54px solid ${ds.borderColor}`;

                const label = document.createElement("span");
                label.textContent = ds.label;
                label.className = "text-[9.24px]";
                label.style.opacity = isHidden ? 0.4 : 1;

                item.appendChild(dot);
                item.appendChild(label);

                item.addEventListener("click", () => {
                    const meta = chartInstance.getDatasetMeta(index);

                    meta.hidden = meta.hidden === null ? !chartInstance.data.datasets[index].hidden : null;

                    chartInstance.update();
                    renderLegend(chartInstance);
                });

                legendBox.appendChild(item);
            });
        }


        renderLegend(chart);

        $(select).on("change", async function () {
            const mode = this.value;
            const newData = await fetchData(mode);

            chart.data.labels = newData.labels;
            chart.data.datasets[0].data = newData.new;
            chart.data.datasets[1].data = newData.ended;
            chart.data.datasets[2].data = newData.terminated;

            chart.update();
            renderLegend(chart);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    })();
</script>
