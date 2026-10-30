import * as am5 from "@amcharts/amcharts5";
import * as am5xy from "@amcharts/amcharts5/xy";
import am5themes_Animated from "@amcharts/amcharts5/themes/Animated";
import { applyBrandInterfaceColors } from "./charts/amcharts-brand.js";
import { brandColors } from "./charts/brand-colors.js";

// Read the Livewire-published globals on every call rather than once at module
// evaluation. A module body runs a single time, so the previous top-level
// version captured whatever the counts were on first load and then rendered
// stale data after any Livewire re-render that changed them.
const buildChartData = () => {
    const dynamicLabels = window.jobLevelLabels || [];
    const dynamicCounts = window.employeeCounts || [];
    return dynamicLabels.map((label, index) => ({
        category: label,
        value: dynamicCounts[index],
    }));
};

// The #jobLevelsChart presence check now lives in charts/lazy-charts.js; the
// empty-data guard stays here because it depends on the Livewire globals.
export default function initJobLevelsChart() {
    const chartData = buildChartData();

    if (chartData.length === 0) {
        return;
    }

    am5.ready(function () {
        // Re-runs must not stack a second Root on the same node (amCharts throws).
        am5.registry.rootElements
            .filter((r) => r.dom.id === "jobLevelsChart")
            .forEach((r) => r.dispose());

        // One series, so one colour: ink bars, told apart by their axis labels.
        // Re-read on every draw, so the light <-> dark redraw follows.
        const brand = brandColors();

        const root = am5.Root.new("jobLevelsChart");
        applyBrandInterfaceColors(root);

        root.setThemes([am5themes_Animated.new(root)]);

        const chart = root.container.children.push(
            am5xy.XYChart.new(root, {
                panX: false,
                panY: false,
                wheelX: "none",
                wheelY: "none",
                layout: root.verticalLayout,
                paddingTop: 10,
                paddingBottom: 10,
                paddingLeft: 0,
                paddingRight: 10
            })
        );

        const data = chartData;

        const yRenderer = am5xy.AxisRendererY.new(root, {
            inversed: true,
            minGridDistance: 20,
            cellStartLocation: 0.1,
            cellEndLocation: 0.9,
        });
        yRenderer.labels.template.setAll({
            fontSize: 13,
            fill: am5.color(brand.ink),
            oversizedBehavior: "wrap",
            textAlign: "left",
        });

        const yAxis = chart.yAxes.push(
            am5xy.CategoryAxis.new(root, {
                categoryField: "category",
                renderer: yRenderer,
            })
        );
        yAxis.data.setAll(data);

        const xRenderer = am5xy.AxisRendererX.new(root, {
            strokeOpacity: 0.1,
        });
        const xAxis = chart.xAxes.push(
            am5xy.ValueAxis.new(root, {
                renderer: xRenderer,
                min: 0,
            })
        );

        const series = chart.series.push(
            am5xy.ColumnSeries.new(root, {
                name: "Employees",
                xAxis: xAxis,
                yAxis: yAxis,
                valueXField: "value",
                categoryYField: "category",
                sequencedInterpolation: true,
                tooltip: am5.Tooltip.new(root, {
                    labelText: "{categoryY}: {valueX}",
                }),
            })
        );

        series.columns.template.setAll({
            height: am5.percent(60),
            cornerRadiusTR: 6,
            cornerRadiusBR: 6,
            strokeOpacity: 0,
            fill: am5.color(brand.ink),
        });

        series.data.setAll(data);

        series.bullets.push(() =>
            am5.Bullet.new(root, {
                locationX: 0.5,
                sprite: am5.Label.new(root, {
                    text: "{valueX}",
                    fill: am5.color(brand.onInk),
                    centerY: am5.percent(50),
                    centerX: am5.percent(50),
                    fontSize: 13,
                    populateText: true,
                }),
            })
        );

        series.appear(1000);
        chart.appear(1000, 100);

        root._logo?.dispose();
    });
}
