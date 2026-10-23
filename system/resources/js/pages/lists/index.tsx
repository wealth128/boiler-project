import { Head, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import ListController from '@/actions/App/Http/Controllers/Settings/ListController';
import { Chip } from '@/components/shared/chip';
import type {
    DeleteReasonOption,
    DeleteReasonValue,
} from '@/components/shared/delete-reason-field';
import { EmptyState } from '@/components/shared/empty-state';
import { MoveToTrashRow } from '@/components/shared/move-to-trash-row';
import type { MoveToTrashErrors } from '@/components/shared/move-to-trash-row';
import { PageHeader } from '@/components/shared/page-header';
import { AddBracketForm, AddNameForm } from '@/features/lists/add-forms';
import { Hint, ItemList, ListPanel } from '@/features/lists/list-panel';
import {
    BracketEditRow,
    ItemRow,
    NameEditRow,
} from '@/features/lists/list-rows';
import { checkName } from '@/features/lists/rules';
import type {
    ActiveRow,
    BracketItem,
    BranchWithRanks,
    ListKey,
    ListLimits,
    NameItem,
} from '@/features/lists/types';
import { cn } from '@/lib/utils';

type Props = {
    /** Active diagnoses, alphabetical, "Other (specify)" last. */
    diagnoses: NameItem[];
    /** Active age brackets by From age. */
    ageBrackets: BracketItem[];
    /** Every branch with its active ranks in seniority order. */
    branches: BranchWithRanks[];
    limits: ListLimits;
    /** ListItemDeleteReason choices. */
    deleteReasons: DeleteReasonOption[];
};

/**
 * Dropdown Lists (prototype/prototype.html, Admin Settings, "Dropdown
 * lists" tab). Admin only. Diagnoses, age brackets and ranks by branch:
 * add, fix the spelling, delete (to Trash), and for ranks Move up / Move
 * down to keep the seniority order. Only active items show.
 *
 * One row at a time is edited or deleted. Every check here is repeated on
 * the server (ListItemRequest), which also knows about names in Trash.
 */
export default function ListsIndex({
    diagnoses,
    ageBrackets,
    branches,
    limits,
    deleteReasons,
}: Props) {
    const [branchId, setBranchId] = useState<number | undefined>(
        branches[0]?.id,
    );
    const [active, setActive] = useState<ActiveRow>(null);
    const [trashErrors, setTrashErrors] = useState<MoveToTrashErrors>({});
    const [processing, setProcessing] = useState(false);
    const [moving, setMoving] = useState(false);

    const branch = branches.find((b) => b.id === branchId) ?? branches[0];
    const activeDiagnoses = diagnoses.filter((d) => !d.is_other).length;
    const hasOtherDiagnosis = diagnoses.some((d) => d.is_other);

    const isActive = (list: ListKey, id: number, mode: 'edit' | 'delete') =>
        active?.list === list && active.id === id && active.mode === mode;

    /** Close the open row and put the cursor back on its button. */
    function close(buttonId?: string) {
        setActive(null);

        if (buttonId) {
            window.setTimeout(() => document.getElementById(buttonId)?.focus());
        }
    }

    function open(list: ListKey, id: number, mode: 'edit' | 'delete') {
        setTrashErrors({});
        setActive({ list, id, mode });
    }

    function moveToTrash(reason: DeleteReasonValue) {
        if (!active) {
            return;
        }

        router.delete(
            ListController.destroy.url({ list: active.list, id: active.id }),
            {
                data: reason,
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => setActive(null),
                onError: (errors) => setTrashErrors(errors),
            },
        );
    }

    function move(rank: NameItem, direction: 'up' | 'down') {
        router.put(
            ListController.move.url(rank.id),
            { direction },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setMoving(true),
                onFinish: () => {
                    setMoving(false);
                    // Keep the cursor on the rank that moved. At the top or
                    // bottom that button is off, so use the other one.
                    window.setTimeout(() => {
                        const same = document.getElementById(
                            `ranks-${rank.id}-${direction}`,
                        ) as HTMLButtonElement | null;
                        const other = document.getElementById(
                            `ranks-${rank.id}-${direction === 'up' ? 'down' : 'up'}`,
                        );

                        (same && !same.disabled ? same : other)?.focus();
                    });
                },
            },
        );
    }

    /** The "Move to Trash?" row under an item. */
    function trashRow(list: ListKey, id: number): ReactNode {
        if (!isActive(list, id, 'delete')) {
            return null;
        }

        return (
            <li className="border-b px-2.5 py-2 last:border-b-0">
                <MoveToTrashRow
                    reasons={deleteReasons}
                    onConfirm={moveToTrash}
                    onCancel={() => close(`${list}-${id}-delete`)}
                    processing={processing}
                    errors={trashErrors}
                />
            </li>
        );
    }

    /** Rows for diagnoses or one branch's ranks. */
    function nameRows(
        list: 'diagnoses' | 'ranks',
        items: NameItem[],
        options: { listName: string; max: number; moveable: boolean },
    ) {
        const ordered = items.filter((item) => !item.is_other);

        return items.map((item) => {
            const rowId = `${list}-${item.id}`;

            if (isActive(list, item.id, 'edit')) {
                return (
                    <NameEditRow
                        key={item.id}
                        url={ListController.update.url({ list, id: item.id })}
                        initial={item.name}
                        label={item.name}
                        check={(name) =>
                            checkName(
                                name,
                                items.filter((other) => other.id !== item.id),
                                {
                                    limits,
                                    max: options.max,
                                    empty: "The name can't be empty.",
                                    listName: options.listName,
                                },
                            )
                        }
                        onDone={() => close(`${rowId}-edit`)}
                        onCancel={() => close(`${rowId}-edit`)}
                    />
                );
            }

            const position = ordered.findIndex((r) => r.id === item.id);

            return (
                <Fragment key={item.id}>
                    <ItemRow
                        rowId={rowId}
                        label={item.name}
                        fixed={item.is_other}
                        deleting={isActive(list, item.id, 'delete')}
                        move={
                            options.moveable
                                ? {
                                      canUp: position > 0,
                                      canDown: position < ordered.length - 1,
                                      busy: moving,
                                      onMove: (direction) =>
                                          move(item, direction),
                                  }
                                : undefined
                        }
                        onEdit={() => open(list, item.id, 'edit')}
                        onDelete={() => open(list, item.id, 'delete')}
                    />
                    {trashRow(list, item.id)}
                </Fragment>
            );
        });
    }

    return (
        <>
            <Head title="Dropdown Lists" />

            <PageHeader title="Dropdown Lists" />
            <p className="-mt-2 mb-3 text-sm text-muted-foreground">
                Deleted items move to Trash. They disappear from encoding but
                past visits and reports keep them. Restore them from Trash.
            </p>

            <div className="grid gap-4 md:grid-cols-2">
                <ListPanel
                    title="Diagnosis list"
                    aside={
                        <Chip>
                            {activeDiagnoses} active
                            {hasOtherDiagnosis ? ' + Other' : ''}
                        </Chip>
                    }
                >
                    {diagnoses.length > 0 ? (
                        <ItemList label="Diagnoses">
                            {nameRows('diagnoses', diagnoses, {
                                listName: 'the list',
                                max: limits.diagnosis_name_max,
                                moveable: false,
                            })}
                        </ItemList>
                    ) : (
                        <EmptyState message="No diagnoses yet." />
                    )}
                    <AddNameForm
                        url={ListController.store.url('diagnoses')}
                        placeholder="Add a diagnosis"
                        inputLabel="Add a diagnosis"
                        buttonLabel="Add"
                        check={(name) =>
                            checkName(name, diagnoses, {
                                limits,
                                max: limits.diagnosis_name_max,
                                empty: 'Type the diagnosis to add.',
                                listName: 'the list',
                            })
                        }
                    />
                </ListPanel>

                <ListPanel
                    title="Age brackets"
                    aside={<Chip>{ageBrackets.length} active</Chip>}
                >
                    {ageBrackets.length > 0 ? (
                        <ItemList label="Age brackets">
                            {ageBrackets.map((bracket) => {
                                const list = 'age-brackets';
                                const rowId = `${list}-${bracket.id}`;

                                if (isActive(list, bracket.id, 'edit')) {
                                    return (
                                        <BracketEditRow
                                            key={bracket.id}
                                            url={ListController.update.url({
                                                list,
                                                id: bracket.id,
                                            })}
                                            bracket={bracket}
                                            others={ageBrackets.filter(
                                                (b) => b.id !== bracket.id,
                                            )}
                                            limits={limits}
                                            onDone={() =>
                                                close(`${rowId}-edit`)
                                            }
                                            onCancel={() =>
                                                close(`${rowId}-edit`)
                                            }
                                        />
                                    );
                                }

                                return (
                                    <Fragment key={bracket.id}>
                                        <ItemRow
                                            rowId={rowId}
                                            label={bracket.label}
                                            numeric
                                            deleting={isActive(
                                                list,
                                                bracket.id,
                                                'delete',
                                            )}
                                            onEdit={() =>
                                                open(list, bracket.id, 'edit')
                                            }
                                            onDelete={() =>
                                                open(list, bracket.id, 'delete')
                                            }
                                        />
                                        {trashRow(list, bracket.id)}
                                    </Fragment>
                                );
                            })}
                        </ItemList>
                    ) : (
                        <EmptyState message="No age brackets yet." />
                    )}
                    <Hint>
                        Sample brackets. Replace with the hospital's official
                        ranges. To change ranges, edit or remove a bracket first
                        so they don't overlap.
                    </Hint>
                    <AddBracketForm
                        url={ListController.store.url('age-brackets')}
                        brackets={ageBrackets}
                        limits={limits}
                    />
                </ListPanel>

                <ListPanel
                    title="Ranks by branch"
                    className="md:col-span-2"
                    aside={
                        <span className="text-xs text-muted-foreground">
                            Draft from AFP standard ranks. Verify before
                            go-live.
                        </span>
                    }
                >
                    <div
                        role="group"
                        aria-label="Branch"
                        className="flex flex-wrap gap-1.5"
                    >
                        {branches.map((b) => (
                            <button
                                key={b.id}
                                type="button"
                                aria-pressed={b.id === branch?.id}
                                onClick={() => {
                                    setBranchId(b.id);
                                    setActive(null);
                                }}
                                className={cn(
                                    'rounded-full border px-3 py-1 text-sm font-semibold text-muted-foreground outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                    b.id === branch?.id &&
                                        'border-navy bg-navy text-navy-foreground',
                                )}
                            >
                                {b.is_afp
                                    ? b.name
                                    : `${b.name} (patient types)`}{' '}
                                · {b.ranks.length}
                            </button>
                        ))}
                    </div>

                    {branch && (
                        <>
                            <Hint>
                                Keep the seniority order with the up and down
                                arrows, highest first. Encode and the Branch ×
                                Rank report use this order.
                            </Hint>
                            {branch.ranks.length > 0 ? (
                                <ItemList label={`${branch.name} ranks`}>
                                    {nameRows('ranks', branch.ranks, {
                                        listName: `the ${branch.name} list`,
                                        max: limits.rank_name_max,
                                        moveable: true,
                                    })}
                                </ItemList>
                            ) : (
                                <EmptyState
                                    message={`No ranks in ${branch.name} yet.`}
                                />
                            )}
                            <AddNameForm
                                key={branch.id}
                                url={ListController.store.url('ranks')}
                                extra={{ branch_id: branch.id }}
                                placeholder={`Add to ${branch.name}, e.g. ${branch.is_afp ? 'CPO' : 'Dependent'}`}
                                inputLabel={`Add a rank to ${branch.name}`}
                                buttonLabel={`Add to ${branch.name}`}
                                check={(name) =>
                                    checkName(name, branch.ranks, {
                                        limits,
                                        max: limits.rank_name_max,
                                        empty: 'Type the rank or patient type to add.',
                                        listName: `the ${branch.name} list`,
                                    })
                                }
                            />
                        </>
                    )}
                </ListPanel>
            </div>
        </>
    );
}
