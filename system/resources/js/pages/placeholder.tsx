import { Head, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';

/**
 * TEMPORARY (Step 1): shown by every route whose screen is not built yet.
 * Delete once each feature has its own page.
 */
type PlaceholderProps = {
    title: string;
    description: string;
};

export default function Placeholder({ title, description }: PlaceholderProps) {
    const { url } = usePage();

    return (
        <>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading title={title} description={description} />
                <div className="relative min-h-64 flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    <div className="relative flex h-full flex-col items-center justify-center gap-1 p-6 text-center">
                        <p className="font-medium">Not built yet</p>
                        <p className="text-sm text-muted-foreground">
                            Route <code>{url}</code> is connected. This screen
                            comes in a later step.
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
