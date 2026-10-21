import { useForm } from "@inertiajs/react";
import {
    DownloadIcon,
    FileSpreadsheetIcon,
    UploadIcon,
    XIcon,
} from "lucide-react";
import { useRef, useState } from "react";
import type { FormEvent } from "react";
import ImportController from "@/actions/App/Http/Controllers/Records/ImportController";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import type { ImportLimits } from "@/features/import/types";
import { formatCount } from "@/lib/format";

type UploadPanelProps = {
    limits: ImportLimits;
    /** A file is already waiting: say that a new upload replaces it. */
    hasPreview: boolean;
};

/**
 * Steps 1 and 2 of the downtime import: download the template to keep
 * (do it now, before the system is ever down), and upload the filled file
 * for a preview. Nothing is saved by uploading.
 */
export function UploadPanel({ limits, hasPreview }: UploadPanelProps) {
    const input = useRef<HTMLInputElement>(null);
    const form = useForm<{ file: File | null }>({ file: null });
    const [preparing, setPreparing] = useState(false);

    // The browser saves the file by itself, so there is nothing to wait for:
    // show "Preparing..." for a moment so the click is seen to work.
    function startDownload() {
        setPreparing(true);
        window.setTimeout(() => setPreparing(false), 2500);
    }

    function chooseFile(file: File | null) {
        form.setData("file", file);
        form.clearErrors();
    }

    function clearFile() {
        chooseFile(null);

        if (input.current) {
            input.current.value = "";
        }
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        form.post(ImportController.upload.url(), {
            forceFormData: true,
            onSuccess: () => {
                form.reset();

                if (input.current) {
                    input.current.value = "";
                }
            },
        });
    }

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <section className="rounded-lg border bg-card p-4">
                <h2 className="text-base font-semibold">
                    1. Download the template
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    For when the system is down. Keep a copy on each office PC
                    and a printed one. Download it again after the lists
                    (branches, ranks, diagnoses) change.
                </p>
                <Button
                    asChild
                    size="lg"
                    className="mt-3"
                    aria-disabled={preparing || undefined}
                >
                    {/* A plain link: the browser saves the file. */}
                    <a
                        href={ImportController.template.url()}
                        download
                        onClick={(event) => {
                            if (preparing) {
                                event.preventDefault();

                                return;
                            }

                            startDownload();
                        }}
                    >
                        {preparing ? <Spinner /> : <DownloadIcon />}
                        {preparing
                            ? "Preparing the file…"
                            : "Download template"}
                    </a>
                </Button>
            </section>

            <section className="rounded-lg border bg-card p-4">
                <h2 className="text-base font-semibold">
                    2. Upload the filled file
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    An Excel (.xlsx) or CSV (.csv) file, up to {limits.max_mb}{" "}
                    MB and {formatCount(limits.max_rows)} rows. You will see a
                    preview of every row first. Nothing is saved until you
                    confirm.
                    {hasPreview &&
                        " Uploading a new file replaces the preview below."}
                </p>
                <form onSubmit={submit} className="mt-3 grid gap-2">
                    <Label htmlFor="import-file">File</Label>
                    {/* The real input is hidden: the visible button and the name box below do the work. */}
                    <input
                        id="import-file"
                        ref={input}
                        type="file"
                        accept=".xlsx,.csv"
                        className="sr-only"
                        tabIndex={-1}
                        aria-invalid={form.errors.file ? true : undefined}
                        aria-describedby={
                            form.errors.file ? "import-file-error" : undefined
                        }
                        onChange={(event) =>
                            chooseFile(event.target.files?.[0] ?? null)
                        }
                    />
                    <div
                        className={`flex flex-wrap items-center gap-3 rounded-md border-2 border-dashed p-3 ${
                            form.errors.file
                                ? "border-bad"
                                : form.data.file
                                  ? "border-primary/60 bg-primary/5"
                                  : "border-input"
                        }`}
                    >
                        <Button
                            type="button"
                            variant="secondary"
                            size="lg"
                            className="border border-input"
                            onClick={() => input.current?.click()}
                        >
                            <UploadIcon />
                            {form.data.file ? "Change file" : "Choose file"}
                        </Button>
                        {form.data.file ? (
                            <div className="flex min-w-0 flex-1 items-center gap-2 text-sm">
                                <FileSpreadsheetIcon className="size-4 shrink-0 text-primary" />
                                <span className="truncate font-medium">
                                    {form.data.file.name}
                                </span>
                                <span className="shrink-0 text-muted-foreground">
                                    {formatFileSize(form.data.file.size)}
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="ml-auto size-7 shrink-0"
                                    aria-label="Remove the chosen file"
                                    onClick={clearFile}
                                >
                                    <XIcon />
                                </Button>
                            </div>
                        ) : (
                            <span className="text-sm text-muted-foreground">
                                No file chosen yet. Click <b>Choose file</b>,
                                then <b>Upload and check</b>.
                            </span>
                        )}
                    </div>
                    {form.errors.file && (
                        <p
                            id="import-file-error"
                            role="alert"
                            className="text-sm text-bad"
                        >
                            {form.errors.file}
                        </p>
                    )}
                    <div>
                        <Button
                            type="submit"
                            size="lg"
                            disabled={
                                form.processing || form.data.file === null
                            }
                        >
                            {form.processing && <Spinner />}
                            Upload and check
                        </Button>
                    </div>
                </form>
            </section>
        </div>
    );
}

/** 1.4 MB, 820 KB. */
function formatFileSize(bytes: number): string {
    return bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}
