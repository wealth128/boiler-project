import { Breadcrumbs } from '@/components/breadcrumbs';
import { AppSideNav } from '@/layouts/app/app-side-nav';
import { AppTopBar } from '@/layouts/app/app-top-bar';
import type { AppLayoutProps } from '@/types';

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
                className="sr-only rounded-md bg-card px-3 py-2 focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50"
            >
                Skip to main content
            </a>
            <AppTopBar />
            <div className="flex flex-1 flex-col md:flex-row">
                <AppSideNav />
                <main
                    id="main"
                    className="min-w-0 flex-1 px-4 pt-5 pb-10 md:px-6"
                >
                    {breadcrumbs.length > 0 && (
                        <div className="mb-3">
                            <Breadcrumbs breadcrumbs={breadcrumbs} />
                        </div>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}
