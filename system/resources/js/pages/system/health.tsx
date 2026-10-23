import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { DataTable } from '@/components/shared/data-table';
import type { DataTableColumn } from '@/components/shared/data-table';
import { Chip } from '@/components/shared/chip';
import type { ChipTone } from '@/components/shared/chip';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/shared/pagination';
import { NativeSelect } from '@/components/ui/native-select';
import HealthController from '@/actions/App/Http/Controllers/System/HealthController';
import type {
    HealthCheck,
    HealthCounts,
    HealthFilter,
    HealthStatus,
} from '@/features/system/types';
import { formatDateTime } from '@/lib/dates';
import { DEFAULT_PER_PAGE, pagingQuery } from '@/lib/paging';
import type { Paged } from '@/types';

type Props = {
    /** Problems first, then Warnings, then OK; one page of them. */
    checks: Paged<HealthCheck>;
    /** Every check counted, whatever the filter. */
    counts: HealthCounts;
    filter: HealthFilter;
    overall: HealthStatus;
    checked_at: string;
};

const FILTER_LABEL: Record<HealthFilter, string> = {
    all: 'All',
    problem: 'Problems',
    warning: 'Warnings',
    ok: 'OK',
};

function healthUrl(
    filter: HealthFilter,
    page = 1,
    perPage = DEFAULT_PER_PAGE,
): string {
    return HealthController.url({
        query: {
            status: filter === 'all' ? undefined : filter,
            ...pagingQuery(perPage, page),
        },
    });
}

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

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
export default function SystemHealth({
    checks,
    counts,
    filter,
    overall,
    checked_at,
}: Props) {
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
                    <Button className="max-sm:w-full"
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
                <span className="font-medium">
                    {plural(counts.problem, 'problem', 'problems')},{' '}
                    {plural(counts.warning, 'warning', 'warnings')}
                </span>
                <span>{SUMMARY[overall]}</span>
                <span className="text-muted-foreground">
                    Checked {formatDateTime(checked_at)}
                </span>
            </p>

            <section className="rounded-lg border bg-card">
                <div className="flex flex-wrap items-center gap-2 border-b px-4 py-3">
                    <label className="flex items-center gap-2 text-sm">
                        Show
                        <NativeSelect
                            value={filter}
                            className="h-8 w-auto py-0"
                            onChange={(e) =>
                                router.visit(
                                    healthUrl(
                                        e.target.value as HealthFilter,
                                        1,
                                        checks.meta.per_page,
                                    ),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {(
                                Object.keys(FILTER_LABEL) as HealthFilter[]
                            ).map((value) => (
                                <option key={value} value={value}>
                                    {FILTER_LABEL[value]}
                                    {value === 'all'
                                        ? ` (${counts.total})`
                                        : ` (${counts[value]})`}
                                </option>
                            ))}
                        </NativeSelect>
                    </label>
                </div>
                <DataTable
                    columns={columns}
                    rows={checks.data}
                    rowKey={(check) => check.key}
                    caption="System health checks, problems first"
                    empty={
                        <EmptyState message="No checks match this filter." />
                    }
                    footer={
                        checks.meta.total > 0 ? (
                            <Pagination
                                meta={checks.meta}
                                pageUrl={(page) =>
                                    healthUrl(filter, page, checks.meta.per_page)
                                }
                                perPageUrl={(size) => healthUrl(filter, 1, size)}
                                noun="checks"
                                alwaysShow
                            />
                        ) : undefined
                    }
                />
            </section>

            <p className="mt-3 text-sm text-muted-foreground">
                IT can run the same checks on the server with{' '}
                <span className="font-mono">php artisan census:health</span>.
            </p>
        </>
    );
}
