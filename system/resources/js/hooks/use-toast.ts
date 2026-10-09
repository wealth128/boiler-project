import { toast } from 'sonner';

export type UseToastReturn = {
    success: (message: string) => void;
    error: (message: string) => void;
    info: (message: string) => void;
    warning: (message: string) => void;
};

/**
 * Show a short message at the bottom of the screen, e.g. "Saved P-000012".
 * The <Toast /> in app.tsx displays it.
 *
 * Messages from the server use Inertia::flash('toast', [...]) instead and
 * are shown automatically (hooks/use-flash-toast).
 */
export function useToast(): UseToastReturn {
    return {
        success: (message) => toast.success(message),
        error: (message) => toast.error(message),
        info: (message) => toast.info(message),
        warning: (message) => toast.warning(message),
    };
}
