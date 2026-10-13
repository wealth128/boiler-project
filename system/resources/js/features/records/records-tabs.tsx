import { Link } from '@inertiajs/react';
import { useEffect } from 'react';
import PatientRecordController from '@/actions/App/Http/Controllers/Records/PatientRecordController';
import VisitRecordController from '@/actions/App/Http/Controllers/Records/VisitRecordController';
import { cn } from '@/lib/utils';

export type RecordsTab = 'visits' | 'patients';

/**
 * The last URL seen on each tab, with its filters and page. Switching tabs
 * and coming back keeps them, as in the prototype. Kept in memory only:
 * a full page reload starts from the defaults.
 */
const lastUrl: Record<RecordsTab, string> = {
    visits: VisitRecordController.index.url(),
    patients: PatientRecordController.index.url(),
};

type RecordsTabsProps = {
    current: RecordsTab;
    /** This page's URL, e.g. usePage().url. */
    url: string;
};

/**
 * "Visits" and "Patients" tabs of Patient Records. Each tab is its own page
 * (/records/visits, /records/patients), so the tabs are links.
 */
export function RecordsTabs({ current, url }: RecordsTabsProps) {
    useEffect(() => {
        lastUrl[current] = url;
    }, [current, url]);

    const tabs: { key: RecordsTab; label: string }[] = [
        { key: 'visits', label: 'Visits' },
        { key: 'patients', label: 'Patients' },
    ];

    return (
        <nav
            aria-label="Patient Records"
            className="mb-4 flex flex-wrap gap-1 border-b"
        >
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.key === current ? url : lastUrl[tab.key]}
                    aria-current={tab.key === current ? 'page' : undefined}
                    className={cn(
                        '-mb-px border-b-2 px-3.5 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-ring',
                        tab.key === current
                            ? 'border-navy text-navy'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )}
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}
