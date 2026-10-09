import { useId, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

/** Same limit as the delete_reason column. */
const REASON_MAX_LENGTH = 255;

type ReasonFormProps = {
    /** Called with the trimmed reason. Never called with an empty one. */
    onConfirm: (reason: string) => void;
    title?: string;
    /** Which item this is about, e.g. "V-000123 · Santos, Juan M. · ER". */
    description?: ReactNode;
    confirmLabel?: string;
    reasonLabel?: string;
    placeholder?: string;
    /** True while the request is running: buttons are disabled. */
    processing?: boolean;
    /** Error from the server, e.g. form.errors.reason. */
    error?: string;
};

type ReasonConfirmProps = ReasonFormProps & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Confirm dialog that requires a reason, e.g. "Move to Trash?".
 * The defaults are worded for moving an item to Trash; pass other labels
 * for other actions that need a reason.
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
    onConfirm,
    onCancel,
    title = 'Move to Trash?',
    description,
    confirmLabel = 'Move to Trash',
    reasonLabel = 'Reason',
    placeholder = 'e.g. Duplicate entry, encoded by mistake',
    processing = false,
    error,
}: ReasonFormProps & { onCancel: () => void }) {
    const id = useId();
    const [reason, setReason] = useState('');
    const [missing, setMissing] = useState(false);
    const message = missing ? 'Enter a reason.' : error;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const trimmed = reason.trim();

        if (trimmed === '') {
            setMissing(true);

            return;
        }

        onConfirm(trimmed);
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
                <Input
                    id={id}
                    value={reason}
                    onChange={(event) => {
                        setReason(event.target.value);
                        setMissing(false);
                    }}
                    maxLength={REASON_MAX_LENGTH}
                    placeholder={placeholder}
                    autoComplete="off"
                    aria-invalid={message ? true : undefined}
                    aria-describedby={message ? `${id}-error` : undefined}
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
