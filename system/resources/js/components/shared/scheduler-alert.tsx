import { Link, usePage } from '@inertiajs/react';
import system from '@/routes/system';

/**
 * Red bar on every Admin page when the scheduler stopped (no automatic
 * backup, no 8 PM sign-out). The server sends the text only to Admin and
 * only when the "last ran" time is more than 5 minutes old.
 */
export function SchedulerAlert() {
    const { schedulerAlert, auth } = usePage().props;
    const user = auth.user as typeof auth.user | null;

    if (!user || user.role !== 'admin' || !schedulerAlert) {
        return null;
    }

    return (
        <p
            role="alert"
            className="mb-4 rounded-md border border-bad bg-bad-soft px-3 py-2 text-sm font-medium text-bad print:hidden"
        >
            {schedulerAlert}{' '}
            <Link href={system.health()} className="underline underline-offset-2">
                Open System health
            </Link>
        </p>
    );
}
