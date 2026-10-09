import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type PageHeaderProps = {
    title: string;
    /** One short line under or beside the title. */
    description?: ReactNode;
    /** Buttons on the right, e.g. "Empty Trash" or "Export". */
    actions?: ReactNode;
    className?: string;
};

/**
 * The heading at the top of every screen. Set the browser tab title
 * separately with Inertia's <Head title="…" />.
 */
export function PageHeader({
    title,
    description,
    actions,
    className,
}: PageHeaderProps) {
    return (
        <div
            className={cn(
                'mb-4 flex flex-wrap items-baseline gap-x-4 gap-y-2',
                className,
            )}
        >
            <h1 className="text-xl font-semibold text-balance">{title}</h1>
            {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
            {actions && (
                <div className="ml-auto flex flex-wrap items-center gap-2 self-center">
                    {actions}
                </div>
            )}
        </div>
    );
}
