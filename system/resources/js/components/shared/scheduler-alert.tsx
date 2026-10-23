import { Link, usePage } from '@inertiajs/react';
import { DismissibleAlert } from '@/components/shared/dismissible-alert';
import system from '@/routes/system';

/**
 * Red bar on every Admin page when the scheduler stopped (no automatic
 * backup, no 8 PM sign-out). The server sends the text only to Admin and
 * only when the "last ran" time is more than 5 minutes old. The x hides it
 * until the next sign-in (see DismissibleAlert); System health has no x.
 */
export function SchedulerAlert() {
    const { schedulerAlert, auth } = usePage().props;
    const { url } = usePage();
    const user = auth.user as typeof auth.user | null;

    if (!user || user.role !== 'admin' || !schedulerAlert) {
        return null;
    }

    return (
        <div className="mb-4 print:hidden">
            <DismissibleAlert
                key={schedulerAlert}
                level="error"
                message={schedulerAlert}
                dismissible={!url.startsWith('/system/health')}
            >
                <Link
                    href={system.health()}
                    className="underline underline-offset-2 max-lg:inline-flex max-lg:min-h-10 max-lg:items-center"
                >
                    Open System health
                </Link>
            </DismissibleAlert>
        </div>
    );
}
