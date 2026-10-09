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

type TypeToConfirmFormProps = {
    /**
     * The exact text the user must type, e.g. the item's ID "V-000123", or
     * "EMPTY TRASH". Case and spacing must match.
     */
    confirmText: string;
    /** Called only after the text matches. */
    onConfirm: () => void;
    title?: string;
    /** Which item this is about and what will happen. */
    description?: ReactNode;
    confirmLabel?: string;
    /** True while the request is running: buttons are disabled. */
    processing?: boolean;
    /** Error from the server, e.g. "Its month is closed. Reopen it first." */
    error?: string;
};

type TypeToConfirmProps = TypeToConfirmFormProps & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Confirm dialog for actions that can't be undone, such as deleting
 * permanently from Trash: the user must type the item's ID first.
 */
export function TypeToConfirm({
    open,
    onOpenChange,
    ...formProps
}: TypeToConfirmProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                {/* Mounted only while open, so the input starts empty each time. */}
                <TypeToConfirmForm
                    {...formProps}
                    onCancel={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

function TypeToConfirmForm({
    confirmText,
    onConfirm,
    onCancel,
    title = 'Delete permanently?',
    description,
    confirmLabel = 'Delete permanently',
    processing = false,
    error,
}: TypeToConfirmFormProps & { onCancel: () => void }) {
    const id = useId();
    const [typed, setTyped] = useState('');
    const [mismatch, setMismatch] = useState(false);
    const message = mismatch
        ? `Type ${confirmText} exactly to confirm.`
        : error;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (typed.trim() !== confirmText) {
            setMismatch(true);

            return;
        }

        onConfirm();
    }

    return (
        <form onSubmit={submit} noValidate className="grid gap-4">
            <DialogHeader>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>
                    {description && <>{description} </>}
                    This can&apos;t be undone.
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-1.5">
                <Label htmlFor={id}>
                    Type{' '}
                    <span className="rounded bg-muted px-1.5 py-0.5 font-mono">
                        {confirmText}
                    </span>{' '}
                    to confirm
                </Label>
                <Input
                    id={id}
                    value={typed}
                    onChange={(event) => {
                        setTyped(event.target.value);
                        setMismatch(false);
                    }}
                    className="font-mono"
                    autoComplete="off"
                    autoCapitalize="off"
                    spellCheck={false}
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
