import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import TrashController from "@/actions/App/Http/Controllers/Trash/TrashController";
import { DataTable } from "@/components/shared/data-table";
import type { DataTableColumn } from "@/components/shared/data-table";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { Pagination } from "@/components/shared/pagination";
import { Button } from "@/components/ui/button";
import { PurgeRow } from "@/features/trash/purge-row";
import type { TrashItem } from "@/features/trash/types";
import { formatDateTime } from "@/lib/dates";
import { formatCount } from "@/lib/format";
import { pagingQuery, showsPaging } from "@/lib/paging";
import type { Paged } from "@/types";

type Props = {
    items: Paged<TrashItem>;
};

/** Typed to confirm Empty Trash (TrashController::EMPTY_PHRASE). */
const EMPTY_PHRASE = "EMPTY TRASH";

/** Which row has its "Delete permanently?" question open, or the whole of Trash. */
type Purging = { kind: "item"; key: string } | { kind: "all" } | null;

const actionClass = "h-auto px-1.5 py-0.5 text-xs";

/**
 * Trash (prototype/prototype.html, trashView). Admin only. Restore is one
 * click. Delete permanently and Empty Trash ask for the ID (or EMPTY TRASH)
 * to be typed in a row. A refusal from the server shows in the row it
 * belongs to.
 */
export default function TrashIndex({ items }: Props) {
    const page = items.meta.current_page;
    const perPage = items.meta.per_page;
    const total = items.meta.total;
    const [purging, setPurging] = useState<Purging>(null);
    const [processing, setProcessing] = useState(false);
    /** Message for one row (a refused Restore or Delete permanently). */
    const [refusal, setRefusal] = useState<{
        key: string;
        message: string;
    } | null>(null);
    /** Message for the open "Delete permanently?" row. */
    const [purgeError, setPurgeError] = useState<string | undefined>();

    function reset() {
        setRefusal(null);
        setPurgeError(undefined);
    }

    function restore(item: TrashItem) {
        reset();
        setPurging(null);

        router.post(
            TrashController.restore.url({ type: item.type, id: item.id }),
            { page, per_page: perPage },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) =>
                    setRefusal({
                        key: item.key,
                        message:
                            errors.item ??
                            Object.values(errors)[0] ??
                            "This item can't be restored.",
                    }),
            },
        );
    }

    function startPurge(next: Purging) {
        reset();
        setPurging(next);
    }

    function cancelPurge() {
        const was = purging;

        reset();
        setPurging(null);
        window.setTimeout(() =>
            document
                .getElementById(
                    was?.kind === "item" ? `purge-${was.key}` : "empty-trash",
                )
                ?.focus(),
        );
    }

    function purge(item: TrashItem, typed: string) {
        setPurgeError(undefined);

        router.delete(
            TrashController.destroy.url({ type: item.type, id: item.id }),
            {
                data: { confirm: typed, page, per_page: perPage },
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => setPurging(null),
                onError: (errors) =>
                    setPurgeError(
                        errors.confirm ??
                            errors.item ??
                            "This item can't be deleted permanently.",
                    ),
            },
        );
    }

    function emptyTrash(typed: string) {
        setPurgeError(undefined);

        router.delete(TrashController.empty.url(), {
            data: { confirm: typed, per_page: perPage },
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => setPurging(null),
            onError: (errors) =>
                setPurgeError(errors.confirm ?? Object.values(errors)[0]),
        });
    }

    const columns: DataTableColumn<TrashItem>[] = [
        {
            key: "type",
            header: "Type",
            cell: (item) => item.type_label,
            className: "whitespace-nowrap",
        },
        { key: "item", header: "Item", cell: (item) => item.item },
        { key: "reason", header: "Reason", cell: (item) => item.reason },
        {
            key: "deleted_by",
            header: "Deleted by",
            cell: (item) => item.deleted_by,
            className: "font-mono",
        },
        {
            key: "deleted_at",
            header: "When",
            cell: (item) => formatDateTime(item.deleted_at),
            className: "font-mono whitespace-nowrap",
        },
        {
            key: "actions",
            header: <span className="sr-only">Actions</span>,
            cell: (item) => (
                <>
                    <div className="flex flex-nowrap items-center gap-1">
                        <Button
                            variant="link"
                            size="sm"
                            className={actionClass}
                            onClick={() => restore(item)}
                            disabled={processing}
                            aria-label={`Restore ${item.type_label} ${item.item}`}
                        >
                            Restore
                        </Button>
                        {item.blocked ? (
                            <span
                                className="text-xs whitespace-nowrap text-muted-foreground"
                                title={item.blocked}
                            >
                                Can&apos;t delete permanently
                            </span>
                        ) : (
                            <Button
                                id={`purge-${item.key}`}
                                variant="link"
                                size="sm"
                                className={`${actionClass} text-bad`}
                                onClick={() =>
                                    startPurge({ kind: "item", key: item.key })
                                }
                                disabled={processing}
                                aria-label={`Delete ${item.type_label} ${item.item} permanently`}
                            >
                                Delete permanently
                            </Button>
                        )}
                    </div>
                    {item.blocked && (
                        <div className="mt-0.5 max-w-64 text-xs text-muted-foreground">
                            {item.blocked}
                        </div>
                    )}
                </>
            ),
        },
    ];

    const isPurging = (item: TrashItem) =>
        purging?.kind === "item" && purging.key === item.key;

    return (
        <>
            <Head title="Trash" />

            <PageHeader
                title="Trash"
                description="Deleted items wait here until Admin restores them or deletes them permanently. Nothing is emptied automatically."
            />

            <section className="rounded-lg border bg-card">
                <div className="flex items-center gap-3 border-b px-4 py-3">
                    <h2 className="text-base font-semibold">
                        {formatCount(total)} {total === 1 ? "item" : "items"}
                    </h2>
                    {total > 0 && (
                        <Button
                            id="empty-trash"
                            variant="destructive"
                            size="sm"
                            className="ml-auto"
                            onClick={() => startPurge({ kind: "all" })}
                            disabled={processing}
                        >
                            Empty Trash
                        </Button>
                    )}
                </div>

                {total === 0 ? (
                    <EmptyState message="Trash is empty." />
                ) : (
                    <>
                        {purging?.kind === "all" && (
                            <PurgeRow
                                question="Empty Trash?"
                                confirmText={EMPTY_PHRASE}
                                onConfirm={emptyTrash}
                                onCancel={cancelPurge}
                                processing={processing}
                                error={purgeError}
                                className="border-b"
                            />
                        )}
                        <DataTable
                            columns={columns}
                            rows={items.data}
                            rowKey={(item) => item.key}
                            caption="Items in Trash"
                            rowClassName={(item) =>
                                isPurging(item) ? "bg-bad-soft!" : undefined
                            }
                            rowDetail={(item) => {
                                if (isPurging(item)) {
                                    return (
                                        <PurgeRow
                                            question="Delete permanently?"
                                            confirmText={item.confirm_text}
                                            onConfirm={(typed) =>
                                                purge(item, typed)
                                            }
                                            onCancel={cancelPurge}
                                            processing={processing}
                                            error={purgeError}
                                            className="-mx-2.5 -my-2"
                                        />
                                    );
                                }

                                return refusal?.key === item.key ? (
                                    <p
                                        role="alert"
                                        className="text-sm font-medium text-bad"
                                    >
                                        {refusal.message}
                                    </p>
                                ) : null;
                            }}
                            footer={
                                showsPaging(items.meta) ? (
                                    <Pagination
                                        meta={items.meta}
                                        pageUrl={(next) =>
                                            TrashController.index.url({
                                                query: pagingQuery(
                                                    perPage,
                                                    next,
                                                ),
                                            })
                                        }
                                        perPageUrl={(size) =>
                                            TrashController.index.url({
                                                query: pagingQuery(size),
                                            })
                                        }
                                        noun="items"
                                    />
                                ) : undefined
                            }
                        />
                    </>
                )}
            </section>

            <p className="mt-3 text-sm text-muted-foreground">
                Items still used by visits, or visits in a closed month,
                can&apos;t be deleted permanently. They stay in Trash so past
                records and reports stay correct. Permanently deleted data still
                exists in backups until the backups rotate out.
            </p>
        </>
    );
}
