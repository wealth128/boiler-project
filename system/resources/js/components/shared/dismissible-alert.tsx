import { usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    readDismissal,
    shouldShow,
    writeDismissal,
} from '@/lib/alert-dismissal';
import type { AlertLevel } from '@/lib/alert-dismissal';
import { cn } from '@/lib/utils';

type DismissibleAlertProps = {
    level: AlertLevel;
    /** The warning text. A new text shows the bar again at once. */
    message: string;
    /** The link after the text ("Open Backups"). */
    children?: ReactNode;
    /**
     * False on the pages that show the full status (Backups, System health):
     * the bar then has no x.
     */
    dismissible?: boolean;
};

/**
 * One warning bar for the top of an Admin page. The x only hides it in this
 * browser (nothing on the server changes): yellow bars come back tomorrow,
 * red bars at the next sign-in, and any bar when its text changes. See
 * lib/alert-dismissal.ts.
 *
 * Callers must pass key={message}: the saved dismissal is read once when the
 * bar mounts, so a changed message needs a fresh mount to show again.
 */
export function DismissibleAlert({
    level,
    message,
    children,
    dismissible = true,
}: DismissibleAlertProps) {
    const { auth } = usePage().props;
    const userId = auth.user?.id ?? 0;
    const loginAt = auth.loginAt ?? null;

    const [hidden, setHidden] = useState(
        () =>
            dismissible &&
            !shouldShow(
                level,
                readDismissal(userId, message),
                new Date(),
                loginAt,
            ),
    );

    if (hidden) {
        return null;
    }

    return (
        <div
            role={level === 'error' ? 'alert' : 'status'}
            className={cn(
                'flex items-start gap-2 rounded-md border px-3 py-2 text-sm font-medium print:hidden',
                level === 'error'
                    ? 'border-bad bg-bad-soft text-bad'
                    : 'border-warn bg-warn-soft text-warn',
            )}
        >
            <p className="min-w-0 flex-1">
                {message} {children}
            </p>
            {dismissible && (
                <button
                    type="button"
                    aria-label="Close this warning"
                    className="-my-0.5 -mr-1 shrink-0 rounded p-1 hover:bg-current/10 focus-visible:ring-2 focus-visible:ring-current focus-visible:outline-none"
                    onClick={() => {
                        writeDismissal(userId, message, loginAt);
                        setHidden(true);
                    }}
                >
                    <X className="size-4" aria-hidden="true" />
                </button>
            )}
        </div>
    );
}
