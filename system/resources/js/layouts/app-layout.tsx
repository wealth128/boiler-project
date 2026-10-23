import { Breadcrumbs } from "@/components/breadcrumbs";
import { BackupAlerts } from "@/components/shared/backup-alerts";
import { SchedulerAlert } from "@/components/shared/scheduler-alert";
import { AppSideNav } from "@/layouts/app/app-side-nav";
import { AppTopBar } from "@/layouts/app/app-top-bar";
import type { AppLayoutProps } from "@/types";

/**
 * Layout for every signed-in screen (set in app.tsx): navy top bar,
 * role-based left menu, and the page on a light grey background.
 */
export default function AppLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col bg-page text-foreground">
            <a
                href="#main"
                className="sr-only print:hidden rounded-md bg-card px-3 py-2 focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50"
            >
                Skip to main content
            </a>
            <AppTopBar />
            <div className="flex flex-1 print:block">
                <AppSideNav />
                <main
                    id="main"
                    className="min-w-0 flex-1 px-3 pt-4 pb-10 sm:px-4 md:px-6 md:pt-5 print:p-0"
                >
                    {breadcrumbs.length > 0 && (
                        <div className="mb-3 print:hidden">
                            <Breadcrumbs breadcrumbs={breadcrumbs} />
                        </div>
                    )}
                    <SchedulerAlert />
                    <BackupAlerts />
                    {children}
                </main>
            </div>
        </div>
    );
}
