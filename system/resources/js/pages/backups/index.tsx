import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import BackupController from '@/actions/App/Http/Controllers/Backup/BackupController';
import { DataTable } from '@/components/shared/data-table';
import type { DataTableColumn } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/features/backups/confirm-dialog';
import { StatusPanel } from '@/features/backups/status-panel';
import type {
    BackupMarkInfo,
    BackupRunRow,
    BackupRuns,
    BackupStatusInfo,
} from '@/features/backups/types';
import { formatDateTime } from '@/lib/dates';
import { formatCount } from '@/lib/format';
import { DEFAULT_PER_PAGE, pagingQuery } from '@/lib/paging';
import { cn } from '@/lib/utils';

type Props = {
    status: BackupStatusInfo;
    marks: BackupMarkInfo[];
    runs: BackupRuns;
};

function backupsUrl(page = 1, perPage = DEFAULT_PER_PAGE): string {
    return BackupController.index.url({
        query: pagingQuery(perPage, page),
    });
}

/**
 * Backups (Admin only). Status and reminders on top, then every backup run,
 * newest first. "Run backup now" asks first and runs one backup. There is no
 * download or restore here: the files stay in the backup folder.
 */
export default function BackupsIndex({ status, marks, runs }: Props) {
    const [asking, setAsking] = useState(false);
    const [running, setRunning] = useState(false);

    function runNow() {
        router.post(
            BackupController.run.url(),
            {},
            {
                preserveScroll: true,
                onStart: () => setRunning(true),
                onFinish: () => {
                    setRunning(false);
                    setAsking(false);
                },
            },
        );
    }

    const columns: DataTableColumn<BackupRunRow>[] = [
        {
            key: 'started',
            header: 'Started',
            cell: (run) => formatDateTime(run.started_at),
            className: 'font-mono whitespace-nowrap',
        },
        {
            key: 'finished',
            header: 'Finished',
            cell: (run) => formatDateTime(run.finished_at),
            className: 'font-mono whitespace-nowrap',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (run) => (
                <span
                    className={cn(
                        'font-medium',
                        run.status === 'success' ? 'text-ok' : 'text-bad',
                    )}
                >
                    {run.status === 'success' ? 'Success' : 'Failed'}
                </span>
            ),
        },
        {
            key: 'file',
            header: 'Files',
            cell: (run) =>
                run.file_name === null ? (
                    '—'
                ) : (
                    <>
                        <span className="block">{run.file_name}</span>
                        <span className="block text-muted-foreground">
                            {run.zip_name ?? 'no picture zip'}
                        </span>
                    </>
                ),
            className: 'font-mono text-xs break-all',
        },
        {
            key: 'size',
            header: 'Size',
            align: 'right',
            cell: (run) =>
                run.size_mb === null ? (
                    '—'
                ) : (
                    <>
                        <span className="block">
                            {run.size_mb.toFixed(2)} MB
                        </span>
                        <span className="block text-muted-foreground">
                            {run.zip_size_mb === null
                                ? '—'
                                : `${run.zip_size_mb.toFixed(2)} MB`}
                        </span>
                    </>
                ),
            className: 'whitespace-nowrap',
        },
        {
            key: 'error',
            header: 'Error',
            // Plain text: React escapes it.
            cell: (run) => run.error ?? '',
            className: 'max-w-md break-words text-bad',
        },
    ];

    return (
        <>
            <Head title="Backups" />

            <PageHeader
                title="Backups"
                description="The database is backed up every day. Admin only."
                actions={
                    <Button type="button" onClick={() => setAsking(true)}>
                        Run backup now
                    </Button>
                }
            />

            <StatusPanel status={status} marks={marks} />

            <section className="rounded-lg border bg-card">
                <div className="border-b px-4 py-3">
                    <h2 className="text-base font-semibold">
                        Backup history ({formatCount(runs.meta.total)})
                    </h2>
                </div>
                <DataTable
                    columns={columns}
                    rows={runs.data}
                    rowKey={(run) => run.id}
                    caption="Backup runs, newest first"
                    empty={<EmptyState message="No backups have run yet." />}
                    footer={
                        runs.meta.total > 0 ? (
                            <Pagination
                                meta={runs.meta}
                                pageUrl={(page) =>
                                    backupsUrl(page, runs.meta.per_page)
                                }
                                perPageUrl={(size) => backupsUrl(1, size)}
                                noun="backups"
                                alwaysShow
                            />
                        ) : undefined
                    }
                />
            </section>

            <ConfirmDialog
                open={asking}
                onOpenChange={setAsking}
                title="Run a backup now?"
                description="Run a backup now? It may take a minute."
                confirmLabel="Run backup now"
                processing={running}
                onConfirm={runNow}
            />
        </>
    );
}
