import { Head, Link, router, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import EncodeController from "@/actions/App/Http/Controllers/Encode/EncodeController";
import VisitRecordController from "@/actions/App/Http/Controllers/Records/VisitRecordController";
import { CategoryChip } from "@/components/shared/category-chip";
import { DataTable } from "@/components/shared/data-table";
import type {
    DeleteReasonOption,
    DeleteReasonValue,
} from "@/components/shared/delete-reason-field";
import type { DataTableColumn } from "@/components/shared/data-table";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { MoveToTrashRow } from "@/components/shared/move-to-trash-row";
import type { MoveToTrashErrors } from "@/components/shared/move-to-trash-row";
import { Pagination } from "@/components/shared/pagination";
import { RecordsTabs } from "@/features/records/records-tabs";
import type {
    BranchFilterOption,
    VisitFilters,
    VisitRecord,
} from "@/features/records/types";
import { formatDateTime } from "@/lib/dates";
import { formatCount } from "@/lib/format";
import { DEFAULT_PER_PAGE, pagingQuery, showsPaging } from "@/lib/paging";
import type { Category, Paged } from "@/types";

type Props = {
    filters: VisitFilters;
    categories: Category[];
    branches: BranchFilterOption[];
    visits: Paged<VisitRecord>;
    /** VisitDeleteReason choices. */
    deleteReasons: DeleteReasonOption[];
};

/** Wait this long after the last change before searching. */
const SEARCH_DELAY_MS = 300;
const DATE_DELAY_MS = 400;

const actionClass = "h-auto px-1.5 py-0.5 text-xs";

/**
 * The URL for these filters. From and To are always sent: an empty value
 * means "no limit", while a missing one means the default range.
 */
function visitsUrl(
    filters: VisitFilters,
    page = 1,
    perPage = DEFAULT_PER_PAGE,
): string {
    return VisitRecordController.index.url({
        query: {
            from: filters.from,
            to: filters.to,
            category: filters.category || undefined,
            branch: filters.branch || undefined,
            q: filters.q.trim() || undefined,
            ...pagingQuery(perPage, page),
        },
    });
}

/**
 * Patient Records, Visits tab (prototype/prototype.html, "Patient
 * Records"). Admin only. Filters are kept in the URL, so Refresh and Back
 * keep them. Edit opens the visit on the Encode screen and comes back here
 * afterwards. Delete asks for a reason in a row under the visit.
 */
export default function RecordsVisits({
    filters,
    categories,
    branches,
    visits,
    deleteReasons,
}: Props) {
    const { url } = usePage();
    const [values, setValues] = useState<VisitFilters>(filters);
    const [trashing, setTrashing] = useState<VisitRecord | null>(null);
    const [processing, setProcessing] = useState(false);
    const [trashErrors, setTrashErrors] = useState<MoveToTrashErrors>({});
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    /** Change a filter, then load page 1 for the new filters. */
    function change(patch: Partial<VisitFilters>, delay = 0) {
        const next = { ...values, ...patch };

        setValues(next);
        setTrashing(null);
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(
            () =>
                router.visit(visitsUrl(next, 1, visits.meta.per_page), {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                }),
            delay,
        );
    }

    function startTrash(visit: VisitRecord) {
        setTrashErrors({});
        setTrashing(visit);
    }

    function cancelTrash() {
        const id = trashing?.id;

        setTrashing(null);
        window.setTimeout(() =>
            document.getElementById(`delete-visit-${id}`)?.focus(),
        );
    }

    function moveToTrash(reason: DeleteReasonValue) {
        if (!trashing) {
            return;
        }

        router.delete(VisitRecordController.destroy.url(trashing.id), {
            data: reason,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => setTrashing(null),
            onError: (errors) => setTrashErrors(errors),
        });
    }

    const columns: DataTableColumn<VisitRecord>[] = [
        {
            key: "visited_at",
            header: "Date & time",
            cell: (visit) => formatDateTime(visit.visited_at),
            className: "font-mono whitespace-nowrap",
        },
        {
            key: "visit_no",
            header: "Visit",
            cell: (visit) => visit.visit_no,
            className: "font-mono whitespace-nowrap",
        },
        {
            key: "patient_no",
            header: "Patient ID",
            cell: (visit) => visit.patient_no,
            className: "font-mono whitespace-nowrap",
        },
        { key: "name", header: "Name", cell: (visit) => visit.patient_name },
        {
            key: "sex",
            header: "Sex",
            cell: (visit) => (
                <abbr title={visit.sex} className="no-underline">
                    {visit.sex.charAt(0)}
                </abbr>
            ),
        },
        {
            key: "age",
            header: "Age",
            cell: (visit) => visit.age,
            align: "right",
        },
        {
            key: "branch",
            header: "Branch / Rank",
            cell: (visit) => `${visit.branch} · ${visit.rank}`,
        },
        {
            key: "diagnosis",
            header: "Diagnosis",
            cell: (visit) => (
                <>
                    {visit.diagnosis}
                    {visit.remarks && (
                        <div className="text-xs text-muted-foreground">
                            {visit.remarks}
                        </div>
                    )}
                </>
            ),
        },
        {
            key: "category",
            header: "Category",
            cell: (visit) => <CategoryChip category={visit.category} />,
        },
        {
            key: "encoded_by",
            header: "Encoded by",
            cell: (visit) => visit.encoded_by,
        },
        {
            key: "actions",
            header: <span className="sr-only">Actions</span>,
            cell: (visit) =>
                visit.month_closed ? (
                    <span className="text-xs whitespace-nowrap text-muted-foreground">
                        Month closed
                    </span>
                ) : (
                    <div className="flex flex-nowrap gap-1">
                        <Button
                            asChild
                            variant="link"
                            size="sm"
                            className={actionClass}
                        >
                            <Link
                                href={EncodeController.index.url({
                                    query: { edit: visit.id, return: url },
                                })}
                                aria-label={`Edit ${visit.visit_no}, ${visit.patient_name}`}
                            >
                                Edit
                            </Link>
                        </Button>
                        <Button
                            id={`delete-visit-${visit.id}`}
                            variant="link"
                            size="sm"
                            className={`${actionClass} text-bad`}
                            onClick={() => startTrash(visit)}
                            aria-label={`Delete ${visit.visit_no}, ${visit.patient_name}`}
                        >
                            Delete
                        </Button>
                    </div>
                ),
        },
    ];

    const total = visits.meta.total;

    return (
        <>
            <Head title="Patient Records" />

            <PageHeader
                title="Patient Records"
                description={`${formatCount(total)} ${total === 1 ? "visit matches" : "visits match"}. Deleted visits go to Trash and are left out of reports.`}
            />

            <RecordsTabs current="visits" url={url} />

            <section className="rounded-lg border bg-card">
                <div className="grid gap-3 border-b p-4 sm:grid-cols-2 lg:grid-cols-[repeat(4,minmax(0,1fr))_minmax(0,1.5fr)]">
                    <div className="grid gap-1.5">
                        <Label htmlFor="records-from">From</Label>
                        <Input
                            id="records-from"
                            type="date"
                            value={values.from}
                            max={values.to || undefined}
                            onChange={(e) =>
                                change({ from: e.target.value }, DATE_DELAY_MS)
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="records-to">To</Label>
                        <Input
                            id="records-to"
                            type="date"
                            value={values.to}
                            min={values.from || undefined}
                            onChange={(e) =>
                                change({ to: e.target.value }, DATE_DELAY_MS)
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="records-category">Category</Label>
                        <NativeSelect
                            id="records-category"
                            value={values.category}
                            onChange={(e) =>
                                change({
                                    category: e.target.value as Category | "",
                                })
                            }
                        >
                            <option value="">All categories</option>
                            {categories.map((category) => (
                                <option key={category} value={category}>
                                    {category}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="records-branch">Branch</Label>
                        <NativeSelect
                            id="records-branch"
                            value={values.branch}
                            onChange={(e) => change({ branch: e.target.value })}
                        >
                            <option value="">All branches</option>
                            {branches.map((branch) => (
                                <option key={branch.id} value={branch.id}>
                                    {branch.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="records-search">Search</Label>
                        <Input
                            id="records-search"
                            type="search"
                            value={values.q}
                            placeholder="Name, Patient ID or Visit ID"
                            maxLength={60}
                            autoComplete="off"
                            onChange={(e) =>
                                change({ q: e.target.value }, SEARCH_DELAY_MS)
                            }
                        />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    rows={visits.data}
                    rowKey={(visit) => visit.id}
                    caption="Visits"
                    empty={
                        <EmptyState message="No visits match these filters." />
                    }
                    rowClassName={(visit) =>
                        visit.id === trashing?.id ? "bg-bad-soft" : undefined
                    }
                    rowDetail={(visit) =>
                        visit.id === trashing?.id ? (
                            <MoveToTrashRow
                                reasons={deleteReasons}
                                onConfirm={moveToTrash}
                                onCancel={cancelTrash}
                                processing={processing}
                                errors={trashErrors}
                            />
                        ) : null
                    }
                    footer={
                        showsPaging(visits.meta) ? (
                            <Pagination
                                meta={visits.meta}
                                pageUrl={(page) =>
                                    visitsUrl(
                                        filters,
                                        page,
                                        visits.meta.per_page,
                                    )
                                }
                                perPageUrl={(size) =>
                                    visitsUrl(filters, 1, size)
                                }
                                noun="visits"
                            />
                        ) : undefined
                    }
                />
            </section>
        </>
    );
}
