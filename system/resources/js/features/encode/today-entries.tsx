import { Link } from '@inertiajs/react';
import EncodeController from '@/actions/App/Http/Controllers/Encode/EncodeController';
import { CategoryChip } from '@/components/shared/category-chip';
import { Chip } from '@/components/shared/chip';
import { EmptyState } from '@/components/shared/empty-state';
import { formatTime } from '@/lib/dates';
import { cn } from '@/lib/utils';
import type { TodayEntry } from './types';

type TodayEntriesProps = {
    entries: TodayEntry[];
    /** The visit open in the form, highlighted in the list. */
    editingId?: number;
};

/**
 * "My entries today": the signed-in user's visits saved today, newest
 * first. Visits in Trash don't show. Edit loads one into the form.
 */
export function TodayEntries({ entries, editingId }: TodayEntriesProps) {
    return (
        <aside className="rounded-lg border bg-card">
            <div className="flex items-center gap-2 border-b px-4 py-3">
                <h2 className="font-semibold">My entries today</h2>
                <Chip>{entries.length}</Chip>
            </div>

            {entries.length === 0 ? (
                <EmptyState message="No entries yet today. Saved patients appear here and can be edited until midnight." />
            ) : (
                <ul>
                    {entries.map((entry) => (
                        <li
                            key={entry.id}
                            aria-current={entry.id === editingId || undefined}
                            className={cn(
                                'grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-2.5 gap-y-0.5 border-b px-4 py-2.5 last:border-b-0',
                                entry.id === editingId && 'bg-warn-soft',
                            )}
                        >
                            <span className="font-mono text-xs text-muted-foreground">
                                {formatTime(entry.visited_at)}
                            </span>
                            <span className="truncate text-sm font-semibold">
                                {entry.patient_name}
                            </span>
                            <CategoryChip category={entry.category} />
                            <span className="col-start-2 truncate text-xs text-muted-foreground">
                                {entry.diagnosis}
                            </span>
                            <span className="col-start-3 justify-self-end">
                                {entry.can_edit && entry.id !== editingId && (
                                    <Link
                                        href={EncodeController.index.url({
                                            query: { edit: entry.id },
                                        })}
                                        preserveScroll
                                        className="text-xs font-medium text-navy underline-offset-4 hover:underline"
                                        aria-label={`Edit ${entry.visit_no}, ${entry.patient_name}`}
                                    >
                                        Edit
                                    </Link>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </aside>
    );
}
