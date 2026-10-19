import { Link, usePage } from '@inertiajs/react';
import BackupController from '@/actions/App/Http/Controllers/Backup/BackupController';
import { DismissibleAlert } from '@/components/shared/dismissible-alert';
import type { BackupAlert } from '@/features/backups/types';

/**
 * Backup warnings at the top of every Admin page: red when a backup or the
 * test restore failed or a backup is missing, yellow when the USB copy or
 * test restore is overdue. The server sends the list only to Admin. Hidden
 * on the Backups page itself, which shows the same warnings in full. On
 * System health the bars have no x. Each bar can be closed with the x (see
 * DismissibleAlert).
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
                <DismissibleAlert
                    key={alert.message}
                    level={alert.level}
                    message={alert.message}
                    dismissible={!url.startsWith('/system/health')}
                >
                    <Link
                        href={BackupController.index.url()}
                        className="underline underline-offset-2"
                    >
                        Open Backups
                    </Link>
                </DismissibleAlert>
            ))}
        </div>
    );
}
