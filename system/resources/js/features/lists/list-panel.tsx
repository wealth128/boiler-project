import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type ListPanelProps = {
    title: string;
    /** A count chip or a hint, beside the title. */
    aside?: ReactNode;
    className?: string;
    children: ReactNode;
};

/** One box on the Dropdown Lists screen (prototype: .panel). */
export function ListPanel({
    title,
    aside,
    className,
    children,
}: ListPanelProps) {
    const headingId = `list-${title.toLowerCase().replace(/\W+/g, '-')}`;

    return (
        <section
            aria-labelledby={headingId}
            className={cn('min-w-0 rounded-lg border bg-card', className)}
        >
            <div className="flex flex-wrap items-center gap-2.5 border-b px-4 py-3">
                <h2
                    id={headingId}
                    className="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    {title}
                </h2>
                {aside}
            </div>
            <div className="grid gap-3 p-4">{children}</div>
        </section>
    );
}

/** The scrolling list of items inside a panel (prototype: .lrows). */
export function ItemList({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <ul
            aria-label={label}
            className="max-h-[340px] overflow-auto rounded-md border"
        >
            {children}
        </ul>
    );
}

/** A small grey line of help text. */
export function Hint({ children }: { children: ReactNode }) {
    return <p className="text-xs text-muted-foreground">{children}</p>;
}

/** A red message under a form, read out by screen readers. */
export function ErrorText({
    id,
    message,
}: {
    id: string;
    message?: string | null;
}) {
    if (!message) {
        return null;
    }

    return (
        <p id={id} role="alert" className="text-sm font-medium text-bad">
            {message}
        </p>
    );
}
