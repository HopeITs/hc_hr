import * as am5 from "@amcharts/amcharts5";
import * as am5xy from "@amcharts/amcharts5/xy";
import * as am5percent from "@amcharts/amcharts5/percent";
import am5themes_Animated from "@amcharts/amcharts5/themes/Animated";
import { brandColors, brandPalette } from "./charts/brand-colors.js";
import { applyBrandInterfaceColors, brandColorSet } from "./charts/amcharts-brand.js";

// The presence check that used to guard this block now lives in
// charts/lazy-charts.js, which is also what triggers the dynamic import.
export default function initContractTypeChart() {
    am5.ready(function () {

        const brand = brandColors();
        const colors = brandPalette().map((color) => am5.color(color));

        var data = JSON.parse(
            document.getElementById("chartdiv").dataset.chart
        );

        data.forEach((item, index) => {
            item.sliceSettings = { fill: colors[index % colors.length] };
        });

        var root = am5.Root.new("chartdiv");
        applyBrandInterfaceColors(root);
        root.setThemes([am5themes_Animated.new(root)]);

        // ============================
        // CONTAINER HOLDING BOTH CHARTS
        // ============================
        var container = root.container.children.push(
            am5.Container.new(root, {
                width: am5.p100,
                height: am5.p100,
                layout: root.horizontalLayout,
                paddingTop: 10,
                paddingLeft: 0,
                paddingRight: 0,
                paddingBottom: 0
            })
        );

        // ============================
        // DONUT CHART
        // ============================
        var pieChart = container.children.push(
            am5percent.PieChart.new(root, {
                width: am5.percent(30),
                height: am5.percent(70),
                innerRadius: am5.percent(80),
                align: "center",
                centerX: am5.p50
            })
        );

        var pieSeries = pieChart.series.push(
            am5percent.PieSeries.new(root, {
                valueField: "value",
                categoryField: "category",
                colors: brandColorSet(root)
            })
        );

        pieSeries.slices.template.setAll({
            templateField: "sliceSettings",
            strokeOpacity: 0,
            fillOpacity: 10,
            cornerRadius: 10,
            innerCornerRadius: 10,
            tooltipText: ""
        });

        pieSeries.set("tooltip", null);
        pieSeries.slices.template.set("tooltipText", "");
        pieSeries.ticks.template.set("tooltipText", "");
        pieSeries.labels.template.set("tooltipText", "");


        // donut labels
        var centerLabel = pieChart.seriesContainer.children.push(
            am5.Label.new(root, {
                text: "",
                centerX: am5.p50,
                centerY: am5.p50,
                textAlign: "center",
                fontSize: 16,
            })
        );

        // ============================
        // MINIMALIST DOT-ONLY CHART
        // ============================
        var dotChart = container.children.push(
            am5xy.XYChart.new(root, {
                width: am5.percent(55),
                height: am5.percent(90),
                panX: false,
                panY: false,
                wheelX: "none",
                wheelY: "none",
                layout: root.verticalLayout,
                colors: brandColorSet(root)
            })
        );

        // X-axis
        var xAxis = dotChart.xAxes.push(
            am5xy.CategoryAxis.new(root, {
                categoryField: "category",
                renderer: am5xy.AxisRendererX.new(root, {
                    minGridDistance: 30
                })
            })
        );

        xAxis.get("renderer").labels.template.setAll({
            fontSize: 9,
            fill: am5.color(brand.secondaryDark),
            textAlign: "center",
            dy: 4
        });

        // Y-axis
        var yAxis = dotChart.yAxes.push(
            am5xy.ValueAxis.new(root, {
                renderer: am5xy.AxisRendererY.new(root, {
                    strokeOpacity: 0.05,
                    minGridDistance: 25
                })
            })
        );

        yAxis.set("numberFormatter", am5.NumberFormatter.new(root, {
            numberFormat: "#"
        }));

        yAxis.setAll({
            min: 0,
            maxPrecision: 0 // prevents decimals
        });

        yAxis.get("renderer").labels.template.setAll({
            fontSize: 11,
            fill: am5.color(brand.secondaryMedium)
        });

        // Invisible line series (only bullets)
        var dotSeries = dotChart.series.push(
            am5xy.LineSeries.new(root, {
                xAxis: xAxis,
                yAxis: yAxis,
                valueYField: "value",
                categoryXField: "category",
                strokeOpacity: 0, // hide the line
                fillOpacity: 0
            })
        );

        dotChart.set("layer", 1);

        // Dots
        dotSeries.bullets.push(function (root, series, dataItem) {
            return am5.Bullet.new(root, {
                sprite: am5.Circle.new(root, {
                    radius: 4,
                    stroke: am5.color("#ffffff"),
                    strokeWidth: 2,
                    fill: dataItem.dataContext._dotColor || colors[0]
                })
            });
        });

        // ============================
        // INTERACTION
        // ============================
        var currentSlice;

        pieSeries.slices.template.on("active", function (active, slice) {
            if (currentSlice && currentSlice !== slice && active) {
                currentSlice.set("active", false);
            }

            var color = slice.get("fill");

            slice.dataItem.dataContext.breakdown.forEach(item => {
                item._dotColor = color;
            });

            dotSeries.data.setAll(slice.dataItem.dataContext.breakdown);

            centerLabel.setAll({
                fill: color,
                text:
                    "[fontSize:20][bold]" +
                    root.numberFormatter.format(slice.dataItem.get("valuePercentTotal"), "#.'%'") +
                    "[/]\n" +
                    "[fontSize:12]" +
                    slice.dataItem.get("category") +
                    "[/]"
            });


            dotSeries.strokes.template.setAll({
                strokeOpacity: 0,
                visible: false
            });
            dotSeries.fills.template.setAll({
                visible: false,
                fillOpacity: 0
            });

            // recolor dots
            dotSeries.bulletsContainer.setAll({
                fill: color
            });

            dotSeries.data.setAll(slice.dataItem.dataContext.breakdown);
            xAxis.data.setAll(slice.dataItem.dataContext.breakdown);

            currentSlice = slice;
        });

        dotSeries.set("tooltip", null);
        dotSeries.bulletsContainer.set("tooltipText", "");
        xAxis.get("renderer").labels.template.set("tooltipText", "");
        yAxis.get("renderer").labels.template.set("tooltipText", "");


        pieSeries.labels.template.set("forceHidden", true);
        pieSeries.ticks.template.set("forceHidden", true);

        pieSeries.data.setAll(data);

        // activate first slice
        pieSeries.events.on("datavalidated", function () {
            pieSeries.slices.getIndex(0).set("active", true);
        });

        dotChart.appear(1000, 100);

        root._logo?.dispose();
    });

}
