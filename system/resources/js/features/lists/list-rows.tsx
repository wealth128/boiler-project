import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { ErrorText, Hint } from './list-panel';
import { checkBracket, firstError, normalizeName } from './rules';
import type { BracketItem, ListLimits } from './types';

const linkClass = 'h-auto px-1.5 py-0.5 text-xs';

/** Shown under every name being edited (planning/mvp-scope.md). */
export const EDIT_NAME_HINT =
    'Use Edit only to fix spelling. To use a different item, add a new one.';

type ItemRowProps = {
    /** Base for the button ids, e.g. "diagnoses-12". */
    rowId: string;
    label: string;
    /** Ages: shown in tabular numbers. */
    numeric?: boolean;
    /** "Other (specify)": shows "Fixed" and no buttons. */
    fixed?: boolean;
    /** The row's "Move to Trash?" row is open below it. */
    deleting?: boolean;
    /** Move up / Move down, for ranks. */
    move?: {
        canUp: boolean;
        canDown: boolean;
        busy: boolean;
        onMove: (direction: 'up' | 'down') => void;
    };
    onEdit: () => void;
    onDelete: () => void;
};

/** One item: its name and Edit / Delete (and Move up / down for ranks). */
export function ItemRow({
    rowId,
    label,
    numeric = false,
    fixed = false,
    deleting = false,
    move,
    onEdit,
    onDelete,
}: ItemRowProps) {
    return (
        <li
            className={cn(
                'flex min-h-10 items-center gap-2 border-b px-2.5 py-1.5 last:border-b-0',
                deleting && 'bg-bad-soft',
            )}
        >
            <span
                className={cn(
                    'min-w-0 flex-1 break-words',
                    numeric && 'tabular-nums',
                )}
            >
                {label}
            </span>
            {fixed ? (
                <span className="text-xs text-muted-foreground">Fixed</span>
            ) : (
                <div className="flex flex-nowrap items-center gap-1">
                    {move && (
                        <>
                            <Button
                                id={`${rowId}-up`}
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                disabled={!move.canUp || move.busy}
                                onClick={() => move.onMove('up')}
                                aria-label={`Move ${label} up`}
                                title="Move up"
                            >
                                <ArrowUp />
                            </Button>
                            <Button
                                id={`${rowId}-down`}
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                disabled={!move.canDown || move.busy}
                                onClick={() => move.onMove('down')}
                                aria-label={`Move ${label} down`}
                                title="Move down"
                            >
                                <ArrowDown />
                            </Button>
                        </>
                    )}
                    <Button
                        id={`${rowId}-edit`}
                        variant="link"
                        size="sm"
                        className={linkClass}
                        onClick={onEdit}
                        aria-label={`Edit ${label}`}
                    >
                        Edit
                    </Button>
                    <Button
                        id={`${rowId}-delete`}
                        variant="link"
                        size="sm"
                        className={`${linkClass} text-bad`}
                        onClick={onDelete}
                        aria-label={`Delete ${label}`}
                    >
                        Delete
                    </Button>
                </div>
            )}
        </li>
    );
}

type EditFormProps = {
    /** PUT /settings/{list}/{id} */
    url: string;
    onDone: () => void;
    onCancel: () => void;
};

/**
 * The form inside an item being edited. Enter saves, Esc cancels.
 */
function EditForm({
    children,
    onSubmit,
    onCancel,
    processing,
    error,
    errorId,
    hint,
}: {
    children: ReactNode;
    onSubmit: () => void;
    onCancel: () => void;
    processing: boolean;
    error: string | null;
    errorId: string;
    hint?: string;
}) {
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!processing) {
            onSubmit();
        }
    }

    function onKeyDown(event: KeyboardEvent<HTMLFormElement>) {
        if (event.key === 'Escape' && !processing) {
            event.preventDefault();
            onCancel();
        }
    }

    return (
        <li className="grid gap-1 border-b px-2.5 py-1.5 last:border-b-0">
            <form
                onSubmit={submit}
                onKeyDown={onKeyDown}
                noValidate
                className="flex flex-wrap items-center gap-2"
            >
                {children}
                <Button className="max-sm:w-full" type="submit" size="sm" disabled={processing}>
                    {processing && <Spinner />}
                    Save
                </Button>
                <Button className="max-sm:w-full"
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onCancel}
                    disabled={processing}
                >
                    Cancel
                </Button>
            </form>
            <ErrorText id={errorId} message={error} />
            {hint && <Hint>{hint}</Hint>}
        </li>
    );
}

/** Put the cursor in the first box with its text selected. */
function useSelectOnMount() {
    const ref = useRef<HTMLInputElement>(null);

    useEffect(() => {
        ref.current?.focus();
        ref.current?.select();
    }, []);

    return ref;
}

/**
 * Edit a diagnosis or rank name, with the "spelling only" hint.
 *
 * @param check The browser check; returns the problem or null
 */
export function NameEditRow({
    url,
    initial,
    label,
    check,
    onDone,
    onCancel,
}: EditFormProps & {
    initial: string;
    label: string;
    check: (name: string) => string | null;
}) {
    const id = useId();
    const ref = useSelectOnMount();
    const [value, setValue] = useState(initial);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    function save() {
        const problem = check(value);

        if (problem) {
            setError(problem);
            ref.current?.focus();

            return;
        }

        router.put(
            url,
            { name: normalizeName(value) },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
                onError: (errors) => {
                    setError(firstError(errors) ?? null);
                    ref.current?.focus();
                },
            },
        );
    }

    return (
        <EditForm
            onSubmit={save}
            onCancel={onCancel}
            processing={processing}
            error={error}
            errorId={`${id}-error`}
            hint={EDIT_NAME_HINT}
        >
            <Input
                ref={ref}
                value={value}
                onChange={(event) => {
                    setValue(event.target.value);
                    setError(null);
                }}
                autoComplete="off"
                aria-label={`New name for ${label}`}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${id}-error` : undefined}
                className="h-8 min-w-40 flex-1"
            />
        </EditForm>
    );
}

/** Edit an age bracket's From and To. Blank To means "and above". */
export function BracketEditRow({
    url,
    bracket,
    others,
    limits,
    onDone,
    onCancel,
}: EditFormProps & {
    bracket: BracketItem;
    /** The other active brackets, for the overlap check. */
    others: BracketItem[];
    limits: ListLimits;
}) {
    const id = useId();
    const fromRef = useSelectOnMount();
    const toRef = useRef<HTMLInputElement>(null);
    const [from, setFrom] = useState(String(bracket.min_age));
    const [to, setTo] = useState(
        bracket.max_age === null ? '' : String(bracket.max_age),
    );
    const [error, setError] = useState<{
        field: 'min_age' | 'max_age';
        message: string;
    } | null>(null);
    const [processing, setProcessing] = useState(false);

    function showError(field: 'min_age' | 'max_age', message: string) {
        setError({ field, message });
        (field === 'max_age' ? toRef : fromRef).current?.focus();
    }

    function save() {
        const result = checkBracket(from, to, others, limits);

        if (!result.ok) {
            showError(result.field, result.message);

            return;
        }

        router.put(
            url,
            { min_age: result.min, max_age: result.max },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
                onError: (errors) =>
                    showError(
                        errors.max_age ? 'max_age' : 'min_age',
                        firstError(errors) ?? '',
                    ),
            },
        );
    }

    const describedBy = (field: 'min_age' | 'max_age') =>
        error?.field === field ? `${id}-error` : undefined;

    return (
        <EditForm
            onSubmit={save}
            onCancel={onCancel}
            processing={processing}
            error={error?.message ?? null}
            errorId={`${id}-error`}
        >
            <Input
                ref={fromRef}
                type="number"
                inputMode="numeric"
                min={limits.age_min}
                max={limits.age_max}
                value={from}
                onChange={(event) => {
                    setFrom(event.target.value);
                    setError(null);
                }}
                aria-label="From age"
                aria-invalid={error?.field === 'min_age' ? true : undefined}
                aria-describedby={describedBy('min_age')}
                className="h-8 w-24"
            />
            <span className="text-sm">to</span>
            <Input
                ref={toRef}
                type="number"
                inputMode="numeric"
                min={limits.age_min}
                max={limits.age_max}
                value={to}
                placeholder="and above"
                onChange={(event) => {
                    setTo(event.target.value);
                    setError(null);
                }}
                aria-label="To age (blank = and above)"
                aria-invalid={error?.field === 'max_age' ? true : undefined}
                aria-describedby={describedBy('max_age')}
                className="h-8 w-28"
            />
        </EditForm>
    );
}
