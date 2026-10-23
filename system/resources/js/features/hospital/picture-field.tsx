import { useEffect, useMemo, useState } from "react";
import type { RefObject } from "react";
import InputError from "@/components/input-error";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { CropDialog } from "@/features/hospital/crop-dialog";
import type { CropKind } from "@/features/hospital/crop-math";
import type { LogoLimits } from "@/features/hospital/types";

const MAX_ORIGINAL_BYTES = 25 * 1024 * 1024;

/** An address for a picture just chosen, so it can show before it is saved. */
export function usePickedUrl(file: File | null): string | null {
    const url = useMemo(
        () => (file ? URL.createObjectURL(file) : null),
        [file],
    );

    useEffect(() => {
        return () => {
            if (url) {
                URL.revokeObjectURL(url);
            }
        };
    }, [url]);

    return url;
}

/**
 * Check a chosen picture before the crop opens. The server checks the saved
 * picture again (real file type, size, pixels) and is the one that decides.
 * Returns the problem in words, or null when the file looks fine.
 */
export function pictureProblem(file: File, noun: string): string | null {
    if (!["image/png", "image/jpeg"].includes(file.type)) {
        return `The ${noun} must be a PNG or JPG picture.`;
    }

    // The crop makes it small. This only stops a file too big to open here.
    if (file.size > MAX_ORIGINAL_BYTES) {
        return `That file is too big to open. Choose a smaller ${noun} picture.`;
    }

    return null;
}

type PictureFieldProps = {
    id: string;
    /** "logo" or "signature", used in messages. */
    noun: string;
    /** Logo: square crop. Signature: wide strip. */
    cropKind: CropKind;
    label: string;
    /** Address of the saved picture, or null. */
    currentUrl: string | null;
    /** A cropped picture is waiting for Save settings. */
    ready: boolean;
    limits: LogoLimits;
    error?: string;
    inputRef: RefObject<HTMLInputElement | null>;
    /** The cropped file (null when refused) and why it was refused. */
    onPick: (file: File | null, problem: string | null) => void;
    onRemove: () => void;
    removeLabel: string;
};

/**
 * One picture on the Hospital settings screen: the saved picture, a solid
 * file button, the limits, and a Remove button. Choosing a file opens the
 * crop dialog; only the cropped picture is kept for upload. Used for the
 * logo and for each signature.
 */
export function PictureField({
    id,
    noun,
    cropKind,
    label,
    currentUrl,
    ready,
    limits,
    error,
    inputRef,
    onPick,
    onRemove,
    removeLabel,
}: PictureFieldProps) {
    const [choosing, setChoosing] = useState<File | null>(null);

    function closeCrop() {
        setChoosing(null);

        // Let the same file be chosen again.
        if (inputRef.current) {
            inputRef.current.value = "";
        }
    }

    return (
        <div className="flex flex-wrap items-start gap-4">
            <div className="flex h-24 w-40 items-center justify-center rounded-md border bg-muted/40">
                {currentUrl ? (
                    <img
                        src={currentUrl}
                        alt={`Current ${noun}`}
                        className="max-h-full max-w-full object-contain p-1"
                    />
                ) : (
                    <span className="text-sm text-muted-foreground">
                        No {noun}
                    </span>
                )}
            </div>
            <div className="grid min-w-56 flex-1 gap-2">
                <Label htmlFor={id}>
                    {label} {currentUrl ? "(replace)" : "(optional)"}
                </Label>
                <Input
                    ref={inputRef}
                    id={id}
                    name={`${id}_original`}
                    type="file"
                    accept="image/png,image/jpeg"
                    // A solid button inside the box, so it is clearly clickable.
                    className="cursor-pointer p-1 file:mr-3 file:h-full file:cursor-pointer file:rounded-md file:bg-primary file:px-3 file:text-primary-foreground hover:file:bg-primary/90"
                    onChange={(event) => {
                        const file = event.target.files?.[0] ?? null;

                        if (!file) {
                            return;
                        }

                        const problem = pictureProblem(file, noun);

                        if (problem) {
                            event.target.value = "";
                            onPick(null, problem);

                            return;
                        }

                        setChoosing(file);
                    }}
                />
                <p className="text-sm text-muted-foreground">
                    PNG or JPG. You crop it after choosing. The saved picture is
                    at most {Math.round(limits.logo_max_kb / 1024)} MB and{" "}
                    {limits.logo_max_pixels} × {limits.logo_max_pixels} pixels.
                </p>
                {ready && (
                    <p className="text-sm font-medium text-ok">
                        New {noun} ready. Press Save settings to keep it.
                    </p>
                )}
                <InputError message={error} />
                {currentUrl && (
                    <div>
                        <Button className="max-sm:w-full"
                            type="button"
                            variant="outline"
                            onClick={onRemove}
                        >
                            {removeLabel}
                        </Button>
                    </div>
                )}
            </div>

            <CropDialog
                file={choosing}
                kind={cropKind}
                maxBytes={limits.logo_max_kb * 1024}
                onSave={(cropped) => {
                    onPick(cropped, null);
                    closeCrop();
                }}
                onCancel={closeCrop}
            />
        </div>
    );
}
