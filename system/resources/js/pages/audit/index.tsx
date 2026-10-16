import { Head, router } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import AuditLogController from "@/actions/App/Http/Controllers/Audit/AuditLogController";
import { DataTable } from "@/components/shared/data-table";
import type { DataTableColumn } from "@/components/shared/data-table";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { Pagination } from "@/components/shared/pagination";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import type {
    AuditActionGroup,
    AuditEntry,
    AuditFilters,
    AuditUserOption,
} from "@/features/audit/types";
import { formatDateTime } from "@/lib/dates";
import { formatCount } from "@/lib/format";
import { DEFAULT_PER_PAGE, pagingQuery, showsPaging } from "@/lib/paging";
import type { Paged } from "@/types";

type Props = {
    filters: AuditFilters;
    actions: AuditActionGroup[];
    users: AuditUserOption[];
    entries: Paged<AuditEntry>;
};

/** Wait this long after the last change before searching. */
const SEARCH_DELAY_MS = 300;
const DATE_DELAY_MS = 400;

/**
 * The URL for these filters. From and To are always sent: an empty value
 * means "no limit", while a missing one means the default range.
 */
function auditUrl(
    filters: AuditFilters,
    page = 1,
    perPage = DEFAULT_PER_PAGE,
): string {
    return AuditLogController.index.url({
        query: {
            from: filters.from,
            to: filters.to,
            user: filters.user || undefined,
            action: filters.action || undefined,
            record: filters.record.trim() || undefined,
            ...pagingQuery(perPage, page),
        },
    });
}

/**
 * Audit log (prototype/prototype.html, "Audit log"). Admin only, read-only.
 * Filters are kept in the URL, so Refresh and Back keep them. Details opens
 * a row under the entry with each changed field as "Field: old → new".
 */
export default function AuditIndex({
    filters,
    actions,
    users,
    entries,
}: Props) {
    const [values, setValues] = useState<AuditFilters>(filters);
    const [open, setOpen] = useState<number | null>(null);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    /** Change a filter, then load page 1 for the new filters. */
    function change(patch: Partial<AuditFilters>, delay = 0) {
        const next = { ...values, ...patch };

        setValues(next);
        setOpen(null);
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(
            () =>
                router.visit(auditUrl(next, 1, entries.meta.per_page), {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                }),
            delay,
        );
    }

    const columns: DataTableColumn<AuditEntry>[] = [
        {
            key: "when",
            header: "When",
            cell: (entry) => formatDateTime(entry.created_at),
            className: "font-mono whitespace-nowrap",
        },
        {
            key: "user",
            header: "User",
            cell: (entry) =>
                entry.username === null ? (
                    <span className="text-muted-foreground">System</span>
                ) : (
                    <>
                        <span className="font-mono">{entry.username}</span>
                        {entry.user_name && (
                            <div className="text-xs text-muted-foreground">
                                {entry.user_name}
                            </div>
                        )}
                    </>
                ),
        },
        {
            key: "action",
            header: "Action",
            cell: (entry) => (
                <>
                    {entry.action_label}
                    {entry.details.length > 0 && (
                        <Button
                            variant="link"
                            size="sm"
                            className="ml-2 h-auto px-1.5 py-0.5 text-xs"
                            aria-expanded={open === entry.id}
                            aria-controls={`audit-details-${entry.id}`}
                            onClick={() =>
                                setOpen(open === entry.id ? null : entry.id)
                            }
                        >
                            {open === entry.id ? "Hide details" : "Details"}
                        </Button>
                    )}
                </>
            ),
        },
        { key: "record", header: "Record", cell: (entry) => entry.record },
        {
            key: "pc",
            header: "PC",
            cell: (entry) => entry.ip_address ?? "—",
            className: "font-mono whitespace-nowrap",
        },
    ];

    const total = entries.meta.total;

    return (
        <>
            <Head title="Audit log" />

            <PageHeader
                title="Audit log"
                description="Who did what, and when. Read-only."
            />

            <section className="rounded-lg border bg-card">
                <div className="border-b px-4 py-3">
                    <h2 className="text-base font-semibold">
                        {formatCount(total)} {total === 1 ? "entry" : "entries"}
                    </h2>
                </div>

                <div className="grid gap-3 border-b p-4 sm:grid-cols-2 lg:grid-cols-[repeat(2,minmax(0,1fr))_minmax(0,1.3fr)_minmax(0,1.3fr)_minmax(0,1.3fr)]">
                    <div className="grid gap-1.5">
                        <Label htmlFor="audit-from">From</Label>
                        <Input
                            id="audit-from"
                            type="date"
                            value={values.from}
                            max={values.to || undefined}
                            onChange={(e) =>
                                change({ from: e.target.value }, DATE_DELAY_MS)
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="audit-to">To</Label>
                        <Input
                            id="audit-to"
                            type="date"
                            value={values.to}
                            min={values.from || undefined}
                            onChange={(e) =>
                                change({ to: e.target.value }, DATE_DELAY_MS)
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="audit-user">User</Label>
                        <NativeSelect
                            id="audit-user"
                            value={values.user}
                            onChange={(e) => change({ user: e.target.value })}
                        >
                            <option value="">All users</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="audit-action">Action</Label>
                        <NativeSelect
                            id="audit-action"
                            value={values.action}
                            onChange={(e) => change({ action: e.target.value })}
                        >
                            <option value="">All actions</option>
                            {actions.map((group) => (
                                <optgroup key={group.group} label={group.group}>
                                    {group.options.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="audit-record">Record</Label>
                        <Input
                            id="audit-record"
                            type="search"
                            value={values.record}
                            placeholder="Visit No., Patient ID or username"
                            maxLength={60}
                            autoComplete="off"
                            onChange={(e) =>
                                change(
                                    { record: e.target.value },
                                    SEARCH_DELAY_MS,
                                )
                            }
                        />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    rows={entries.data}
                    rowKey={(entry) => entry.id}
                    caption="Audit log entries"
                    empty={
                        <EmptyState message="No entries match these filters." />
                    }
                    rowDetail={(entry) =>
                        open === entry.id ? (
                            <ul
                                id={`audit-details-${entry.id}`}
                                className="grid gap-0.5 text-sm"
                            >
                                {entry.details.map((detail) => (
                                    <li key={detail.field}>
                                        <span className="font-medium">
                                            {detail.field}:
                                        </span>{" "}
                                        {detail.old !== null && (
                                            <>
                                                <span className="text-muted-foreground">
                                                    {detail.old}
                                                </span>{" "}
                                                →{" "}
                                            </>
                                        )}
                                        {detail.new}
                                    </li>
                                ))}
                            </ul>
                        ) : null
                    }
                    footer={
                        showsPaging(entries.meta) ? (
                            <Pagination
                                meta={entries.meta}
                                pageUrl={(page) =>
                                    auditUrl(
                                        filters,
                                        page,
                                        entries.meta.per_page,
                                    )
                                }
                                perPageUrl={(size) =>
                                    auditUrl(filters, 1, size)
                                }
                                noun="entries"
                            />
                        ) : undefined
                    }
                />
            </section>
        </>
    );
}
