import { usePage } from "@inertiajs/react";
import { ROLE_LABELS } from "@/lib/format";
import { ThemeToggle } from "@/components/shared/theme-toggle";
import { MobileMenu } from "./app-side-nav";

/**
 * Navy bar across the top: menu button (below 1024 px), hospital logo when
 * one is set, hospital and app name, and who is logged in.
 */
export function AppTopBar() {
    const { name, hospital, auth } = usePage().props;
    const user = auth.user as typeof auth.user | null;

    return (
        <header className="flex flex-wrap items-center gap-x-3 gap-y-2 bg-bar px-3 py-2 text-bar-foreground sm:px-4 print:hidden">
            <MobileMenu />
            {hospital.logo_url && (
                // Decorative: the hospital name is written next to it.
                <img
                    src={hospital.logo_url}
                    alt=""
                    className="h-9 max-w-[7rem] shrink-0 rounded-sm object-contain"
                />
            )}
            <div className="mr-auto flex min-w-0 flex-1 flex-col leading-tight">
                <span className="text-[15px] font-bold tracking-[0.01em] break-words">
                    {hospital.name} · {name}
                </span>
                {hospital.subtitle && (
                    <span className="text-sm opacity-80">
                        {hospital.subtitle}
                    </span>
                )}
            </div>
            {user && (
                <div className="ml-auto text-right text-sm leading-tight max-sm:w-full max-sm:text-left">
                    {user.name}
                    <span className="block text-sm opacity-80">
                        {ROLE_LABELS[user.role]} · {user.office}
                    </span>
                </div>
            )}
            {user && <ThemeToggle />}
        </header>
    );
}
