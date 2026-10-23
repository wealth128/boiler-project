import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import { SvgChart } from "@/features/reports/svg-chart";
import {
    ALLOWED_ATTRIBUTES,
    ALLOWED_TAGS,
    parseChartSvg,
} from "@/features/reports/svg-tree";
import type { ChartNode } from "@/features/reports/svg-tree";

/** Every tag name in a tree. */
function tags(node: ChartNode | string): string[] {
    return typeof node === "string"
        ? []
        : [node.tag, ...node.children.flatMap(tags)];
}

/** Every text in a tree, joined. */
function texts(node: ChartNode | string): string {
    return typeof node === "string" ? node : node.children.map(texts).join("");
}

/** Every prop name in a tree. */
function propNames(node: ChartNode | string): string[] {
    return typeof node === "string"
        ? []
        : [...Object.keys(node.props), ...node.children.flatMap(propNames)];
}

const svg = (inner: string) =>
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" role="img" aria-label="x">${inner}</svg>`;

describe("the chart SVG whitelist", () => {
    it("allows exactly the chart tags", () => {
        expect([...ALLOWED_TAGS].sort()).toEqual([
            "desc",
            "g",
            "line",
            "path",
            "rect",
            "svg",
            "text",
            "title",
            "tspan",
        ]);

        const tree = parseChartSvg(
            svg(
                '<g class="rc-bar" tabindex="0"><title>t</title><desc>d</desc>' +
                    '<rect x="1" y="2" width="3" height="4" rx="1" fill="#1F3864"/>' +
                    '<line x1="0" y1="0" x2="5" y2="5" stroke="#D9DEE7" stroke-width="1"/>' +
                    '<path d="M0 0 L5 5 Z" fill="none" stroke="#000"/>' +
                    '<text x="1" y="2" font-size="12.5" text-anchor="middle">a<tspan dx="2">b</tspan></text></g>',
            ),
        );

        expect(tree).not.toBeNull();
        expect(new Set(tags(tree!))).toEqual(ALLOWED_TAGS);
    });

    it("keeps the chart attributes and nothing else", () => {
        const tree = parseChartSvg(
            svg(
                '<rect x="1" y="2" width="3" height="4" fill="#fff" stroke-width="1"/>',
            ),
        )!;
        const rect = tree.children[0] as ChartNode;

        expect(rect.props).toEqual({
            x: "1",
            y: "2",
            width: "3",
            height: "4",
            fill: "#fff",
            strokeWidth: "1",
        });

        // Nothing on the list is an event or a link.
        for (const name of Object.keys(ALLOWED_ATTRIBUTES)) {
            expect(name).not.toMatch(/^on/i);
            expect(name).not.toMatch(/href/i);
        }
    });

    it("drops every on* handler", () => {
        const tree = parseChartSvg(
            svg(
                '<rect x="1" onclick="alert(1)" onmouseover="alert(2)" onload="alert(3)" onfocus="alert(4)" ONCLICK="alert(5)"/>' +
                    '<g onmouseenter="alert(6)"><text x="1" onerror="alert(7)">a</text></g>',
            ),
        )!;

        expect(propNames(tree).filter((name) => /^on/i.test(name))).toEqual([]);
        expect(JSON.stringify(tree)).not.toContain("alert");
        expect(propNames(tree)).toContain("x");
    });

    it("drops href and xlink:href", () => {
        const tree = parseChartSvg(
            svg(
                '<rect href="java0073cript:alert(1)" xlink:href="java0073cript:alert(2)" x="1"/>' +
                    '<g href="https://evil.example"><text xlink:href="#x">a</text></g>',
            ),
        )!;

        expect(JSON.stringify(tree)).not.toMatch(/href|javascript|evil/i);
    });

    it("drops a style attribute that has url() or anything that can load or run", () => {
        for (const style of [
            "fill:url(#evil)",
            "fill: URL( 'https://evil.example/x' )",
            "background:url(java0073cript:alert(1))",
            "fill:red;behavior:url(x.htc)",
            "width:expression(alert(1))",
            "fill:red;@import 'x'",
            "fill:\\75rl(x)",
        ]) {
            const tree = parseChartSvg(svg(`<rect x="1" style="${style}"/>`))!;
            const rect = tree.children[0] as ChartNode;

            expect(rect.props.style, style).toBeUndefined();
        }
    });

    it("keeps a safe style attribute, only with the listed properties", () => {
        const tree = parseChartSvg(
            svg(
                '<text style="fill:#1F3864; font-size:12; position:fixed; font-weight:bold">a</text>',
            ),
        )!;
        const text = tree.children[0] as ChartNode;

        expect(text.props.style).toEqual({
            fill: "#1F3864",
            fontSize: "12",
            fontWeight: "bold",
        });
    });

    it("drops unknown tags together with what is inside them", () => {
        const tree = parseChartSvg(
            svg(
                '<script>alert(1)</script><foreignObject><div onclick="x">hi</div></foreignObject>' +
                    '<image href="x.png"/><a href="http://evil"><text x="1">link</text></a><use href="#a"/>' +
                    "<style>*{fill:url(#x)}</style><iframe src=\"x\"></iframe><circle cx=\"1\"/><animate attributeName=\"x\"/>" +
                    '<g><rect x="1" width="2" height="3"/></g>',
            ),
        )!;

        expect(tags(tree).sort()).toEqual(["g", "rect", "svg"]);
        expect(texts(tree)).toBe("");
    });

    it("drops attributes that are not on the list, and bad values of the ones that are", () => {
        const tree = parseChartSvg(
            svg(
                '<rect x="1" id="a" data-x="1" onclick="x" fill="url(#a)" width="java0073cript:1" stroke="red" transform="translate(1 2)"/>' +
                    '<path d="java0073cript:alert(1)" transform="scale(1) evil()"/>' +
                    '<g class="a b" tabindex="1" role="button"/>',
            ),
        )!;
        const [rect, path, g] = tree.children as ChartNode[];

        expect(rect.props.fill).toBeUndefined();
        expect(rect.props.width).toBeUndefined();
        expect(rect.props.id).toBeUndefined();
        expect(rect.props["data-x"]).toBeUndefined();
        expect(rect.props.stroke).toBe("red");
        expect(rect.props.transform).toBe("translate(1 2)");
        expect(path.props.d).toBeUndefined();
        expect(path.props.transform).toBeUndefined();
        // Only tabindex 0 and role img are kept.
        expect(g.props).toEqual({ className: "a b" });
    });

    it("draws a diagnosis named like a script tag or an event handler as plain text", () => {
        // The server escapes the name: ChartSvg writes every text through htmlspecialchars.
        const evil =
            '<script>alert(1)</script> onmouseover="alert(2)" <img src=x onerror=alert(3)> & "q"';
        const escaped = evil
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
        const markup = svg(
            `<g class="rc-bar" tabindex="0"><title>${escaped}: 1 visit</title><text x="1" y="2" font-size="12.5">${escaped}</text></g>`,
        );

        const tree = parseChartSvg(markup)!;

        // The characters are there as text, exactly...
        expect(texts(tree)).toBe(`${evil}: 1 visit${evil}`);
        // ...and none of it became an element or an attribute.
        expect(tags(tree).sort()).toEqual(["g", "svg", "text", "title"]);
        expect(propNames(tree).filter((name) => /^on/i.test(name))).toEqual(
            [],
        );

        // Drawn as React: the page gets &lt;script&gt;, not a script, and no handler.
        const html = renderToStaticMarkup(
            createElement(SvgChart, { svg: markup }),
        );

        expect(html).toContain("&lt;script&gt;alert(1)&lt;/script&gt;");
        expect(html).toContain("onmouseover=&quot;alert(2)&quot;");
        expect(html).not.toContain("<script");
        expect(html).not.toContain("<img");
        expect(html).not.toMatch(/<[^>]* on\w+=/i);
    });

    it("draws nothing for text that is not an SVG made by the chart drawer", () => {
        expect(parseChartSvg("")).toBeNull();
        expect(parseChartSvg("<div>hello</div>")).toBeNull();
        expect(parseChartSvg("<script>alert(1)</script>")).toBeNull();
        expect(
            renderToStaticMarkup(
                createElement(SvgChart, {
                    svg: "<img src=x onerror=alert(1)>",
                }),
            ),
        ).toBe("");
    });

    it("draws a chart as an svg with its role and aria-label", () => {
        const html = renderToStaticMarkup(
            createElement(SvgChart, {
                svg: svg(
                    '<rect x="1" y="1" width="2" height="2" fill="#1F3864"/>',
                ),
            }),
        );

        expect(html).toContain('role="img"');
        expect(html).toContain('aria-label="x"');
        expect(html).toContain("<rect");
    });
});
