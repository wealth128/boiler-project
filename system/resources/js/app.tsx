import { createInertiaApp } from '@inertiajs/react';
import { Toast } from '@/components/shared/toast';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// The starter kit's own account pages (profile, password, appearance).
// Not the Dropdown Lists screen, which lives at /settings/lists.
const accountSettingsPages = [
    'settings/profile',
    'settings/security',
    'settings/appearance',
];

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // The error page picks its own layout: the app menu when signed
            // in, a plain panel when signed out.
            case name.startsWith('errors/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case accountSettingsPages.includes(name):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toast />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#3b6fd1',
    },
});

// This will set light / dark mode on load...
initializeTheme();
