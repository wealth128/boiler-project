import { RotateCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    OUTPUT_SIZE,
    clampPlacement,
    coverZoom,
    drawCrop,
    exportCrop,
    removeWhiteBackground,
    startPlacement,
    turnedSize,
} from '@/features/hospital/crop-math';
import type { CropKind, Placement } from '@/features/hospital/crop-math';

type CropDialogProps = {
    /** The file just chosen. The dialog is open while there is one. */
    file: File | null;
    kind: CropKind;
    /** Largest saved picture, in bytes. */
    maxBytes: number;
    /** Save was pressed: the cropped picture. */
    onSave: (file: File) => void;
    /** Cancel, Esc or close: nothing is kept. */
    onCancel: () => void;
};

/** Pixels the picture moves for one arrow key press, in frame pixels. */
const KEY_STEP = 15;

const CHECKERBOARD =
    'repeating-conic-gradient(#e5e7eb 0% 25%, #ffffff 0% 50%) 50% / 12px 12px';

/**
 * Crop a picture before it is uploaded (logo: square, signature: wide strip).
 * Drag to move, the slider or + / - to zoom, Rotate for quarter turns, and a
 * preview of the saved result. Save hands back the cropped file, Cancel (or
 * Esc) keeps nothing. Made with the browser canvas, no library.
 */
export function CropDialog({
    file,
    kind,
    maxBytes,
    onSave,
    onCancel,
}: CropDialogProps) {
    return (
        <Dialog
            open={file !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onCancel();
                }
            }}
        >
            <DialogContent className="max-h-[95vh] overflow-y-auto sm:max-w-xl">
                {file && (
                    <CropBody
                        key={`${file.name}-${file.size}-${file.lastModified}`}
                        file={file}
                        kind={kind}
                        maxBytes={maxBytes}
                        onSave={onSave}
                        onCancel={onCancel}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function CropBody({
    file,
    kind,
    maxBytes,
    onSave,
    onCancel,
}: Omit<CropDialogProps, 'file'> & { file: File }) {
    const frame = OUTPUT_SIZE[kind];
    const noun = kind === 'logo' ? 'logo' : 'signature';

    const [image, setImage] = useState<HTMLImageElement | null>(null);
    const [placement, setPlacement] = useState<Placement | null>(null);
    const [clearWhite, setClearWhite] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const editCanvas = useRef<HTMLCanvasElement>(null);
    const previewCanvas = useRef<HTMLCanvasElement>(null);
    const drag = useRef<{ x: number; y: number } | null>(null);

    // Load the chosen file.
    useEffect(() => {
        const url = URL.createObjectURL(file);
        const picture = new Image();

        picture.onload = () => {
            setImage(picture);
            setPlacement(
                startPlacement(
                    {
                        width: picture.naturalWidth,
                        height: picture.naturalHeight,
                    },
                    frame,
                ),
            );
        };
        picture.onerror = () =>
            setProblem('This file could not be opened as a picture.');
        picture.src = url;

        return () => URL.revokeObjectURL(url);
    }, [file, frame]);

    // The editing view: exactly what will be saved, on white.
    useEffect(() => {
        if (image && placement && editCanvas.current) {
            drawCrop(editCanvas.current, image, placement, frame, 1, '#ffffff');
        }
    }, [image, placement, frame]);

    // The preview: the saved result, so clear pixels show the checkerboard.
    useEffect(() => {
        if (image && placement && previewCanvas.current) {
            drawCrop(previewCanvas.current, image, placement, frame, 0.5);

            if (kind === 'signature' && clearWhite) {
                removeWhiteBackground(previewCanvas.current);
            }
        }
    }, [image, placement, frame, kind, clearWhite]);

    const natural = image
        ? { width: image.naturalWidth, height: image.naturalHeight }
        : null;

    function change(next: Placement) {
        if (natural) {
            setPlacement(clampPlacement(next, natural, frame));
        }
    }

    function moveBy(dx: number, dy: number) {
        if (placement) {
            change({ ...placement, x: placement.x + dx, y: placement.y + dy });
        }
    }

    function zoomTo(zoom: number) {
        if (placement && natural) {
            const max = coverZoom(turnedSize(natural, placement.turns), frame) * 4;

            change({ ...placement, zoom: Math.min(max, Math.max(1, zoom)) });
        }
    }

    function rotate() {
        if (natural && placement) {
            setPlacement(
                startPlacement(natural, frame, (placement.turns + 1) % 4),
            );
        }
    }

    function onPointerDown(event: PointerEvent<HTMLCanvasElement>) {
        event.currentTarget.setPointerCapture(event.pointerId);
        drag.current = { x: event.clientX, y: event.clientY };
    }

    function onPointerMove(event: PointerEvent<HTMLCanvasElement>) {
        if (!drag.current) {
            return;
        }

        const box = event.currentTarget.getBoundingClientRect();
        const ratio = frame.width / box.width;

        moveBy(
            (event.clientX - drag.current.x) * ratio,
            (event.clientY - drag.current.y) * ratio,
        );
        drag.current = { x: event.clientX, y: event.clientY };
    }

    function onKeyDown(event: KeyboardEvent<HTMLCanvasElement>) {
        const moves: Record<string, [number, number]> = {
            ArrowLeft: [-KEY_STEP, 0],
            ArrowRight: [KEY_STEP, 0],
            ArrowUp: [0, -KEY_STEP],
            ArrowDown: [0, KEY_STEP],
        };

        if (moves[event.key]) {
            event.preventDefault();
            moveBy(...moves[event.key]);
        } else if (event.key === '+' || event.key === '=') {
            event.preventDefault();
            zoomTo((placement?.zoom ?? 1) * 1.1);
        } else if (event.key === '-' || event.key === '_') {
            event.preventDefault();
            zoomTo((placement?.zoom ?? 1) / 1.1);
        }
    }

    async function save() {
        if (!image || !placement) {
            return;
        }

        setSaving(true);
        setProblem(null);

        const cropped = await exportCrop(
            image,
            placement,
            kind,
            kind === 'signature' && clearWhite,
            maxBytes,
        );

        setSaving(false);

        if (cropped) {
            onSave(cropped);
        } else {
            setProblem(
                `The cropped ${noun} is still over 1 MB. Zoom in on a smaller part and try again.`,
            );
        }
    }

    const maxZoom =
        placement && natural
            ? coverZoom(turnedSize(natural, placement.turns), frame) * 4
            : 4;

    return (
        <>
            <DialogHeader>
                <DialogTitle>Crop the {noun}</DialogTitle>
                <DialogDescription>
                    Drag the picture to move it, use the slider to zoom, and
                    turn it if needed. Save uploads what you see in the
                    preview.
                </DialogDescription>
            </DialogHeader>

            <div
                className={
                    kind === 'logo'
                        ? 'mx-auto w-full max-w-[360px]'
                        : 'mx-auto w-full max-w-[480px]'
                }
            >
                <canvas
                    ref={editCanvas}
                    width={frame.width}
                    height={frame.height}
                    tabIndex={0}
                    role="img"
                    aria-label={`Crop area. Drag, or use the arrow keys to move the ${noun}, and plus and minus to zoom.`}
                    className="h-auto w-full cursor-grab touch-none rounded-md border bg-white active:cursor-grabbing"
                    style={{ aspectRatio: `${frame.width} / ${frame.height}` }}
                    onPointerDown={onPointerDown}
                    onPointerMove={onPointerMove}
                    onPointerUp={() => (drag.current = null)}
                    onPointerCancel={() => (drag.current = null)}
                    onKeyDown={onKeyDown}
                />
            </div>

            <div className="flex flex-wrap items-center gap-3">
                <Label htmlFor="crop-zoom" className="shrink-0">
                    Zoom
                </Label>
                <input
                    id="crop-zoom"
                    type="range"
                    min={1}
                    max={maxZoom}
                    step={0.01}
                    value={placement?.zoom ?? 1}
                    disabled={!placement}
                    onChange={(event) => zoomTo(Number(event.target.value))}
                    className="min-w-32 flex-1 accent-primary"
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={rotate}
                    disabled={!placement}
                >
                    <RotateCw aria-hidden="true" /> Rotate 90°
                </Button>
            </div>

            {kind === 'signature' && (
                <label className="flex cursor-pointer items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={clearWhite}
                        onChange={(event) => setClearWhite(event.target.checked)}
                        className="size-4 accent-primary"
                    />
                    Remove white background (saved as PNG)
                </label>
            )}

            <div>
                <p className="mb-1 text-sm font-medium">Preview</p>
                <canvas
                    ref={previewCanvas}
                    aria-label={`Preview of the cropped ${noun}`}
                    className="h-auto rounded-md border"
                    style={{
                        background: CHECKERBOARD,
                        width: kind === 'logo' ? 96 : 240,
                        aspectRatio: `${frame.width} / ${frame.height}`,
                    }}
                />
            </div>

            {problem && (
                <p role="alert" className="text-sm text-bad">
                    {problem}
                </p>
            )}

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onCancel}
                    disabled={saving}
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={save}
                    disabled={!placement || saving}
                >
                    Save
                </Button>
            </DialogFooter>
        </>
    );
}
