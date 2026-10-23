/**
 * Turns the SVG text of a report chart (ChartSvg on the server) into a plain
 * tree of allowed elements, so the interface can draw it as React elements
 * and never insert it as raw HTML (planning/security.md section 2).
 *
 * Only chart elements and chart attributes are kept:
 *
 *   tags        svg, g, rect, line, path, text, tspan, title, desc
 *   attributes  geometry (x, y, x1, y1, x2, y2, width, height, rx, ry, dx, dy,
 *               d, viewBox, transform), paint and text style (fill, stroke,
 *               stroke-width, stroke-dasharray, stroke-linecap, stroke-linejoin,
 *               opacity, fill-opacity, stroke-opacity, font-family, font-size,
 *               font-weight, font-style, text-anchor, and a style attribute made
 *               only of those), and class, tabindex, role, aria-label, aria-hidden, xmlns
 *
 * Everything else is dropped: every on* handler, href and xlink:href, any
 * style with url(), any other tag (script, foreignObject, image, a, use,
 * style, iframe ...) together with what is inside it, and any attribute not
 * listed. Each kept value must also match a strict pattern. Text is always
 * text: a diagnosis named "<script>" or "onmouseover=x" is drawn as those
 * characters, never run.
 */

export type ChartNode = {
    tag: string;
    props: Record<string, string | number | Record<string, string>>;
    children: (ChartNode | string)[];
};

export const ALLOWED_TAGS: ReadonlySet<string> = new Set([
    "svg",
    "g",
    "rect",
    "line",
    "path",
    "text",
    "tspan",
    "title",
    "desc",
]);

const NUMBER = /^-?[\d.]+$/;
const NUMBERS = /^[-\d.\s,]+$/;
const PAINT = /^(#[0-9a-fA-F]{3,8}|none|[a-zA-Z]+)$/;
const FONT_FAMILY = /^[\w\s,'"-]+$/;
const TRANSFORM =
    /^(\s*(translate|rotate|scale)\(\s*-?[\d.]+(\s*[ ,]\s*-?[\d.]+){0,2}\s*\))+\s*$/;
const PATH = /^[MmLlHhVvCcSsQqTtAaZz\d\s.,eE+-]+$/;

/** attribute -> [React prop, the value must match]. */
export const ALLOWED_ATTRIBUTES: Readonly<Record<string, [string, RegExp]>> = {
    xmlns: ["xmlns", /^http:\/\/www\.w3\.org\/2000\/svg$/],
    viewBox: ["viewBox", NUMBERS],
    width: ["width", NUMBER],
    height: ["height", NUMBER],
    x: ["x", NUMBER],
    y: ["y", NUMBER],
    x1: ["x1", NUMBER],
    y1: ["y1", NUMBER],
    x2: ["x2", NUMBER],
    y2: ["y2", NUMBER],
    rx: ["rx", NUMBER],
    ry: ["ry", NUMBER],
    dx: ["dx", NUMBER],
    dy: ["dy", NUMBER],
    d: ["d", PATH],
    transform: ["transform", TRANSFORM],
    fill: ["fill", PAINT],
    stroke: ["stroke", PAINT],
    "stroke-width": ["strokeWidth", NUMBER],
    "stroke-dasharray": ["strokeDasharray", NUMBERS],
    "stroke-linecap": ["strokeLinecap", /^(butt|round|square)$/],
    "stroke-linejoin": ["strokeLinejoin", /^(miter|round|bevel)$/],
    opacity: ["opacity", NUMBER],
    "fill-opacity": ["fillOpacity", NUMBER],
    "stroke-opacity": ["strokeOpacity", NUMBER],
    "font-family": ["fontFamily", FONT_FAMILY],
    "font-size": ["fontSize", NUMBER],
    "font-weight": ["fontWeight", /^(bold|normal)$/],
    "font-style": ["fontStyle", /^(italic|normal)$/],
    "text-anchor": ["textAnchor", /^(start|middle|end)$/],
    class: ["className", /^[\w -]+$/],
    tabindex: ["tabIndex", /^0$/],
    role: ["role", /^img$/],
    "aria-label": ["aria-label", /^[^]*$/],
    "aria-hidden": ["aria-hidden", /^true$/],
};

/** The properties a style attribute may set, and what their values may look like. */
const STYLE_PROPERTIES: Readonly<Record<string, [string, RegExp]>> = {
    fill: ["fill", PAINT],
    stroke: ["stroke", PAINT],
    "stroke-width": ["strokeWidth", NUMBER],
    "stroke-dasharray": ["strokeDasharray", NUMBERS],
    opacity: ["opacity", NUMBER],
    "fill-opacity": ["fillOpacity", NUMBER],
    "stroke-opacity": ["strokeOpacity", NUMBER],
    "font-family": ["fontFamily", FONT_FAMILY],
    "font-size": ["fontSize", NUMBER],
    "font-weight": ["fontWeight", /^(bold|normal)$/],
    "font-style": ["fontStyle", /^(italic|normal)$/],
    "text-anchor": ["textAnchor", /^(start|middle|end)$/],
};

/** Anything in a style value that could load or run something. */
const DANGEROUS = /url\s*\(|expression|javascript|@import|\\|<|&#/i;

function decode(text: string): string {
    return text
        .replace(/&#(\d+);/g, (whole, code: string) => {
            const point = Number(code);

            return point > 0 && point <= 0x10ffff ? String.fromCodePoint(point) : whole;
        })
        .replace(/&lt;/g, "<")
        .replace(/&gt;/g, ">")
        .replace(/&quot;/g, '"')
        .replace(/&apos;/g, "'")
        .replace(/&amp;/g, "&");
}

/** A style attribute as a style object: only the listed properties with safe values, or nothing at all when it holds anything dangerous. */
function styleOf(value: string): Record<string, string> | null {
    if (DANGEROUS.test(value)) {
        return null;
    }

    const style: Record<string, string> = {};

    for (const declaration of value.split(";")) {
        const colon = declaration.indexOf(":");

        if (colon < 0) {
            continue;
        }

        const rule = STYLE_PROPERTIES[declaration.slice(0, colon).trim().toLowerCase()];
        const text = declaration.slice(colon + 1).trim();

        if (rule && rule[1].test(text)) {
            style[rule[0]] = text;
        }
    }

    return Object.keys(style).length > 0 ? style : null;
}

/** The props of a tag: only the allowed attributes with a value that matches its pattern. */
function propsOf(attrs: Record<string, string>): ChartNode["props"] {
    const props: ChartNode["props"] = {};

    for (const [name, value] of Object.entries(attrs)) {
        if (name === "style") {
            const style = styleOf(value);

            if (style) {
                props.style = style;
            }

            continue;
        }

        // Own properties only: a name like "constructor" is not an attribute.
        const rule = Object.hasOwn(ALLOWED_ATTRIBUTES, name) ? ALLOWED_ATTRIBUTES[name] : undefined;

        if (rule && rule[1].test(value)) {
            props[rule[0]] = name === "tabindex" ? 0 : value;
        }
    }

    return props;
}

/**
 * The root <svg> of the text as a tree, or null when the text is not an SVG
 * the chart drawer made. A tag that is not allowed, and everything inside it,
 * is left out.
 */
export function parseChartSvg(svg: string): ChartNode | null {
    const token =
        /<(\/?)([a-zA-Z][\w-]*)((?:\s+[\w:-]+="[^"]*")*)\s*(\/?)>|([^<]+)/g;
    const root: ChartNode = { tag: "#root", props: {}, children: [] };
    const stack: ChartNode[] = [root];
    let skipping = 0;

    for (const match of svg.matchAll(token)) {
        const [, closing, tag, rawAttributes, selfClosing, text] = match;

        if (text !== undefined) {
            if (skipping === 0) {
                stack[stack.length - 1].children.push(decode(text));
            }

            continue;
        }

        if (closing) {
            if (skipping > 0) {
                skipping -= 1;
            } else if (stack.length > 1) {
                stack.pop();
            }

            continue;
        }

        if (!ALLOWED_TAGS.has(tag) || skipping > 0) {
            if (!selfClosing) {
                skipping += 1;
            }

            continue;
        }

        const attrs: Record<string, string> = {};

        for (const attribute of rawAttributes.matchAll(/([\w:-]+)="([^"]*)"/g)) {
            attrs[attribute[1]] = decode(attribute[2]);
        }

        const node: ChartNode = { tag, props: propsOf(attrs), children: [] };
        stack[stack.length - 1].children.push(node);

        if (!selfClosing) {
            stack.push(node);
        }
    }

    const first = root.children.find(
        (child): child is ChartNode => typeof child !== "string",
    );

    return first?.tag === "svg" ? first : null;
}
