import { router } from '@inertiajs/react';
import { useState } from 'react';
import ImportController from '@/actions/App/Http/Controllers/Records/ImportController';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable } from '@/components/shared/data-table';
import type { DataTableColumn } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { Pagination } from '@/components/shared/pagination';
import { Button } from '@/components/ui/button';
import type { ImportBatchRow } from '@/features/import/types';
import { formatDateTime } from '@/lib/dates';
import { formatCount } from '@/lib/format';
import { DEFAULT_PER_PAGE, pagingQuery, showsPaging } from '@/lib/paging';
import type { Paged } from '@/types';

type PastImportsProps = {
    batches: Paged<ImportBatchRow>;
    /** Keeps the waiting file's preview open while paging. */
    token: string | null;
};

function pageUrl(
    token: string | null,
    page = 1,
    perPage = DEFAULT_PER_PAGE,
): string {
    return ImportController.index.url({
        query: {
            file: token ?? undefined,
            ...pagingQuery(perPage, page),
        },
    });
}

/**
 * "Past imports": every file that was imported, 10 to a page, with Undo
 * for a whole import (everything goes to Trash with the reason "Wrong
 * import"; refused while one of its visits is in a closed month).
 */
export function PastImports({ batches, token }: PastImportsProps) {
    const [undoing, setUndoing] = useState<ImportBatchRow | null>(null);
    const [processing, setProcessing] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    function undo() {
        if (!undoing) {
            return;
        }

        router.post(
            ImportController.undo.url(undoing.id),
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setProblem(null);
                },
                onFinish: () => setProcessing(false),
                onSuccess: () => setUndoing(null),
                onError: (errors) => {
                    setUndoing(null);
                    setProblem(
                        errors.import ?? 'The import could not be undone.',
                    );
                },
            },
        );
    }

    const columns: DataTableColumn<ImportBatchRow>[] = [
        {
            key: 'imported_at',
            header: 'Imported',
            cell: (batch) => formatDateTime(batch.imported_at),
            className: 'font-mono whitespace-nowrap',
        },
        { key: 'file', header: 'File', cell: (batch) => batch.file_name },
        { key: 'by', header: 'By', cell: (batch) => batch.imported_by },
        {
            key: 'rows',
            header: 'Rows in file',
            cell: (batch) => formatCount(batch.original_rows),
            align: 'right',
        },
        {
            key: 'imported',
            header: 'Imported',
            cell: (batch) => formatCount(batch.imported_count),
            align: 'right',
        },
        {
            key: 'skipped',
            header: 'Skipped',
            cell: (batch) => formatCount(batch.skipped_count),
            align: 'right',
        },
        {
            key: 'actions',
            header: <span className="sr-only">Undo</span>,
            cell: (batch) => {
                if (batch.undone_at) {
                    return (
                        <span className="text-xs text-muted-foreground">
                            Undone {formatDateTime(batch.undone_at)} by{' '}
                            {batch.undone_by}
                        </span>
                    );
                }

                if (batch.blocked_month) {
                    return (
                        <span className="text-xs text-muted-foreground">
                            {batch.blocked_month} is closed
                        </span>
                    );
                }

                return (
                    <Button
                        variant="link"
                        size="sm"
                        className="h-auto px-1.5 py-0.5 text-xs text-bad"
                        onClick={() => {
                            setProblem(null);
                            setUndoing(batch);
                        }}
                        aria-label={`Undo import of ${batch.file_name}`}
                    >
                        Undo import
                    </Button>
                );
            },
        },
    ];

    return (
        <section className="mt-4 rounded-lg border bg-card">
            <div className="border-b p-4">
                <h2 className="text-base font-semibold">Past imports</h2>
                <p className="text-sm text-muted-foreground">
                    Undo moves the whole import to Trash. Visits can be restored
                    from Trash one by one.
                </p>
                {problem && (
                    <p
                        role="alert"
                        className="mt-2 rounded-md border border-bad bg-bad-soft px-3 py-2 text-sm text-bad"
                    >
                        {problem}
                    </p>
                )}
            </div>

            <DataTable
                columns={columns}
                rows={batches.data}
                rowKey={(batch) => batch.id}
                caption="Past imports"
                empty={<EmptyState message="Nothing has been imported yet." />}
                footer={
                    showsPaging(batches.meta) ? (
                        <Pagination
                            meta={batches.meta}
                            pageUrl={(page) =>
                                pageUrl(token, page, batches.meta.per_page)
                            }
                            perPageUrl={(size) => pageUrl(token, 1, size)}
                            noun="imports"
                        />
                    ) : undefined
                }
            />

            <ConfirmDialog
                open={undoing !== null}
                onOpenChange={(open) => !open && setUndoing(null)}
                title="Undo this import?"
                description={`${undoing?.file_name ?? ''}: its ${undoing ? formatCount(undoing.imported_count) : ''} visits go to Trash with the reason "Wrong import". Patients created only by this import go to Trash too, unless they have other visits.`}
                confirmLabel="Undo import"
                onConfirm={undo}
                processing={processing}
            />
        </section>
    );
}
