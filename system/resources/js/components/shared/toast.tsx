import type { CSSProperties } from 'react';
import { Toaster } from 'sonner';
import { useAppearance } from '@/hooks/use-appearance';
import { useFlashToast } from '@/hooks/use-flash-toast';

/**
 * Where short messages appear: a dark bar at the bottom center, like the
 * prototype. Mounted once in app.tsx.
 *
 * Show one from a page with useToast(), or from the server with
 * Inertia::flash('toast', ['type' => 'success', 'message' => '…']).
 */
export function Toast() {
    const { appearance } = useAppearance();

    useFlashToast();

    return (
        <Toaster
            theme={appearance}
            position="bottom-center"
            duration={3000}
            className="toaster group"
            toastOptions={{ className: 'font-sans font-medium' }}
            style={
                {
                    '--normal-bg': 'var(--foreground)',
                    '--normal-text': 'var(--background)',
                    '--normal-border': 'var(--foreground)',
                } as CSSProperties
            }
        />
    );
}
