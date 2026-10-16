/*
 * The maths and pixel work behind the picture crop dialog. No library: only
 * the browser canvas. Kept apart from the dialog so it is easy to read.
 */

/** What is being cropped. The logo is square, a signature is a wide strip (3:1). */
export type CropKind = 'logo' | 'signature';

/** The size of the saved picture, in pixels (well under the 1000 x 1000 limit). */
export const OUTPUT_SIZE: Record<CropKind, { width: number; height: number }> =
    {
        logo: { width: 600, height: 600 },
        signature: { width: 900, height: 300 },
    };

/** The picture as it is placed in the crop frame. */
export type Placement = {
    /** Quarter turns clockwise, 0 to 3. */
    turns: number;
    /** 1 = the whole picture just fits in the frame; more = zoomed in. */
    zoom: number;
    /** Where the middle of the picture is, in frame pixels. */
    x: number;
    y: number;
};

/** Size of the picture after the quarter turns. */
export function turnedSize(
    image: { width: number; height: number },
    turns: number,
): { width: number; height: number } {
    return turns % 2 === 0
        ? { width: image.width, height: image.height }
        : { width: image.height, height: image.width };
}

/** Scale at which the whole picture fits inside the frame (zoom 1). */
export function containScale(
    size: { width: number; height: number },
    frame: { width: number; height: number },
): number {
    return Math.min(frame.width / size.width, frame.height / size.height);
}

/** The zoom at which the picture just covers the whole frame. */
export function coverZoom(
    size: { width: number; height: number },
    frame: { width: number; height: number },
): number {
    const cover = Math.max(frame.width / size.width, frame.height / size.height);

    return cover / containScale(size, frame);
}

/**
 * Keep the picture from being dragged away: along an axis where it is bigger
 * than the frame, the frame must stay inside it; where it is smaller, it
 * stays in the middle.
 */
export function clampPlacement(
    placement: Placement,
    image: { width: number; height: number },
    frame: { width: number; height: number },
): Placement {
    const size = turnedSize(image, placement.turns);
    const scale = containScale(size, frame) * placement.zoom;

    const limit = (picture: number, box: number) =>
        Math.max(0, (picture * scale - box) / 2);
    const dx = limit(size.width, frame.width);
    const dy = limit(size.height, frame.height);
    const midX = frame.width / 2;
    const midY = frame.height / 2;

    return {
        ...placement,
        x: Math.min(midX + dx, Math.max(midX - dx, placement.x)),
        y: Math.min(midY + dy, Math.max(midY - dy, placement.y)),
    };
}

/** The starting placement: centred, zoomed so the picture covers the frame. */
export function startPlacement(
    image: { width: number; height: number },
    frame: { width: number; height: number },
    turns = 0,
): Placement {
    return {
        turns,
        zoom: coverZoom(turnedSize(image, turns), frame),
        x: frame.width / 2,
        y: frame.height / 2,
    };
}

/**
 * Draw the picture into a canvas that has the frame's proportions.
 * `pixelRatio` draws the same view at a smaller or larger size.
 */
export function drawCrop(
    canvas: HTMLCanvasElement,
    image: HTMLImageElement,
    placement: Placement,
    frame: { width: number; height: number },
    pixelRatio = 1,
    background: string | null = null,
): void {
    canvas.width = Math.round(frame.width * pixelRatio);
    canvas.height = Math.round(frame.height * pixelRatio);

    const context = canvas.getContext('2d');

    if (!context) {
        return;
    }

    context.clearRect(0, 0, canvas.width, canvas.height);

    if (background) {
        context.fillStyle = background;
        context.fillRect(0, 0, canvas.width, canvas.height);
    }

    const size = turnedSize(
        { width: image.naturalWidth, height: image.naturalHeight },
        placement.turns,
    );
    const scale = containScale(size, frame) * placement.zoom * pixelRatio;

    context.save();
    context.imageSmoothingQuality = 'high';
    context.translate(placement.x * pixelRatio, placement.y * pixelRatio);
    context.rotate((placement.turns * Math.PI) / 2);
    context.scale(scale, scale);
    context.drawImage(
        image,
        -image.naturalWidth / 2,
        -image.naturalHeight / 2,
    );
    context.restore();
}

/**
 * Make near-white pixels transparent (for a signature photographed on paper).
 * Pure white and very light grey go fully clear; slightly darker pixels fade
 * out a little so the edge of the pen stroke stays smooth.
 */
export function removeWhiteBackground(canvas: HTMLCanvasElement): void {
    const context = canvas.getContext('2d');

    if (!context) {
        return;
    }

    const picture = context.getImageData(0, 0, canvas.width, canvas.height);
    const data = picture.data;

    for (let i = 0; i < data.length; i += 4) {
        const lightest = Math.min(data[i], data[i + 1], data[i + 2]);

        if (lightest >= 245) {
            data[i + 3] = 0;
        } else if (lightest >= 200) {
            data[i + 3] = Math.round((data[i + 3] * (245 - lightest)) / 45);
        }
    }

    context.putImageData(picture, 0, 0);
}

function toBlob(
    canvas: HTMLCanvasElement,
    type: string,
    quality?: number,
): Promise<Blob | null> {
    return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

/**
 * The cropped picture as a file. PNG (it keeps transparency). If the PNG is
 * over `maxBytes`, it is drawn smaller until it fits, and as a last step
 * saved as JPG on white. Returns null if the browser cannot make the file.
 */
export async function exportCrop(
    image: HTMLImageElement,
    placement: Placement,
    kind: CropKind,
    clearWhite: boolean,
    maxBytes: number,
): Promise<File | null> {
    const frame = OUTPUT_SIZE[kind];
    const canvas = document.createElement('canvas');
    const name = kind === 'logo' ? 'logo' : 'signature';

    for (const ratio of [1, 0.75, 0.5, 0.35]) {
        drawCrop(canvas, image, placement, frame, ratio);

        if (clearWhite) {
            removeWhiteBackground(canvas);
        }

        const blob = await toBlob(canvas, 'image/png');

        if (blob && blob.size <= maxBytes) {
            return new File([blob], `${name}.png`, { type: 'image/png' });
        }
    }

    // Still too big (a detailed photo): a JPG on white is much smaller.
    drawCrop(canvas, image, placement, frame, 0.5, '#ffffff');

    const jpg = await toBlob(canvas, 'image/jpeg', 0.85);

    return jpg && jpg.size <= maxBytes
        ? new File([jpg], `${name}.jpg`, { type: 'image/jpeg' })
        : null;
}
