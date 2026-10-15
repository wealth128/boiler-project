import { router } from "@inertiajs/react";
import { useState } from "react";
import type { FormEvent } from "react";
import ReportController from "@/actions/App/Http/Controllers/Reports/ReportController";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { reportQuery } from "@/features/reports/report-query";
import type {
    PeriodType,
    ReportFilters,
    ReportOptions,
} from "@/features/reports/types";
import type { Category } from "@/types";

const TYPE_LABELS: Record<PeriodType, string> = {
    monthly: "Monthly",
    quarterly: "Quarterly",
    annual: "Annual",
    custom: "Custom range",
};

type ReportControlsProps = {
    filters: ReportFilters;
    options: ReportOptions;
};

/**
 * Period type, the matching period fields, Category and Generate. The choices
 * are only applied (and put in the URL) when Generate is pressed, or Enter is
 * pressed in a field, so Refresh and Back keep them.
 */
export function ReportControls({ filters, options }: ReportControlsProps) {
    const [values, setValues] = useState<ReportFilters>(filters);
    const [error, setError] = useState("");
    const [loading, setLoading] = useState(false);

    const set = (patch: Partial<ReportFilters>) => {
        setValues((current) => ({ ...current, ...patch }));
        setError("");
    };

    function generate(event: FormEvent) {
        event.preventDefault();

        if (values.type === "custom") {
            if (!values.from || !values.to) {
                setError("Choose both a From date and a To date.");

                return;
            }

            if (values.to < values.from) {
                setError("To can’t be before From.");

                return;
            }
        }

        router.visit(
            ReportController.index.url({ query: reportQuery(values) }),
            {
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
                preserveScroll: true,
            },
        );
    }

    return (
        <form
            onSubmit={generate}
            className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[repeat(4,minmax(0,1fr))_auto] lg:items-end"
            noValidate
        >
            <div className="grid gap-1.5">
                <Label htmlFor="report-type">Period type</Label>
                <NativeSelect
                    id="report-type"
                    value={values.type}
                    onChange={(e) =>
                        set({ type: e.target.value as PeriodType })
                    }
                >
                    {(Object.keys(TYPE_LABELS) as PeriodType[]).map((type) => (
                        <option key={type} value={type}>
                            {TYPE_LABELS[type]}
                        </option>
                    ))}
                </NativeSelect>
            </div>

            {values.type === "monthly" && (
                <>
                    <div className="grid gap-1.5">
                        <Label htmlFor="report-month">Month</Label>
                        <NativeSelect
                            id="report-month"
                            value={values.month}
                            onChange={(e) => set({ month: e.target.value })}
                        >
                            {options.months.map((month) => (
                                <option key={month.value} value={month.value}>
                                    {month.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="hidden lg:block" />
                </>
            )}

            {values.type === "quarterly" && (
                <>
                    <div className="grid gap-1.5">
                        <Label htmlFor="report-quarter">Quarter</Label>
                        <NativeSelect
                            id="report-quarter"
                            value={values.quarter}
                            onChange={(e) => set({ quarter: e.target.value })}
                        >
                            {["1", "2", "3", "4"].map((quarter) => (
                                <option key={quarter} value={quarter}>
                                    Q{quarter}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <YearSelect
                        value={values.year}
                        years={options.years}
                        onChange={(year) => set({ year })}
                    />
                </>
            )}

            {values.type === "annual" && (
                <>
                    <YearSelect
                        value={values.year}
                        years={options.years}
                        onChange={(year) => set({ year })}
                    />
                    <div className="hidden lg:block" />
                </>
            )}

            {values.type === "custom" && (
                <>
                    <div className="grid gap-1.5">
                        <Label htmlFor="report-from">From</Label>
                        <Input
                            id="report-from"
                            type="date"
                            value={values.from}
                            max={values.to || undefined}
                            aria-invalid={error ? true : undefined}
                            onChange={(e) => set({ from: e.target.value })}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="report-to">To</Label>
                        <Input
                            id="report-to"
                            type="date"
                            value={values.to}
                            min={values.from || undefined}
                            aria-invalid={error ? true : undefined}
                            onChange={(e) => set({ to: e.target.value })}
                        />
                    </div>
                </>
            )}

            <div className="grid gap-1.5">
                <Label htmlFor="report-category">Category</Label>
                <NativeSelect
                    id="report-category"
                    value={values.category}
                    onChange={(e) =>
                        set({ category: e.target.value as Category | "" })
                    }
                >
                    <option value="">All</option>
                    {options.categories.map((category) => (
                        <option key={category} value={category}>
                            {category}
                        </option>
                    ))}
                </NativeSelect>
            </div>

            <Button type="submit" disabled={loading}>
                Generate
            </Button>

            {error && (
                <p
                    role="alert"
                    className="text-sm text-bad sm:col-span-2 lg:col-span-full"
                >
                    {error}
                </p>
            )}
        </form>
    );
}

function YearSelect({
    value,
    years,
    onChange,
}: {
    value: string;
    years: string[];
    onChange: (year: string) => void;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor="report-year">Year</Label>
            <NativeSelect
                id="report-year"
                value={value}
                onChange={(e) => onChange(e.target.value)}
            >
                {years.map((year) => (
                    <option key={year} value={year}>
                        {year}
                    </option>
                ))}
            </NativeSelect>
        </div>
    );
}
