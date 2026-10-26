import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, RefObject } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/dates';
import { SearchRateLimited, searchPatients } from './search-patients';
import type { PatientMatch } from './types';

/** Same as PatientSearchController::MIN_LENGTH. */
const MIN_LENGTH = 2;
const DEBOUNCE_MS = 250;

type PatientSearchProps = {
    query: string;
    onQueryChange: (query: string) => void;
    onPick: (patient: PatientMatch) => void;
    inputRef: RefObject<HTMLInputElement | null>;
};

type SearchState =
    | { status: 'idle' }
    | { status: 'done'; query: string; results: PatientMatch[] }
    | { status: 'failed'; query: string; limited: boolean };

/**
 * "Find returning patient": type a last name or Patient ID, then pick the
 * person so the visit keeps their Patient ID. Patients in Trash are never
 * listed (the server leaves them out).
 *
 * Keys: Down arrow moves into the results, Up / Down between them, Esc
 * closes the list.
 */
export function PatientSearch({
    query,
    onQueryChange,
    onPick,
    inputRef,
}: PatientSearchProps) {
    const [search, setSearch] = useState<SearchState>({ status: 'idle' });
    const [open, setOpen] = useState(false);
    const listRef = useRef<HTMLDivElement>(null);
    const wrapRef = useRef<HTMLDivElement>(null);
    const term = query.trim();

    useEffect(() => {
        if (term.length < MIN_LENGTH) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            try {
                const results = await searchPatients(term, controller.signal);

                if (results !== null) {
                    setSearch({ status: 'done', query: term, results });
                }
            } catch (error) {
                if (!controller.signal.aborted) {
                    console.error(error);
                    setSearch({
                        status: 'failed',
                        query: term,
                        limited: error instanceof SearchRateLimited,
                    });
                }
            }
        }, DEBOUNCE_MS);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [term]);

    const showList =
        open && term.length >= MIN_LENGTH && search.status !== 'idle';
    // Old results stay visible while the next search runs.
    const results = search.status === 'done' ? search.results : [];

    function pick(patient: PatientMatch) {
        setOpen(false);
        onPick(patient);
    }

    function resultButtons(): HTMLButtonElement[] {
        return Array.from(
            listRef.current?.querySelectorAll<HTMLButtonElement>(
                'button[data-pick]',
            ) ?? [],
        );
    }

    function onInputKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'ArrowDown') {
            const first = resultButtons()[0];

            if (first) {
                event.preventDefault();
                setOpen(true);
                first.focus();
            }
        } else if (event.key === 'Escape') {
            setOpen(false);
        }
    }

    function onListKeyDown(event: KeyboardEvent<HTMLDivElement>) {
        const buttons = resultButtons();
        const index = buttons.indexOf(
            document.activeElement as HTMLButtonElement,
        );

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            buttons[Math.min(index + 1, buttons.length - 1)]?.focus();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();

            if (index <= 0) {
                inputRef.current?.focus();
            } else {
                buttons[index - 1]?.focus();
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            setOpen(false);
            inputRef.current?.focus();
        }
    }

    return (
        <div
            ref={wrapRef}
            className="relative grid gap-1.5"
            onBlur={(event) => {
                if (!wrapRef.current?.contains(event.relatedTarget as Node)) {
                    setOpen(false);
                }
            }}
        >
            <Label htmlFor="encode-search">Find returning patient</Label>
            <Input
                ref={inputRef}
                id="encode-search"
                type="search"
                value={query}
                onChange={(event) => {
                    onQueryChange(event.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onKeyDown={onInputKeyDown}
                placeholder="Type last name or Patient ID, e.g. Santos or P-000012"
                autoComplete="off"
                spellCheck={false}
                autoFocus
                role="combobox"
                aria-expanded={showList}
                aria-controls="encode-search-results"
                aria-autocomplete="list"
            />

            {showList && (
                <div
                    ref={listRef}
                    id="encode-search-results"
                    onKeyDown={onListKeyDown}
                    className="absolute top-full right-0 left-0 z-10 mt-1 max-h-64 overflow-auto rounded-md border bg-popover text-popover-foreground shadow-lg"
                >
                    {search.status === 'failed' ? (
                        <p className="px-3 py-2.5 text-sm text-muted-foreground">
                            {search.limited
                                ? 'Too many searches, wait a minute. You can also fill in the form below.'
                                : "Search isn't working right now. Try again, or fill in the form below."}
                        </p>
                    ) : results.length === 0 ? (
                        <p className="px-3 py-2.5 text-sm text-muted-foreground">
                            No match. Fill in the form below as a new patient.
                        </p>
                    ) : (
                        results.map((patient) => (
                            <button
                                key={patient.id}
                                type="button"
                                data-pick={patient.id}
                                onClick={() => pick(patient)}
                                className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm outline-none hover:bg-navy-soft focus-visible:bg-navy-soft"
                            >
                                <span className="min-w-0">
                                    <b className="font-semibold">
                                        {patient.full_name}
                                    </b>
                                    <span className="text-muted-foreground">
                                        {' · '}
                                        {patient.sex}
                                        {' · '}
                                        {formatDate(patient.birthdate)}
                                    </span>
                                </span>
                                <span className="shrink-0 font-mono text-xs">
                                    {patient.patient_no}
                                </span>
                            </button>
                        ))
                    )}
                </div>
            )}

            <p className="text-xs text-muted-foreground">
                Search first so a returning patient keeps the same Patient ID.
            </p>
        </div>
    );
}
