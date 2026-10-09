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
            [key: string]: unknown;
        };
    }
}
