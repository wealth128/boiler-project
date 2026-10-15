import { useId, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
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
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

/** Errors the server may send back for a move to Trash. */
export type MoveToTrashErrors = DeleteReasonErrors & {
    /** A refusal, e.g. "Move this patient's visits to Trash first." */
    item?: string;
};

type MoveToTrashRowProps = {
    /** The reasons for this record type (DeleteReason::options()). */
    reasons: DeleteReasonOption[];
    /** Called with a reason that passed the browser check. */
    onConfirm: (value: DeleteReasonValue) => void;
    onCancel: () => void;
    /** True while the request is running: buttons are disabled. */
    processing?: boolean;
    errors?: MoveToTrashErrors;
    /** Replaces the default spacing, which fits a table cell. */
    className?: string;
};

/**
 * The inline "Move to Trash? Reason (required)" row under a table row or
 * list item (prototype/prototype.html, delRow), with the reason picked from
 * a list. Enter confirms, Esc cancels. Used by Patient Records and
 * Dropdown Lists.
 */
export function MoveToTrashRow({
    reasons,
    onConfirm,
    onCancel,
    processing = false,
    errors: serverErrors = {},
    className = '-mx-2.5 -my-2',
}: MoveToTrashRowProps) {
    const id = useId();
    const [value, setValue] = useState<DeleteReasonValue>(emptyDeleteReason);
    const [errors, setErrors] = useState<MoveToTrashErrors | null>(null);
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

    /** Enter confirms from the list or the text box; Esc cancels. */
    function onKeyDown(event: KeyboardEvent<HTMLFormElement>) {
        if (event.key === 'Escape' && !processing) {
            event.preventDefault();
            onCancel();

            return;
        }

        if (
            event.key === 'Enter' &&
            !event.nativeEvent.isComposing &&
            event.target instanceof HTMLSelectElement
        ) {
            event.preventDefault();
            event.currentTarget.requestSubmit();
        }
    }

    return (
        <form
            onSubmit={submit}
            onKeyDown={onKeyDown}
            noValidate
            className={cn(
                'flex flex-wrap items-center gap-2.5 bg-bad-soft px-2.5 py-2',
                className,
            )}
        >
            <label htmlFor={id} className="text-sm">
                <b>Move to Trash?</b> Reason (required):
            </label>
            <div className="flex flex-wrap items-center gap-2">
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
                    autoFocus
                    controlClassName="h-8 w-auto max-w-80"
                />
            </div>
            <Button
                type="submit"
                variant="destructive"
                size="sm"
                disabled={processing}
            >
                {processing && <Spinner />}
                Move to Trash
            </Button>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={onCancel}
                disabled={processing}
            >
                Cancel
            </Button>
            {message && (
                <span
                    id={`${id}-error`}
                    role="alert"
                    className="text-sm font-medium text-bad"
                >
                    {message}
                </span>
            )}
        </form>
    );
}
