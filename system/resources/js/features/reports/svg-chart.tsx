import { createElement } from "react";
import type { ReactNode } from "react";
import { parseChartSvg } from "@/features/reports/svg-tree";
import type { ChartNode } from "@/features/reports/svg-tree";

/**
 * Draws the SVG a chart comes as (ChartSvg on the server) as React elements,
 * not as raw HTML: the interface never inserts markup it was sent
 * (planning/security.md section 2; a security test scans for raw HTML
 * insertion). svg-tree.ts keeps only chart tags and attributes and checks
 * every value; text is always placed as text.
 */
function build(node: ChartNode, key: number): ReactNode {
    return createElement(
        node.tag,
        { ...node.props, key },
        ...node.children.map((child, index) =>
            typeof child === "string" ? child : build(child, index),
        ),
    );
}

/** One chart drawing. Nothing when the string is not an SVG made by the chart drawer. */
export function SvgChart({ svg }: { svg: string }) {
    const tree = parseChartSvg(svg);

    return tree ? build(tree, 0) : null;
}
