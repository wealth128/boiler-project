import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import UserAccountController from '@/actions/App/Http/Controllers/Users/UserAccountController';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import { Chip } from '@/components/shared/chip';
import { DataTable } from '@/components/shared/data-table';
import type { DataTableColumn } from '@/components/shared/data-table';
import { PageHeader } from '@/components/shared/page-header';
import type {
    DeleteReasonOption,
    DeleteReasonValue,
} from '@/components/shared/delete-reason-field';
import { ReasonConfirm } from '@/components/shared/reason-confirm';
import type { ReasonConfirmErrors } from '@/components/shared/reason-confirm';
import { Button } from '@/components/ui/button';
import { DeactivateDialog } from '@/features/users/deactivate-dialog';
import { ResetPasswordDialog } from '@/features/users/reset-password-dialog';
import type { OfficeOption, RoleOption, UserRow } from '@/features/users/types';
import { UserFormDialog } from '@/features/users/user-form-dialog';
import { usePermissions } from '@/hooks/use-permissions';

type Props = {
    users: UserRow[];
    roles: RoleOption[];
    encoderOffices: OfficeOption[];
    maxAttempts: number;
    passwordRules: string;
    /** UserDeleteReason choices. */
    deleteReasons: DeleteReasonOption[];
};

const actionClass = 'h-auto px-1.5 py-0.5 text-xs';

/**
 * User Accounts (prototype/prototype.html, "User accounts"). Admin and
 * System Admin add, edit, unlock, reset, activate / deactivate and move
 * accounts to Trash. The server decides which buttons each row gets
 * (UserRow.can, from UserPolicy) and checks again on every action.
 */
export default function UsersIndex({
    users,
    roles,
    encoderOffices,
    maxAttempts,
    passwordRules,
    deleteReasons,
}: Props) {
    const { is } = usePermissions();

    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<UserRow | null>(null);
    const [resetting, setResetting] = useState<UserRow | null>(null);
    const [deactivating, setDeactivating] = useState<UserRow | null>(null);
    const [trashing, setTrashing] = useState<UserRow | null>(null);
    const [processing, setProcessing] = useState(false);
    const [trashErrors, setTrashErrors] = useState<ReasonConfirmErrors>({});

    const roleLabel = (value: UserRow['role']) =>
        roles.find((role) => role.value === value)?.label ?? value;

    const post = (url: string, onSuccess?: () => void) =>
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess,
            },
        );

    function toggleActive(user: UserRow) {
        if (user.is_active) {
            setDeactivating(user);

            return;
        }

        post(UserAccountController.toggleActive.url(user.id));
    }

    function moveToTrash(reason: DeleteReasonValue) {
        if (!trashing) {
            return;
        }

        router.delete(UserController.destroy.url(trashing.id), {
            data: reason,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => setTrashing(null),
            onError: (errors) => setTrashErrors(errors),
        });
    }

    const columns: DataTableColumn<UserRow>[] = [
        {
            key: 'name',
            header: 'Name',
            cell: (user) => (
                <>
                    {user.name}
                    {user.is_self && (
                        <span className="ml-1.5 text-xs text-muted-foreground">
                            (you)
                        </span>
                    )}
                </>
            ),
        },
        {
            key: 'username',
            header: 'Username',
            cell: (user) => user.username,
            className: 'font-mono',
        },
        { key: 'role', header: 'Role', cell: (user) => roleLabel(user.role) },
        { key: 'office', header: 'Office', cell: (user) => user.office },
        {
            key: 'status',
            header: 'Status',
            cell: (user) => (
                <div className="flex flex-wrap items-center gap-1.5">
                    {user.is_locked ? (
                        <>
                            <Chip tone="bad">Locked</Chip>
                            <span className="text-xs text-muted-foreground">
                                {maxAttempts} wrong passwords
                            </span>
                        </>
                    ) : user.is_active ? (
                        <Chip tone="ok">Active</Chip>
                    ) : (
                        <Chip>Off</Chip>
                    )}
                    {user.must_change_password && (
                        <Chip tone="warn">Temporary password</Chip>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            cell: (user) => (
                <div className="flex flex-nowrap gap-1">
                    {user.can.update && (
                        <Button
                            variant="link"
                            size="sm"
                            className={actionClass}
                            onClick={() => setEditing(user)}
                        >
                            Edit
                        </Button>
                    )}
                    {user.can.unlock && (
                        <Button
                            variant="link"
                            size="sm"
                            className={actionClass}
                            disabled={processing}
                            onClick={() =>
                                post(UserAccountController.unlock.url(user.id))
                            }
                        >
                            Unlock
                        </Button>
                    )}
                    {user.can.reset_password && (
                        <Button
                            variant="link"
                            size="sm"
                            className={actionClass}
                            onClick={() => setResetting(user)}
                        >
                            Reset password
                        </Button>
                    )}
                    {user.can.toggle_active && (
                        <Button
                            variant="link"
                            size="sm"
                            className={actionClass}
                            disabled={processing}
                            onClick={() => toggleActive(user)}
                        >
                            {user.is_active ? 'Deactivate' : 'Activate'}
                        </Button>
                    )}
                    {user.can.delete && (
                        <Button
                            variant="link"
                            size="sm"
                            className={`${actionClass} text-bad`}
                            onClick={() => {
                                setTrashErrors({});
                                setTrashing(user);
                            }}
                        >
                            Delete
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="User Accounts" />

            <PageHeader
                title="User Accounts"
                description={
                    is('system_admin')
                        ? 'System Admin manages accounts only. No access to patient data or reports.'
                        : undefined
                }
            />

            <section className="rounded-lg border bg-card">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b px-4 py-3">
                    <h2 className="font-semibold">User accounts</h2>
                    <span className="text-xs text-muted-foreground">
                        Only one Admin and one System Admin account are allowed.
                        Accounts lock after {maxAttempts} wrong passwords.
                    </span>
                    <Button
                        size="sm"
                        className="ml-auto"
                        onClick={() => setAdding(true)}
                        data-test="add-user-button"
                    >
                        Add user
                    </Button>
                </div>

                <DataTable
                    columns={columns}
                    rows={users}
                    rowKey={(user) => user.id}
                    caption="User accounts"
                />
            </section>

            <UserFormDialog
                open={adding || editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setAdding(false);
                        setEditing(null);
                    }
                }}
                user={editing ?? undefined}
                roles={roles}
                encoderOffices={encoderOffices}
                passwordRules={passwordRules}
            />

            <ResetPasswordDialog
                user={resetting}
                onClose={() => setResetting(null)}
                passwordRules={passwordRules}
            />

            <DeactivateDialog
                user={deactivating}
                onClose={() => setDeactivating(null)}
                onConfirm={(user) =>
                    post(UserAccountController.toggleActive.url(user.id), () =>
                        setDeactivating(null),
                    )
                }
                processing={processing}
            />

            <ReasonConfirm
                open={trashing !== null}
                onOpenChange={(open) => !open && setTrashing(null)}
                description={
                    trashing
                        ? `${trashing.name} (${trashing.username}). The account can't log in while it is in Trash. Admin can restore it from Trash.`
                        : undefined
                }
                reasons={deleteReasons}
                onConfirm={moveToTrash}
                processing={processing}
                errors={trashErrors}
            />
        </>
    );
}
