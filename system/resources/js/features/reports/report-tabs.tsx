import { router } from "@inertiajs/react";
import { useRef } from "react";
import type { KeyboardEvent } from "react";
import ReportController from "@/actions/App/Http/Controllers/Reports/ReportController";
import { reportQuery } from "@/features/reports/report-query";
import type { ReportFilters, ReportTab } from "@/features/reports/types";
import type { Category } from "@/types";
import type { ReportPageKey } from "@/features/reports/report-sheet";
import { cn } from "@/lib/utils";

/** The tabs, in the order shown. */
export const REPORT_TABS: { key: ReportTab; label: string }[] = [
    { key: "opd", label: "OPD" },
    { key: "er", label: "ER" },
    { key: "admission", label: "Admission" },
    { key: "summary", label: "Summary" },
];

/** The id of the panel the tabs control. */
export const REPORT_PANEL_ID = "report-panel";

export const tabId = (tab: ReportTab) => `report-tab-${tab}`;

/** The page of the report a tab shows. */
export function pageOfTab(tab: ReportTab): ReportPageKey {
    if (tab === "summary") {
        return "summary";
    }

    return REPORT_TABS.find((t) => t.key === tab)?.label as Category;
}

/**
 * OPD | ER | Admission | Summary. One category at a time, with the period
 * choices kept. The tab is in the URL (?category=er), so Refresh and Back
 * keep it.
 *
 * Keyboard: Tab goes to the open tab, Left / Right arrows move between tabs
 * (Home / End to the first / last), and Enter or Space opens the tab that has
 * the focus. Opening a tab loads the report again, so arrows only move the
 * focus.
 */
export function ReportTabs({ filters }: { filters: ReportFilters }) {
    const buttons = useRef<Record<string, HTMLButtonElement | null>>({});

    function open(tab: ReportTab) {
        if (tab === filters.category) {
            return;
        }

        router.visit(
            ReportController.index.url({
                query: reportQuery({ ...filters, category: tab }),
            }),
            { preserveScroll: true },
        );
    }

    function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
        const index = REPORT_TABS.findIndex((t) => t.key === document.activeElement?.getAttribute("data-tab"));
        const last = REPORT_TABS.length - 1;
        const target =
            event.key === "ArrowRight"
                ? (index + 1) % REPORT_TABS.length
                : event.key === "ArrowLeft"
                  ? (index - 1 + REPORT_TABS.length) % REPORT_TABS.length
                  : event.key === "Home"
                    ? 0
                    : event.key === "End"
                      ? last
                      : null;

        if (target !== null && index !== -1) {
            event.preventDefault();
            buttons.current[REPORT_TABS[target].key]?.focus();
        }
    }

    return (
        <div
            role="tablist"
            aria-label="Report category"
            onKeyDown={onKeyDown}
            className="mb-3 flex flex-wrap gap-1 border-b print:hidden"
        >
            {REPORT_TABS.map((tab) => {
                const selected = tab.key === filters.category;

                return (
                    <button
                        key={tab.key}
                        ref={(element) => {
                            buttons.current[tab.key] = element;
                        }}
                        type="button"
                        role="tab"
                        id={tabId(tab.key)}
                        data-tab={tab.key}
                        aria-selected={selected}
                        aria-controls={REPORT_PANEL_ID}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => open(tab.key)}
                        className={cn(
                            "-mb-px cursor-pointer rounded-t-md border-b-2 px-4 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring",
                            selected
                                ? "border-primary font-semibold text-primary"
                                : "border-transparent font-medium text-muted-foreground hover:bg-muted hover:text-foreground",
                        )}
                    >
                        {tab.label}
                    </button>
                );
            })}
        </div>
    );
}
