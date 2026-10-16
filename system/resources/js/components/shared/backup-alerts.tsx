import { Link, usePage } from '@inertiajs/react';
import BackupController from '@/actions/App/Http/Controllers/Backup/BackupController';
import type { BackupAlert } from '@/features/backups/types';
import { cn } from '@/lib/utils';

/**
 * Backup warnings at the top of every Admin page: red when a backup failed
 * or is missing, yellow when the USB copy or test restore is overdue. The
 * server sends the list only to Admin. Hidden on the Backups page itself,
 * which shows the same warnings in full.
 */
export function BackupAlerts() {
    const { props, url } = usePage();
    const { backupAlerts, auth } = props;
    const alerts = (backupAlerts ?? []) as BackupAlert[];
    const user = auth.user as typeof auth.user | null;

    if (
        !user ||
        user.role !== 'admin' ||
        alerts.length === 0 ||
        url.startsWith('/backups')
    ) {
        return null;
    }

    return (
        <div className="mb-4 grid gap-2 print:hidden">
            {alerts.map((alert) => (
                <p
                    key={alert.message}
                    role={alert.level === 'error' ? 'alert' : 'status'}
                    className={cn(
                        'rounded-md border px-3 py-2 text-sm font-medium',
                        alert.level === 'error'
                            ? 'border-bad bg-bad-soft text-bad'
                            : 'border-warn bg-warn-soft text-warn',
                    )}
                >
                    {alert.message}{' '}
                    <Link
                        href={BackupController.index.url()}
                        className="underline underline-offset-2"
                    >
                        Open Backups
                    </Link>
                </p>
            ))}
        </div>
    );
}
