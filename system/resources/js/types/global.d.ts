import type { BackupAlert } from '@/features/backups/types';
import type { Auth, Hospital } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            hospital: Hospital;
            sidebarOpen: boolean;
            /** Backup warnings, Admin only (empty for everyone else). */
            backupAlerts: BackupAlert[];
            /** Red bar text when the scheduler stopped, Admin only. */
            schedulerAlert: string | null;
            [key: string]: unknown;
        };
    }
}
