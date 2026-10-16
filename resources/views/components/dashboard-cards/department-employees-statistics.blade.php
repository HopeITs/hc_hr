<div
    class="w-full h-[28rem] rounded-surface bg-white border border-[var(--color-neutral-200)] shadow-e2 hover:shadow-e3 transition-all duration-500 overflow-hidden">

    <div
        class="flex justify-between items-center px-6 py-4 border-b border-[var(--color-neutral-200)] bg-[var(--color-neutral-50)]">
        <h2 class="text-h4 font-semibold text-[var(--color-neutral-800)] tracking-tight flex items-center gap-2">
      <span
          class="inline-block w-2.5 h-2.5 rounded-full bg-[var(--color-primary)] shadow-[0_0_8px_var(--color-secondary-medium)]"></span>
            Employees per Department
        </h2>
        <span class="text-body text-[var(--color-neutral-500)] font-medium">2025 Overview</span>
    </div>

    <div class="relative p-3 h-[calc(100%-4.5rem)]">
        <canvas id="departmentEmployeesChart" style="width:100%;height:100%;"></canvas>
    </div>
</div>

<script nonce="{{ Vite::cspNonce() }}">
    // Runs on a normal load and again when wire:navigate swaps this page in,
    // where DOMContentLoaded has already fired and would never call back.
    (() => {
    const init = () => {
        const canvas = document.getElementById("departmentEmployeesChart");
        if (!canvas) return;
        const ctx = canvas.getContext("2d");

        const departments = [
            "Management", "Front Desk", "Teachers", "IT", "Communication",
            "HR", "Finance", "MEAL", "Fundraising", "Legal Affairs"
        ];
        const employees = [8, 4, 15, 6, 5, 9, 7, 3, 2, 4];

        const barColor = getComputedStyle(document.documentElement)
            .getPropertyValue("--color-primary").trim() || "#be1f24";
        const neutralGrid = "rgba(88,89,91,0.06)";

        new Chart(ctx, {
            type: "bar",
            data: {
                labels: departments,
                datasets: [{
                    label: "Employees",
                    data: employees,
                    backgroundColor: barColor,
                    borderColor: barColor,
                    borderWidth: 1,
                    borderRadius: 6,
                    hoverBackgroundColor: barColor,
                    barPercentage: 0.6,
                    categoryPercentage: 0.5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 800, easing: "easeOutQuart" },
                events: ["mousemove", "mouseout", "click", "touchstart", "touchmove"],
                plugins: {
                    legend: { display: false },
                    title: {
                        display: true,
                        text: "Employee Distribution by Department",
                        color: "#58595b",
                        font: {
                            family: "SomarSans",
                            size: 16,
                            weight: "600"
                        },
                        padding: { top: 10, bottom: 20 }
                    },
                    tooltip: {
                        enabled: true,
                        mode: "index",
                        intersect: false,
                        backgroundColor: "#58595b",
                        borderColor: "#58595b",
                        borderWidth: 1,
                        titleColor: "#ffffff",
                        bodyColor: "#ffffff",
                        cornerRadius: 8,
                        padding: 12,
                        displayColors: false,
                        usePointStyle: true,
                        titleFont: { family: "SomarSans", weight: "600" },
                        bodyFont: { family: "SomarSans", size: 13 },
                        callbacks: {
                            title: (ctx) => ctx[0].label,
                            label: (ctx) => `${ctx.parsed.y} Employees`
                        }
                    }
                },
                interaction: { mode: "index", intersect: false },
                hover: { mode: "index", intersect: false },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: "#6d6e71",
                            font: { size: 12, family: "SomarSans" },
                            padding: 8,
                            maxRotation: 80,
                            minRotation: 50,
                            autoSkip: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: neutralGrid, drawBorder: false },
                        ticks: {
                            color: "#6d6e71",
                            font: { size: 12, family: "SomarSans" },
                            padding: 6
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
