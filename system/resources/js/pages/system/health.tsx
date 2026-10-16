import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { DataTable } from '@/components/shared/data-table';
import type { DataTableColumn } from '@/components/shared/data-table';
import { Chip } from '@/components/shared/chip';
import type { ChipTone } from '@/components/shared/chip';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import type { HealthCheck, HealthStatus } from '@/features/system/types';
import { formatDateTime } from '@/lib/dates';

type Props = {
    checks: HealthCheck[];
    overall: HealthStatus;
    checked_at: string;
};

const LABEL: Record<HealthStatus, string> = {
    ok: 'OK',
    warning: 'Warning',
    problem: 'Problem',
};

const TONE: Record<HealthStatus, ChipTone> = {
    ok: 'ok',
    warning: 'warn',
    problem: 'bad',
};

const SUMMARY: Record<HealthStatus, string> = {
    ok: 'Everything is working.',
    warning: 'The system works, but something needs attention soon.',
    problem: 'Something is wrong. Tell IT.',
};

/**
 * System health (Admin only): each check as OK, Warning or Problem with one
 * plain "What to do" line. Nothing here shows passwords or settings. IT can
 * run the same checks with "php artisan census:health".
 */
export default function SystemHealth({ checks, overall, checked_at }: Props) {
    const [checking, setChecking] = useState(false);

    const columns: DataTableColumn<HealthCheck>[] = [
        {
            key: 'check',
            header: 'Check',
            cell: (check) => <span className="font-medium">{check.label}</span>,
        },
        {
            key: 'status',
            header: 'Result',
            cell: (check) => (
                <Chip tone={TONE[check.status]}>{LABEL[check.status]}</Chip>
            ),
        },
        {
            key: 'detail',
            header: 'What we found',
            // Plain text: React escapes it.
            cell: (check) => check.detail,
        },
        {
            key: 'todo',
            header: 'What to do',
            cell: (check) => check.todo,
        },
    ];

    function checkAgain() {
        router.reload({
            onStart: () => setChecking(true),
            onFinish: () => setChecking(false),
        });
    }

    return (
        <>
            <Head title="System health" />

            <PageHeader
                title="System health"
                description="Is the system able to keep patient records safe? Admin only."
                actions={
                    <Button
                        type="button"
                        variant="outline"
                        onClick={checkAgain}
                        disabled={checking}
                    >
                        {checking ? 'Checking…' : 'Check again'}
                    </Button>
                }
            />

            <p
                role={overall === 'problem' ? 'alert' : 'status'}
                className="mb-4 flex flex-wrap items-center gap-2 rounded-lg border bg-card px-4 py-3 text-sm"
            >
                <Chip tone={TONE[overall]}>{LABEL[overall]}</Chip>
                <span className="font-medium">{SUMMARY[overall]}</span>
                <span className="text-muted-foreground">
                    Checked {formatDateTime(checked_at)}
                </span>
            </p>

            <section className="rounded-lg border bg-card">
                <DataTable
                    columns={columns}
                    rows={checks}
                    rowKey={(check) => check.key}
                    caption="System health checks"
                />
            </section>

            <p className="mt-3 text-sm text-muted-foreground">
                IT can run the same checks on the server with{' '}
                <span className="font-mono">php artisan census:health</span>.
            </p>
        </>
    );
}
