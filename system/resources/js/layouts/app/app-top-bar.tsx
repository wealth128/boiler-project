import { usePage } from '@inertiajs/react';
import { ROLE_LABELS } from '@/lib/format';

/** Navy bar across the top: hospital and app name, and who is logged in. */
export function AppTopBar() {
    const { name, hospital, auth } = usePage().props;
    const user = auth.user as typeof auth.user | null;

    return (
        <header className="flex flex-wrap items-center gap-x-5 gap-y-2 bg-navy px-4 py-2.5 text-navy-foreground">
            <div className="mr-auto flex min-w-0 flex-col leading-tight">
                <span className="text-[15px] font-bold tracking-[0.01em]">
                    {hospital.name} · {name}
                </span>
                {hospital.subtitle && (
                    <span className="text-xs opacity-80">
                        {hospital.subtitle}
                    </span>
                )}
            </div>
            {user && (
                <div className="text-right text-[13px] leading-tight">
                    {user.name}
                    <span className="block text-xs opacity-80">
                        {ROLE_LABELS[user.role]} · {user.office}
                    </span>
                </div>
            )}
        </header>
    );
}
