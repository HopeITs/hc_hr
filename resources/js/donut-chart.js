import * as am5 from "@amcharts/amcharts5";
import * as am5percent from "@amcharts/amcharts5/percent";
import { applyBrandInterfaceColors } from "./charts/amcharts-brand.js";
import { brandColors, brandPalette } from "./charts/brand-colors.js";

function initEmployeesByPayType() {
    const host = document.getElementById("salaryTypePieChart");
    if (!host) return;

    am5.registry.rootElements
        .filter(r => r.dom.id === "salaryTypePieChart")
        .forEach(r => r.dispose());

    const seriesData = JSON.parse(host.dataset.series || "[]");
    const percentValue = parseInt(host.dataset.percentage || "0", 10);

    const root = am5.Root.new("salaryTypePieChart");
    applyBrandInterfaceColors(root);
    root._logo?.dispose();

    const chart = root.container.children.push(
        am5percent.PieChart.new(root, {
            innerRadius: am5.percent(80),
        })
    );

    const series = chart.series.push(
        am5percent.PieSeries.new(root, {
            valueField: "value",
            categoryField: "label",
        })
    );

    // Slice colours: the brand palette in series order (the legend in
    // salary-per-employee-statistics.blade.php uses the same order), re-read on
    // every draw, so a light <-> dark redraw (charts/lazy-charts.js) follows.
    const palette = brandPalette();
    series.set("colors", am5.ColorSet.new(root, {
        colors: seriesData.map((s, i) => am5.color(palette[i % palette.length])),
        reuse: false
    }));

    series.slices.template.setAll({
        cornerRadius: 50,
        innerCornerRadius: 50,
        strokeOpacity: 0
    });

    series.labels.template.set("forceHidden", true);
    series.ticks.template.set("forceHidden", true);
    series.data.setAll(seriesData);

    const center = chart.seriesContainer.children.push(
        am5.Container.new(root, {
            centerX: am5.p50,
            centerY: am5.p50
        })
    );

    center.children.push(
        am5.Label.new(root, {
            text: percentValue + "%",
            fontSize: 20,
            fontWeight: "600",
            fill: am5.color(brandColors().ink),
            centerX: am5.p50,
            centerY: am5.p50,
            textAlign: "center"
        })
    );

    series.appear(700);
    chart.appear(700);
}

// Invocation is owned by charts/lazy-charts.js, which imports this module only
// once #salaryTypePieChart is actually on the page and re-runs it after Livewire
// navigations. Self-registering DOMContentLoaded here would never fire, because
// the dynamic import resolves long after that event.
export default initEmployeesByPayType;
