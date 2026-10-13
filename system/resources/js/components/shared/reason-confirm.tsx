import { useId, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import {
    checkDeleteReason,
    DeleteReasonField,
    deleteReasonPayload,
    emptyDeleteReason,
} from '@/components/shared/delete-reason-field';
import type {
    DeleteReasonErrors,
    DeleteReasonOption,
    DeleteReasonValue,
} from '@/components/shared/delete-reason-field';
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
import { Spinner } from '@/components/ui/spinner';

/** Errors the server may send back: the reason, or a refusal. */
export type ReasonConfirmErrors = DeleteReasonErrors & { item?: string };

type ReasonFormProps = {
    /** The reasons for this record type (DeleteReason::options()). */
    reasons: DeleteReasonOption[];
    /** Called with a reason that passed the browser check. */
    onConfirm: (value: DeleteReasonValue) => void;
    title?: string;
    /** Which item this is about, e.g. "V-000123 · Santos, Juan M. · ER". */
    description?: ReactNode;
    confirmLabel?: string;
    reasonLabel?: string;
    /** True while the request is running: buttons are disabled. */
    processing?: boolean;
    /** Errors from the server, e.g. the onError value of router.delete. */
    errors?: ReasonConfirmErrors;
};

type ReasonConfirmProps = ReasonFormProps & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Confirm dialog that requires a reason picked from a list, e.g.
 * "Move to Trash?". "Other (specify)" asks for the reason in words.
 */
export function ReasonConfirm({
    open,
    onOpenChange,
    description,
    ...formProps
}: ReasonConfirmProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                {...(description ? {} : { 'aria-describedby': undefined })}
            >
                {/* Mounted only while open, so the reason starts empty each time. */}
                <ReasonForm
                    {...formProps}
                    description={description}
                    onCancel={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

function ReasonForm({
    reasons,
    onConfirm,
    onCancel,
    title = 'Move to Trash?',
    description,
    confirmLabel = 'Move to Trash',
    reasonLabel = 'Reason',
    processing = false,
    errors: serverErrors = {},
}: ReasonFormProps & { onCancel: () => void }) {
    const id = useId();
    const [value, setValue] = useState<DeleteReasonValue>(emptyDeleteReason);
    const [errors, setErrors] = useState<ReasonConfirmErrors | null>(null);
    const shown = errors ?? serverErrors;
    const message = shown.reason ?? shown.reason_other ?? shown.item;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (processing) {
            return;
        }

        const found = checkDeleteReason(value, reasons);

        if (Object.keys(found).length > 0) {
            setErrors(found);

            return;
        }

        setErrors(null);
        onConfirm(deleteReasonPayload(value, reasons));
    }

    return (
        <form onSubmit={submit} noValidate className="grid gap-4">
            <DialogHeader>
                <DialogTitle>{title}</DialogTitle>
                {description && (
                    <DialogDescription>{description}</DialogDescription>
                )}
            </DialogHeader>

            <div className="grid gap-1.5">
                <Label htmlFor={id}>
                    {reasonLabel} <span className="text-bad">(required)</span>
                </Label>
                <DeleteReasonField
                    id={id}
                    options={reasons}
                    value={value}
                    onChange={(next) => {
                        setValue(next);
                        setErrors({});
                    }}
                    errors={shown}
                    errorId={`${id}-error`}
                />
                {message && (
                    <p id={`${id}-error`} className="text-sm text-bad">
                        {message}
                    </p>
                )}
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onCancel}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    variant="destructive"
                    disabled={processing}
                >
                    {processing && <Spinner />}
                    {confirmLabel}
                </Button>
            </DialogFooter>
        </form>
    );
}
