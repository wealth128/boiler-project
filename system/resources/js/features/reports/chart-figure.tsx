import { SvgChart } from "@/features/reports/svg-chart";
import type { ReportChart } from "@/features/reports/types";
import { cn } from "@/lib/utils";

/**
 * A report chart, drawn by the server as SVG (ReportCharts), so the screen,
 * Print and the PDF show the same picture. Two drawings come with it: a
 * narrow one for phones (text stays readable at 360 px wide) and a wide one
 * for everything else, including Print. The SVG has role="img" and an
 * aria-label that sums it up; hovering or focusing a bar shows its exact
 * value (the bar's title), and every value is also written beside its bar.
 *
 * The drawing is turned into React elements by SvgChart, which keeps only
 * the tags and attributes the chart drawer writes: no markup is inserted as
 * raw HTML.
 */
export function ChartFigure({ chart }: { chart: ReportChart }) {
    const svg = "[&>svg]:block [&>svg]:h-auto [&>svg]:w-full";

    return (
        <figure
            data-chart={chart.key}
            className="rc-chart mb-3 print:mb-2 print:break-inside-avoid"
        >
            <div
                className={cn(
                    "mx-auto max-w-[420px] min-[700px]:hidden print:hidden",
                    svg,
                )}
            >
                <SvgChart svg={chart.svg_narrow} />
            </div>
            <div
                className={cn(
                    "mx-auto hidden max-w-[760px] min-[700px]:block print:block print:max-w-none",
                    svg,
                )}
            >
                <SvgChart svg={chart.svg} />
            </div>
        </figure>
    );
}
