import { router } from '@inertiajs/react';
import { useState } from 'react';
import MonthController from '@/actions/App/Http/Controllers/Reports/MonthController';
import { Chip } from '@/components/shared/chip';
import type { DeleteReasonOption } from '@/components/shared/delete-reason-field';
import { MoveToTrashRow } from '@/components/shared/move-to-trash-row';
import type { MoveToTrashErrors } from '@/components/shared/move-to-trash-row';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { MonthStatus } from '@/features/reports/types';

type MonthBarProps = {
    status: MonthStatus;
    /** "September 2026" */
    monthLabel: string;
    reasons: DeleteReasonOption[];
};

type Errors = MoveToTrashErrors & { period?: string };

/**
 * The bar under the Reports controls for a monthly report (prototype,
 * reportsView): the month's status for everyone, and for Admin the
 * Close / Reopen and Delete month report buttons. Close and Reopen ask
 * first. Refusals from the server show here, not on a 403 page.
 */
export function MonthBar({ status, monthLabel, reasons }: MonthBarProps) {
    const [confirming, setConfirming] = useState<'close' | 'reopen' | null>(
        null,
    );
    const [deleting, setDeleting] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});

    const run = (
        method: 'post' | 'delete',
        url: string,
        data: Record<string, string> = {},
    ) => {
        setProcessing(true);
        setErrors({});

        router.visit(url, {
            method,
            data,
            preserveScroll: true,
            onSuccess: () => {
                setConfirming(null);
                setDeleting(false);
            },
            onError: (found) => {
                setErrors(found);

                // A refusal ends the question; a bad reason keeps it open.
                if (found.period) {
                    setConfirming(null);
                }
            },
            onFinish: () => setProcessing(false),
        });
    };

    const target = { period: status.period };

    return (
        <div className="border-t">
            <div className="flex flex-wrap items-center justify-end gap-2 px-4 py-2.5">
                {status.closed ? (
                    <Chip>Month closed · locked for editing</Chip>
                ) : (
                    <Chip tone="ok">Month open</Chip>
                )}

                {status.can_manage && (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                            onClick={() => {
                                setDeleting(false);
                                setErrors({});
                                setConfirming(
                                    status.closed ? 'reopen' : 'close',
                                );
                            }}
                        >
                            {status.closed ? 'Reopen month' : 'Close month'}
                        </Button>
                        {status.closed ? (
                            <span className="text-xs text-muted-foreground">
                                Reopen to delete this month
                            </span>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="text-bad"
                                disabled={processing}
                                onClick={() => {
                                    setErrors({});
                                    setDeleting(true);
                                }}
                            >
                                Delete month report
                            </Button>
                        )}
                    </>
                )}
            </div>

            {errors.period && !deleting && (
                <p
                    role="alert"
                    className="px-4 pb-2.5 text-right text-sm font-medium text-bad"
                >
                    {errors.period}
                </p>
            )}

            {status.can_manage && deleting && !status.closed && (
                <MoveToTrashRow
                    reasons={reasons}
                    processing={processing}
                    errors={{ ...errors, item: errors.period ?? errors.item }}
                    className="border-t px-4 py-3"
                    prompt={
                        <b>
                            Move all {status.visit_count} visits of {monthLabel}{' '}
                            to Trash?
                        </b>
                    }
                    hint="The whole month can be restored from Trash in one click."
                    onCancel={() => {
                        setDeleting(false);
                        setErrors({});
                    }}
                    onConfirm={(value) =>
                        run('delete', MonthController.destroy.url(target), value)
                    }
                />
            )}

            <Dialog
                open={confirming !== null}
                onOpenChange={(open) =>
                    !open && !processing && setConfirming(null)
                }
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {confirming === 'reopen'
                                ? `Reopen ${monthLabel}?`
                                : `Close ${monthLabel}?`}
                        </DialogTitle>
                        <DialogDescription>
                            {confirming === 'reopen'
                                ? 'The saved copy is removed and the report is counted live again.'
                                : 'The report is saved as it is now, and visits in this month can’t be added, edited or deleted until it is reopened.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={processing}
                            onClick={() => setConfirming(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            autoFocus
                            disabled={processing}
                            onClick={() =>
                                run(
                                    'post',
                                    (confirming === 'reopen'
                                        ? MonthController.reopen
                                        : MonthController.close
                                    ).url(target),
                                )
                            }
                        >
                            {processing && <Spinner />}
                            {confirming === 'reopen'
                                ? 'Reopen month'
                                : 'Close month'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
