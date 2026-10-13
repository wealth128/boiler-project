import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { cn } from '@/lib/utils';

/**
 * The "Reason" dropdown used whenever something moves to Trash. The
 * choices come from the server (App\Enums\DeleteReason: one list per
 * record type) and the server accepts only those. "Other (specify)" shows
 * a text box that needs 10 to 200 characters; it is saved as
 * "Other: <text>".
 */

/** One choice, as sent by DeleteReason::options(). */
export type DeleteReasonOption = {
    value: string;
    /** The "Other (specify)" choice. */
    other: boolean;
};

/** What the form sends: `reason` and, for Other, `reason_other`. */
export type DeleteReasonValue = {
    reason: string;
    reason_other: string;
};

export type DeleteReasonErrors = Partial<
    Record<'reason' | 'reason_other', string>
>;

/** Same limits as DeleteReason::OTHER_MIN / OTHER_MAX on the server. */
export const OTHER_REASON_MIN = 10;
export const OTHER_REASON_MAX = 200;

/** Same messages as TrashService. */
export const REASON_MESSAGES = {
    notInList: 'Choose a reason from the list.',
    otherLength: 'Describe the reason in 10 to 200 characters.',
} as const;

export const emptyDeleteReason: DeleteReasonValue = {
    reason: '',
    reason_other: '',
};

export function isOtherReason(
    value: DeleteReasonValue,
    options: DeleteReasonOption[],
): boolean {
    return options.some((o) => o.other && o.value === value.reason);
}

/**
 * The browser-side check (the server checks again). Returns no keys when
 * the value is fine.
 */
export function checkDeleteReason(
    value: DeleteReasonValue,
    options: DeleteReasonOption[],
): DeleteReasonErrors {
    if (!options.some((o) => o.value === value.reason)) {
        return { reason: REASON_MESSAGES.notInList };
    }

    if (isOtherReason(value, options)) {
        const length = value.reason_other.trim().replace(/\s+/g, ' ').length;

        if (length < OTHER_REASON_MIN || length > OTHER_REASON_MAX) {
            return { reason_other: REASON_MESSAGES.otherLength };
        }
    }

    return {};
}

/** The value to send: the typed text only goes with "Other (specify)". */
export function deleteReasonPayload(
    value: DeleteReasonValue,
    options: DeleteReasonOption[],
): DeleteReasonValue {
    return {
        reason: value.reason,
        reason_other: isOtherReason(value, options)
            ? value.reason_other.trim()
            : '',
    };
}

type DeleteReasonFieldProps = {
    /** Base id: the list gets `id`, the text box `${id}-other`. */
    id: string;
    options: DeleteReasonOption[];
    value: DeleteReasonValue;
    onChange: (value: DeleteReasonValue) => void;
    errors?: DeleteReasonErrors;
    /** id of the element that shows the error, for screen readers. */
    errorId?: string;
    autoFocus?: boolean;
    /** Extra classes for the list and the text box, e.g. a smaller height. */
    controlClassName?: string;
};

/**
 * The reason list and, for "Other (specify)", the text box. Labels and the
 * error text are placed by the caller.
 */
export function DeleteReasonField({
    id,
    options,
    value,
    onChange,
    errors = {},
    errorId,
    autoFocus,
    controlClassName,
}: DeleteReasonFieldProps) {
    const other = isOtherReason(value, options);

    return (
        <>
            <NativeSelect
                id={id}
                value={value.reason}
                onChange={(event) => {
                    const next = { ...value, reason: event.target.value };

                    onChange(next);

                    // "Other (specify)": continue in the text box.
                    if (isOtherReason(next, options)) {
                        window.setTimeout(() =>
                            document.getElementById(`${id}-other`)?.focus(),
                        );
                    }
                }}
                autoFocus={autoFocus}
                className={cn('bg-background', controlClassName)}
                aria-invalid={errors.reason ? true : undefined}
                aria-describedby={errors.reason ? errorId : undefined}
            >
                <option value="">Choose a reason</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.value}
                    </option>
                ))}
            </NativeSelect>
            {other && (
                <Input
                    id={`${id}-other`}
                    value={value.reason_other}
                    onChange={(event) =>
                        onChange({ ...value, reason_other: event.target.value })
                    }
                    minLength={OTHER_REASON_MIN}
                    maxLength={OTHER_REASON_MAX}
                    placeholder="Specify (at least 10 characters)"
                    autoComplete="off"
                    aria-label="Reason: specify"
                    className={cn('bg-background', controlClassName)}
                    aria-invalid={errors.reason_other ? true : undefined}
                    aria-describedby={errors.reason_other ? errorId : undefined}
                />
            )}
        </>
    );
}
