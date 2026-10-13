// Import the bare Chart class, NOT 'chart.js/auto'. The /auto entry point
// registers every controller Chart.js ships and is a separate module graph, so
// using it here bundled a second full copy of Chart.js alongside the selective
// registration in app.js. This chart is a line type, whose controller, elements
// and scales are all already registered there.
import { Chart } from "chart.js";

function initSalaryTypeScatter() {
    const canvas = document.getElementById("salaryTypeMiniChart");
    if (!canvas) return;

    const seriesData = JSON.parse(canvas.dataset.series || "[]");

    if (canvas._chart) {
        canvas._chart.destroy();
    }

    const labels = seriesData.map(i => i.label);
    const values = seriesData.map(i => i.value);
    const colors = seriesData.map(i => i.color);

    const maxValue = Math.max(...values);
    const suggestedMax = maxValue === 0 ? 1 : maxValue + 10;

    const numericX = labels.map((_, i) => i + 1);

    const chart = new Chart(canvas, {
        type: "line",
        data: {
            labels: numericX,
            datasets: [
                {
                    data: numericX.map((x, i) => ({
                        x,
                        y: values[i]
                    })),
                    pointBackgroundColor: colors,
                    pointBorderColor: colors,
                    pointRadius: 2.8,
                    borderWidth: 0,
                    showLine: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,

            scales: {
                x: {
                    type: "linear",
                    min: 0.7,
                    max: numericX.length + 0.3,
                    grid: { display: false },
                    ticks: { display: false }
                },

                y: {
                    beginAtZero: true,
                    suggestedMax,
                    grid: { color: "#dcddde" },
                    ticks: {
                        color: "#6d6e71",
                        font: {
                            size: 12,
                            family: "SomarSans"
                        }
                    }
                }
            },

            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.parsed.y} Employees`
                    }
                }
            }
        }
    });

    canvas._chart = chart;
}

document.addEventListener("DOMContentLoaded", initSalaryTypeScatter);
window.Livewire?.hook("message.processed", initSalaryTypeScatter);
