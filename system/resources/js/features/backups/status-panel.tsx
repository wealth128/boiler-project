import { router } from '@inertiajs/react';
import { useState } from 'react';
import BackupController from '@/actions/App/Http/Controllers/Backup/BackupController';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/features/backups/confirm-dialog';
import type {
    BackupMarkInfo,
    BackupStatusInfo,
} from '@/features/backups/types';
import { formatDate, formatDateTime } from '@/lib/dates';
import { cn } from '@/lib/utils';

type StatusPanelProps = {
    status: BackupStatusInfo;
    marks: BackupMarkInfo[];
};

/**
 * The top of the Backups screen: the red warning if there is one, the last
 * good backup, the schedule and folder, the "copy to USB" reminder, and the
 * two "mark as done" buttons.
 */
export function StatusPanel({ status, marks }: StatusPanelProps) {
    const [asking, setAsking] = useState<BackupMarkInfo | null>(null);
    const [processing, setProcessing] = useState(false);
    const last = status.last_success;

    function mark(item: BackupMarkInfo) {
        router.post(
            BackupController.mark.url({ type: item.type }),
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setAsking(null);
                },
            },
        );
    }

    return (
        <section
            aria-labelledby="backup-status"
            className="mb-4 rounded-lg border bg-card"
        >
            <div className="border-b px-4 py-3">
                <h2 id="backup-status" className="text-base font-semibold">
                    Status
                </h2>
            </div>

            {status.problem && (
                <p
                    role="alert"
                    className="m-4 rounded-md border border-bad bg-bad-soft px-3 py-2 text-sm font-medium text-bad"
                >
                    {status.problem}
                </p>
            )}

            <dl className="grid gap-x-6 gap-y-3 p-4 text-sm sm:grid-cols-2">
                <Item label="Last successful backup">
                    {last ? (
                        <>
                            {formatDateTime(last.finished_at)}
                            <span className="block font-mono text-xs break-all text-muted-foreground">
                                {last.file_name}
                                {last.size_mb !== null &&
                                    ` · ${last.size_mb.toFixed(2)} MB`}
                            </span>
                        </>
                    ) : (
                        'None yet'
                    )}
                </Item>
                <Item label="Next scheduled run">
                    {formatDateTime(status.next_run)} (daily at 02:00)
                </Item>
                <Item label="Backups kept">{status.keep_days} days</Item>
                <Item label="Backup folder">
                    <span className="font-mono break-all">{status.folder}</span>
                </Item>
                {marks.map((item) => (
                    <Item key={item.type} label={item.label}>
                        <span
                            className={cn(item.overdue && 'text-warn')}
                        >
                            Last done{' '}
                            {item.last_at
                                ? `${formatDate(item.last_at)} by ${item.by ?? 'unknown'}`
                                : 'never'}
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="mt-1.5 block"
                            onClick={() => setAsking(item)}
                        >
                            Mark {noun(item)} done
                        </Button>
                    </Item>
                ))}
            </dl>

            <p className="border-t px-4 py-3 text-sm text-muted-foreground">
                Backup files hold all patient data, so they can&apos;t be
                downloaded or restored here. IT copies them from the backup
                folder. Copy the latest backup to the USB drive every week.
                Test a restore once a month.
            </p>

            <ConfirmDialog
                open={asking !== null}
                onOpenChange={(open) => !open && setAsking(null)}
                title={`Mark ${asking ? noun(asking) : ''} as done?`}
                description={
                    asking?.type === 'usb-copy'
                        ? 'Confirm the latest backup was copied to the USB drive today.'
                        : 'Confirm a backup was restored on a spare PC and the records matched.'
                }
                confirmLabel={`Mark ${asking ? noun(asking) : ''} done`}
                processing={processing}
                onConfirm={() => asking && mark(asking)}
            />
        </section>
    );
}

/** "USB copy" or "test restore", for the button text. */
function noun(item: BackupMarkInfo): string {
    return item.type === 'usb-copy' ? 'USB copy' : 'test restore';
}

function Item({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="mt-0.5">{children}</dd>
        </div>
    );
}
