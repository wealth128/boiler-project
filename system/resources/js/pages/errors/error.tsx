import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { home, login } from '@/routes';

type ErrorStatus = 403 | 404 | 500 | 503;

type ErrorPageProps = {
    status: ErrorStatus;
    /** Reason from the server, e.g. "September 2026 is closed..." (403 only). */
    message: string | null;
};

const TEXT: Record<ErrorStatus, { title: string; body: string }> = {
    403: {
        title: 'Not allowed',
        body: "Your account doesn't have access to this page or action. If you think it should, ask the Admin.",
    },
    404: {
        title: 'Page not found',
        body: 'This page does not exist, or the record was moved to Trash. Check the address, or go back to your home page.',
    },
    500: {
        title: 'Something went wrong',
        body: 'The system ran into a problem and could not finish. Nothing was saved from this last step. Please try again, and tell the Admin if it keeps happening.',
    },
    503: {
        title: 'Under maintenance',
        body: 'The system is being updated. Please try again in a few minutes.',
    },
};

/**
 * Friendly 403, 404, 500 and 503 page (bootstrap/app.php renders it).
 * Signed-in users see it inside the normal layout, with their menu.
 * Signed-out users see a plain panel with a link to the login page.
 */
export default function ErrorPage({ status, message }: ErrorPageProps) {
    const props = usePage().props;
    // Shared props can be missing if the error happened very early.
    const user = (props.auth as typeof props.auth | undefined)?.user ?? null;
    const text = TEXT[status] ?? TEXT[500];

    const content = (
        <>
            <Head title={text.title} />
            <section
                aria-labelledby="error-title"
                className="max-w-xl rounded-lg border bg-card p-6"
            >
                <p className="text-sm font-semibold text-muted-foreground">
                    Error {status}
                </p>
                <h1
                    id="error-title"
                    className="mt-1 text-xl font-semibold text-balance"
                >
                    {text.title}
                </h1>
                {message && (
                    <p className="mt-3 rounded-md bg-bad-soft px-3 py-2 text-sm text-bad">
                        {message}
                    </p>
                )}
                <p className="mt-3 text-sm text-muted-foreground">
                    {text.body}
                </p>
                <div className="mt-5 flex flex-wrap gap-2">
                    {user ? (
                        <Button asChild>
                            <Link href={home()}>Go to my home page</Link>
                        </Button>
                    ) : (
                        <Button asChild>
                            <Link href={login()}>Go to login</Link>
                        </Button>
                    )}
                    <Button
                        variant="outline"
                        onClick={() => window.history.back()}
                    >
                        Go back
                    </Button>
                </div>
            </section>
        </>
    );

    if (user) {
        return <AppLayout>{content}</AppLayout>;
    }

    return <SignedOutShell>{content}</SignedOutShell>;
}

function SignedOutShell({ children }: { children: ReactNode }) {
    const { name, hospital } = usePage().props;

    return (
        <div className="grid min-h-svh place-items-center bg-page px-4 py-6 text-foreground">
            <main className="w-full max-w-xl">
                <p className="mb-3 text-sm text-muted-foreground">
                    {hospital?.name ?? ''}
                    {name ? ` · ${name}` : ''}
                </p>
                {children}
            </main>
        </div>
    );
}
