import { router } from '@inertiajs/react';
import { useId, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { ErrorText } from './list-panel';
import { checkBracket, firstError, normalizeName } from './rules';
import type { BracketItem, ListLimits } from './types';

type AddNameFormProps = {
    /** POST /settings/{list} */
    url: string;
    /** Sent with the name, e.g. { branch_id: 2 } for ranks. */
    extra?: Record<string, number>;
    placeholder: string;
    inputLabel: string;
    buttonLabel: string;
    /** The browser check; returns the problem or null. */
    check: (name: string) => string | null;
};

/**
 * "Add a diagnosis" / "Add to Army" (prototype: .addrow). Enter adds; the
 * box clears and keeps the cursor, ready for the next one.
 */
export function AddNameForm({
    url,
    extra = {},
    placeholder,
    inputLabel,
    buttonLabel,
    check,
}: AddNameFormProps) {
    const id = useId();
    const ref = useRef<HTMLInputElement>(null);
    const [value, setValue] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (processing) {
            return;
        }

        const problem = check(value);

        if (problem) {
            setError(problem);
            ref.current?.focus();

            return;
        }

        router.post(
            url,
            { ...extra, name: normalizeName(value) },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setValue('');
                    ref.current?.focus();
                },
                onError: (errors) => {
                    setError(firstError(errors) ?? null);
                    ref.current?.focus();
                },
            },
        );
    }

    return (
        <div className="grid gap-1">
            <form
                onSubmit={submit}
                noValidate
                className="flex flex-wrap items-center gap-2"
            >
                <Input
                    ref={ref}
                    value={value}
                    onChange={(event) => {
                        setValue(event.target.value);
                        setError(null);
                    }}
                    placeholder={placeholder}
                    autoComplete="off"
                    aria-label={inputLabel}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? `${id}-error` : undefined}
                    className="h-8 min-w-40 flex-1"
                />
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    disabled={processing}
                >
                    {processing && <Spinner />}
                    {buttonLabel}
                </Button>
            </form>
            <ErrorText id={`${id}-error`} message={error} />
        </div>
    );
}

/**
 * Add an age bracket: From age and To age (blank = and above). Enter adds.
 */
export function AddBracketForm({
    url,
    brackets,
    limits,
}: {
    url: string;
    /** The active brackets, for the overlap check. */
    brackets: BracketItem[];
    limits: ListLimits;
}) {
    const id = useId();
    const fromRef = useRef<HTMLInputElement>(null);
    const toRef = useRef<HTMLInputElement>(null);
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [error, setError] = useState<{
        field: 'min_age' | 'max_age';
        message: string;
    } | null>(null);
    const [processing, setProcessing] = useState(false);

    function showError(field: 'min_age' | 'max_age', message: string) {
        setError({ field, message });
        (field === 'max_age' ? toRef : fromRef).current?.focus();
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (processing) {
            return;
        }

        const result = checkBracket(from, to, brackets, limits);

        if (!result.ok) {
            showError(result.field, result.message);

            return;
        }

        router.post(
            url,
            { min_age: result.min, max_age: result.max },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setFrom('');
                    setTo('');
                    fromRef.current?.focus();
                },
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
        <div className="grid gap-1">
            <form
                onSubmit={submit}
                noValidate
                className="flex flex-wrap items-center gap-2"
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
                    placeholder="From age"
                    aria-label="From age"
                    aria-invalid={error?.field === 'min_age' ? true : undefined}
                    aria-describedby={describedBy('min_age')}
                    className="h-8 w-28"
                />
                <Input
                    ref={toRef}
                    type="number"
                    inputMode="numeric"
                    min={limits.age_min}
                    max={limits.age_max}
                    value={to}
                    onChange={(event) => {
                        setTo(event.target.value);
                        setError(null);
                    }}
                    placeholder="To age (blank = and above)"
                    aria-label="To age (blank = and above)"
                    aria-invalid={error?.field === 'max_age' ? true : undefined}
                    aria-describedby={describedBy('max_age')}
                    className="h-8 min-w-48 flex-1"
                />
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    disabled={processing}
                >
                    {processing && <Spinner />}
                    Add
                </Button>
            </form>
            <ErrorText id={`${id}-error`} message={error?.message} />
        </div>
    );
}
