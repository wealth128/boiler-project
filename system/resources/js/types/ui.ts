import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};

/** Page numbers for a table (TablePaging::meta). */
export type PageMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    /** Row number of the first row on this page, or null when empty. */
    from: number | null;
    to: number | null;
};

export type Paged<T> = {
    data: T[];
    meta: PageMeta;
};
