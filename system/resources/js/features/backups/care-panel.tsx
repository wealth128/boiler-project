import { router } from '@inertiajs/react';
import { useState } from 'react';
import BackupController from '@/actions/App/Http/Controllers/Backup/BackupController';
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
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import type { TestRestoreInfo, UsbDrive } from '@/features/backups/types';
import { formatDateTime } from '@/lib/dates';
import { cn } from '@/lib/utils';

type CarePanelProps = {
    testRestore: TestRestoreInfo | null;
    /** Only present after the Copy to USB window asked for it. */
    drives?: UsbDrive[];
    /** Disable both buttons when there is no backup to work with. */
    hasBackup: boolean;
};

/**
 * "Backup care": the two jobs that used to need IT. Run test restore now
 * loads the latest backup into a scratch database and compares the counts.
 * Copy to USB copies the latest backup to a USB drive plugged into the
 * SERVER PC. Both wait for a confirm step and run one at a time.
 */
export function CarePanel({ testRestore, drives, hasBackup }: CarePanelProps) {
    const [testing, setTesting] = useState(false);
    const [asking, setAsking] = useState(false);
    const [usbOpen, setUsbOpen] = useState(false);
    const [loadingDrives, setLoadingDrives] = useState(false);
    const [copying, setCopying] = useState(false);
    const [picked, setPicked] = useState('');

    function runTest() {
        router.post(
            BackupController.testRestore.url(),
            {},
            {
                preserveScroll: true,
                onStart: () => setTesting(true),
                onFinish: () => {
                    setTesting(false);
                    setAsking(false);
                },
            },
        );
    }

    function openUsb() {
        setPicked('');
        setUsbOpen(true);
        router.reload({
            only: ['drives'],
            onStart: () => setLoadingDrives(true),
            onFinish: () => setLoadingDrives(false),
        });
    }

    function refreshDrives() {
        router.reload({
            only: ['drives'],
            onStart: () => setLoadingDrives(true),
            onFinish: () => setLoadingDrives(false),
        });
    }

    function copy() {
        router.post(
            BackupController.usbCopy.url(),
            { drive: picked || drives?.[0]?.letter },
            {
                preserveScroll: true,
                onStart: () => setCopying(true),
                onFinish: () => {
                    setCopying(false);
                    setUsbOpen(false);
                },
            },
        );
    }

    const choice = picked || drives?.[0]?.letter || '';

    return (
        <section
            aria-labelledby="backup-care"
            className="mb-4 rounded-lg border bg-card"
        >
            <div className="border-b px-4 py-3">
                <h2 id="backup-care" className="text-base font-semibold">
                    Backup care
                </h2>
            </div>

            <div className="grid gap-4 p-4 text-sm sm:grid-cols-2">
                <div>
                    <h3 className="font-medium">Test restore</h3>
                    <p
                        className={cn(
                            'mt-1',
                            testRestore?.result === 'failed' && 'text-bad',
                            testRestore === null && 'text-muted-foreground',
                        )}
                    >
                        {testRestore === null
                            ? 'Never run yet.'
                            : testRestore.result === 'success'
                              ? `Passed on ${formatDateTime(testRestore.at)} (${testRestore.trigger === 'automatic' ? 'automatic' : 'run by Admin'}).`
                              : `Failed on ${formatDateTime(testRestore.at)}: ${testRestore.error ?? 'unknown error'}`}
                    </p>
                    <p className="mt-1 text-muted-foreground">
                        It runs by itself on the 1st of every month at 03:00. It
                        loads the latest backup into a scratch database and
                        compares the record counts. Your live data is not
                        touched.
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        className="mt-2"
                        disabled={!hasBackup}
                        onClick={() => setAsking(true)}
                    >
                        Run test restore now
                    </Button>
                </div>

                <div>
                    <h3 className="font-medium">Copy to USB</h3>
                    <p className="mt-1 text-muted-foreground">
                        Plug the USB drive into the{' '}
                        <strong className="font-semibold text-foreground">
                            server PC
                        </strong>
                        , not into your office PC. The server copies the
                        latest backup (the database file and the pictures
                        zip) to a folder named census-backups on the drive,
                        and keeps the newest 8 backups there. Nothing is
                        downloaded to this computer.
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        className="mt-2"
                        disabled={!hasBackup}
                        onClick={openUsb}
                    >
                        Copy to USB
                    </Button>
                </div>
            </div>

            <ConfirmDialog
                open={asking}
                onOpenChange={setAsking}
                title="Run a test restore now?"
                description="The latest backup is loaded into a scratch database and checked. Your live data is not touched. It may take a few minutes, and no backup can run meanwhile."
                confirmLabel="Run test restore now"
                processing={testing}
                onConfirm={runTest}
            />

            <Dialog
                open={usbOpen}
                onOpenChange={(open) => !copying && setUsbOpen(open)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Copy the latest backup to USB?</DialogTitle>
                        <DialogDescription>
                            The USB drive must be plugged into the server PC,
                            not your office PC. Only removable drives are
                            listed.
                        </DialogDescription>
                    </DialogHeader>

                    {loadingDrives && drives === undefined ? (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Spinner /> Looking for USB drives…
                        </p>
                    ) : drives && drives.length > 0 ? (
                        <div className="grid gap-1.5">
                            <Label htmlFor="usb-drive">USB drive</Label>
                            <NativeSelect
                                id="usb-drive"
                                value={choice}
                                disabled={copying}
                                onChange={(event) =>
                                    setPicked(event.target.value)
                                }
                            >
                                {drives.map((drive) => (
                                    <option
                                        key={drive.letter}
                                        value={drive.letter}
                                    >
                                        {drive.letter}{' '}
                                        {drive.label || '(no name)'} ·{' '}
                                        {formatGb(drive.free_mb)} free of{' '}
                                        {formatGb(drive.size_mb)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    ) : (
                        <p
                            role="status"
                            className="rounded-md border border-warn bg-warn-soft px-3 py-2 text-sm"
                        >
                            No USB drive found. Plug the USB drive into the
                            server PC, wait a few seconds, then click “Look
                            again”.
                        </p>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={copying}
                            onClick={() => setUsbOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={copying || loadingDrives}
                            onClick={refreshDrives}
                        >
                            Look again
                        </Button>
                        <Button
                            type="button"
                            disabled={copying || choice === ''}
                            onClick={copy}
                        >
                            {copying && <Spinner />}
                            Copy to USB
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

/** "14.9 GB" from megabytes. */
function formatGb(megabytes: number): string {
    return `${(megabytes / 1024).toFixed(1)} GB`;
}
