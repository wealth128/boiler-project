import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type EmptyStateProps = {
    /** Plain sentence, e.g. "No visits match these filters." */
    message: ReactNode;
    /** Optional bold first line. */
    title?: string;
    /** Optional button or link, e.g. "Clear filters". */
    action?: ReactNode;
    className?: string;
};

/** What a list or table shows when it has nothing in it. */
export function EmptyState({
    message,
    title,
    action,
    className,
}: EmptyStateProps) {
    return (
        <div
            className={cn('px-4 py-5 text-sm text-muted-foreground', className)}
        >
            {title && (
                <p className="mb-1 font-medium text-foreground">{title}</p>
            )}
            <p>{message}</p>
            {action && <div className="mt-3">{action}</div>}
        </div>
    );
}
