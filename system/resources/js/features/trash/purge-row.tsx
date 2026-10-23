import { useId, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

type PurgeRowProps = {
    /** "Delete permanently?" or "Empty Trash?" */
    question: string;
    /** The text to type: an ID, or "EMPTY TRASH". */
    confirmText: string;
    /** Called with the typed text once it matches. */
    onConfirm: (typed: string) => void;
    onCancel: () => void;
    /** True while the request is running: buttons are disabled. */
    processing?: boolean;
    /** Message from the server, e.g. a refusal. */
    error?: string;
    className?: string;
};

/** Dashes count as the same character, like the server (TrashRows::matches). */
function plain(text: string): string {
    return text.replace(/[–—−]/g, '-');
}

/**
 * The inline "Delete permanently? This can't be undone. Type <ID> to
 * confirm:" row (prototype/prototype.html, trashView). Enter confirms, Esc
 * cancels. The server checks the typed text again.
 */
export function PurgeRow({
    question,
    confirmText,
    onConfirm,
    onCancel,
    processing = false,
    error,
    className,
}: PurgeRowProps) {
    const id = useId();
    const [typed, setTyped] = useState('');
    const [mismatch, setMismatch] = useState(false);
    const message = mismatch ? `Type ${confirmText} exactly to confirm.` : error;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (processing) {
            return;
        }

        if (plain(typed.trim()) !== plain(confirmText)) {
            setMismatch(true);

            return;
        }

        onConfirm(typed.trim());
    }

    function onKeyDown(event: KeyboardEvent<HTMLFormElement>) {
        if (event.key === 'Escape' && !processing) {
            event.preventDefault();
            onCancel();
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
                <b>{question}</b> This can&apos;t be undone. Type{' '}
                <span className="font-mono">{confirmText}</span> to confirm:
            </label>
            <Input
                id={id}
                value={typed}
                onChange={(event) => {
                    setTyped(event.target.value);
                    setMismatch(false);
                }}
                className="h-8 max-w-60 font-mono"
                autoComplete="off"
                autoCapitalize="off"
                spellCheck={false}
                autoFocus
                aria-invalid={message ? true : undefined}
                aria-describedby={message ? `${id}-error` : undefined}
            />
            <Button className="max-sm:w-full"
                type="submit"
                variant="destructive"
                size="sm"
                disabled={processing}
            >
                {processing && <Spinner />}
                Delete permanently
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
