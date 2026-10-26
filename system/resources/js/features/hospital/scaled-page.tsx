import { useLayoutEffect, useRef, useState } from "react";
import type { ReactNode } from "react";

/** An A4 sheet at 96 dpi: 210 x 297 mm. */
const PAGE_WIDTH = 794;
const PAGE_HEIGHT = 1123;

type ScaledPageProps = {
    children: ReactNode;
    /** Read by screen readers. */
    label: string;
};

/**
 * A sheet of A4 paper, centred, with a light shadow. The sheet is laid out at
 * its real printed width and then scaled down to the width of its panel, so
 * what you see is the shape of the printout (A4 proportion, same line breaks)
 * at any screen size. It never scales up and never causes sideways scrolling.
 *
 * The scaled copy is a picture of the page, so its small text is smaller than
 * 14 px on a phone. The form beside it is the readable part.
 */
export function ScaledPage({ children, label }: ScaledPageProps) {
    const box = useRef<HTMLDivElement>(null);
    const [scale, setScale] = useState(1);

    useLayoutEffect(() => {
        const element = box.current;

        if (!element) {
            return;
        }

        const update = () =>
            setScale(Math.min(1, element.clientWidth / PAGE_WIDTH));

        update();

        const observer = new ResizeObserver(update);
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return (
        <div ref={box} className="w-full min-w-0">
            <div
                className="mx-auto"
                style={{
                    width: PAGE_WIDTH * scale,
                    height: PAGE_HEIGHT * scale,
                }}
            >
                <article
                    aria-label={label}
                    className="paper origin-top-left overflow-hidden rounded-sm shadow-md ring-1 ring-border"
                    style={{
                        width: PAGE_WIDTH,
                        height: PAGE_HEIGHT,
                        transform: `scale(${scale})`,
                        // The printout margin (12 mm).
                        padding: 45,
                    }}
                >
                    {children}
                </article>
            </div>
        </div>
    );
}
