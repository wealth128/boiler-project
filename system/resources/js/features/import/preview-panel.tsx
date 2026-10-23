import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import ImportController from '@/actions/App/Http/Controllers/Records/ImportController';
import { Chip } from '@/components/shared/chip';
import type { ChipTone } from '@/components/shared/chip';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable } from '@/components/shared/data-table';
import type { DataTableColumn } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import type {
    ImportPreview,
    ImportRow,
    ImportRowStatus,
} from '@/features/import/types';
import { formatCount } from '@/lib/format';

type PreviewPanelProps = {
    preview: ImportPreview;
};

type Show = 'all' | 'problems' | 'errors';

const STATUS_LABEL: Record<ImportRowStatus, string> = {
    ok: 'OK',
    warning: 'Warning',
    error: 'Error',
};

const STATUS_TONE: Record<ImportRowStatus, ChipTone> = {
    ok: 'ok',
    warning: 'warn',
    error: 'bad',
};

function plural(count: number, one: string, many: string): string {
    return `${formatCount(count)} ${count === 1 ? one : many}`;
}

/**
 * Step 3 of the downtime import: the preview. Every row of the file with
 * OK, Warning or Error and the reason in plain words. Admin ticks the rows to
 * import (error rows can't be ticked) and may save a linked patient as a
 * new one. Confirming sends only the row numbers: the server reads the file
 * and checks every row again.
 */
export function PreviewPanel({ preview }: PreviewPanelProps) {
    const initial = useMemo(
        () =>
            new Set(
                preview.rows
                    .filter((row) => row.selected)
                    .map((row) => row.number),
            ),
        [preview.rows],
    );
    const [ticked, setTicked] = useState<Set<number>>(initial);
    const [asNew, setAsNew] = useState<Set<number>>(new Set());
    const [show, setShow] = useState<Show>('all');
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    const { summary } = preview;
    const rows = preview.rows.filter(
        (row) =>
            show === 'all' ||
            (show === 'problems' && row.status !== 'ok') ||
            (show === 'errors' && row.status === 'error'),
    );
    const count = ticked.size;

    function toggle(
        set: Set<number>,
        number: number,
        on: boolean,
    ): Set<number> {
        const next = new Set(set);

        if (on) {
            next.add(number);
        } else {
            next.delete(number);
        }

        return next;
    }

    function confirm() {
        const chosen = [...ticked].sort((a, b) => a - b);

        router.post(
            ImportController.confirm.url(),
            {
                token: preview.token,
                rows: chosen,
                new_patient_rows: chosen.filter((number) => asNew.has(number)),
            },
            {
                onStart: () => {
                    setProcessing(true);
                    setProblem(null);
                },
                onFinish: () => setProcessing(false),
                onSuccess: () => setConfirming(false),
                onError: (errors) => {
                    setConfirming(false);
                    setProblem(
                        errors.import ??
                            errors.rows ??
                            errors.token ??
                            'The import could not be saved.',
                    );
                },
            },
        );
    }

    function cancel() {
        router.post(ImportController.cancel.url(), { token: preview.token });
    }

    const columns: DataTableColumn<ImportRow>[] = [
        {
            key: 'tick',
            header: <span className="sr-only">Import this row</span>,
            cell: (row) => (
                <Checkbox
                    aria-label={`Import row ${row.number}`}
                    checked={ticked.has(row.number)}
                    disabled={row.status === 'error'}
                    onCheckedChange={(on) =>
                        setTicked(toggle(ticked, row.number, on === true))
                    }
                />
            ),
        },
        {
            key: 'number',
            header: 'Row',
            cell: (row) => row.number,
            align: 'right',
        },
        {
            key: 'status',
            header: 'Result',
            cell: (row) => (
                <Chip tone={STATUS_TONE[row.status]}>
                    {STATUS_LABEL[row.status]}
                </Chip>
            ),
        },
        {
            key: 'when',
            header: 'Date & time',
            cell: (row) => `${row.display.date} ${row.display.time}`,
            className: 'font-mono whitespace-nowrap',
        },
        {
            key: 'patient',
            header: 'Patient',
            cell: (row) => (
                <>
                    {row.display.name}
                    {row.display.patient_no && (
                        <div className="font-mono text-xs text-muted-foreground">
                            {row.display.patient_no}
                        </div>
                    )}
                </>
            ),
        },
        {
            key: 'branch',
            header: 'Branch / Rank',
            cell: (row) => row.display.branch_rank,
        },
        {
            key: 'diagnosis',
            header: 'Diagnosis',
            cell: (row) => row.display.diagnosis,
        },
        {
            key: 'category',
            header: 'Category',
            cell: (row) => row.display.category,
        },
        {
            key: 'written_by',
            header: 'Written by',
            cell: (row) => row.display.written_by,
        },
        {
            key: 'notes',
            header: 'What the check found',
            className: 'min-w-64',
            cell: (row) => (
                <div className="grid gap-1">
                    {row.errors.map((text) => (
                        <p key={text} className="text-bad">
                            {text}
                        </p>
                    ))}
                    {row.warnings.map((warning) => (
                        <p key={warning.text} className="text-warn">
                            {warning.text}
                        </p>
                    ))}
                    {row.can_save_as_new && (
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id={`new-patient-${row.number}`}
                                checked={asNew.has(row.number)}
                                onCheckedChange={(on) =>
                                    setAsNew(
                                        toggle(asNew, row.number, on === true),
                                    )
                                }
                            />
                            <Label
                                htmlFor={`new-patient-${row.number}`}
                                className="text-xs font-normal"
                            >
                                Save as a new patient instead
                            </Label>
                        </div>
                    )}
                </div>
            ),
        },
    ];

    return (
        <section className="mt-4 rounded-lg border bg-card">
            <div className="grid gap-3 border-b p-4">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h2 className="text-base font-semibold">
                        3. Check the preview
                    </h2>
                    <span className="text-sm text-muted-foreground">
                        {preview.file_name}
                    </span>
                </div>

                <p className="flex flex-wrap items-center gap-2 text-sm">
                    <Chip tone="ok">{summary.ok} rows OK</Chip>
                    <Chip tone="warn">
                        {plural(summary.warning, 'warning', 'warnings')}
                    </Chip>
                    <Chip tone="bad">
                        {plural(summary.error, 'error', 'errors')}
                    </Chip>
                    <span className="text-muted-foreground">
                        of {plural(summary.total, 'row', 'rows')}. Rows with an
                        error are never imported: fix the file and upload it
                        again.
                    </span>
                </p>

                {problem && (
                    <p
                        role="alert"
                        className="rounded-md border border-bad bg-bad-soft px-3 py-2 text-sm text-bad"
                    >
                        {problem}
                    </p>
                )}

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="import-show">Show</Label>
                        <NativeSelect
                            id="import-show"
                            value={show}
                            onChange={(event) =>
                                setShow(event.target.value as Show)
                            }
                        >
                            <option value="all">All rows</option>
                            <option value="problems">
                                Warnings and errors
                            </option>
                            <option value="errors">Errors only</option>
                        </NativeSelect>
                    </div>
                    <Button className="max-sm:w-full"
                        type="button"
                        variant="outline"
                        onClick={() => {
                            setTicked(new Set(initial));
                        }}
                    >
                        Tick the usual rows
                    </Button>
                    <Button className="max-sm:w-full"
                        type="button"
                        variant="outline"
                        onClick={() => setTicked(new Set())}
                    >
                        Untick all
                    </Button>
                </div>
            </div>

            <DataTable
                columns={columns}
                rows={rows}
                rowKey={(row) => row.number}
                caption="Rows of the file"
                empty={<EmptyState message="No rows to show." />}
                rowClassName={(row) =>
                    row.status === 'error'
                        ? 'bg-bad-soft'
                        : row.status === 'warning'
                          ? 'bg-warn-soft'
                          : undefined
                }
            />

            <div className="flex flex-wrap items-center gap-3 border-t p-4">
                <Button className="max-sm:w-full"
                    type="button"
                    disabled={count === 0 || processing}
                    onClick={() => setConfirming(true)}
                >
                    Import {plural(count, 'visit', 'visits')}
                </Button>
                <Button className="max-sm:w-full"
                    type="button"
                    variant="outline"
                    disabled={processing}
                    onClick={cancel}
                >
                    Cancel
                </Button>
                <span className="text-sm text-muted-foreground">
                    The uploaded file is kept for one hour, then deleted.
                </span>
            </div>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={`Import ${plural(count, 'visit', 'visits')}?`}
                description={`${plural(preview.summary.total - count, 'row', 'rows')} of the file will be left out. All the chosen rows are saved together, or none. You can undo the whole import afterwards from Past imports.`}
                confirmLabel="Import"
                onConfirm={confirm}
                processing={processing}
            />
        </section>
    );
}
