import { usePage } from '@inertiajs/react';
import type { AuthLayoutProps } from '@/types';

/**
 * Layout for the login screen and other signed-out pages (set in app.tsx):
 * one white panel in the middle with the hospital name on top.
 */
export default function AuthLayout({
    title = '',
    description = '',
    children,
}: AuthLayoutProps) {
    const { name, hospital } = usePage().props;

    return (
        <div className="grid min-h-svh place-items-center bg-page px-4 py-6 text-foreground">
            <main className="w-full max-w-[400px] rounded-lg border bg-card">
                <div className="flex flex-col gap-0.5 border-b px-4 py-3">
                    <h1 className="text-lg font-semibold">{hospital.name}</h1>
                    <p className="text-xs text-muted-foreground">{name}</p>
                </div>
                <div className="grid gap-4 p-4">
                    {(title || description) && (
                        <div className="grid gap-1">
                            {title && (
                                <h2 className="text-base font-semibold">
                                    {title}
                                </h2>
                            )}
                            {description && (
                                <p className="text-sm text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </div>
                    )}
                    {children}
                </div>
            </main>
        </div>
    );
}
