import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { Office, Role } from '@/types';
import type { OfficeOption, RoleOption, UserRow } from './types';

type UserFormDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The account to edit. Leave out to add a new one. */
    user?: UserRow;
    roles: RoleOption[];
    encoderOffices: OfficeOption[];
    passwordRules: string;
};

/**
 * "Add user" and "Edit user". Adding sets a temporary password: the user
 * must choose their own right after logging in. Editing never touches the
 * password (use "Reset password").
 */
export function UserFormDialog({
    open,
    onOpenChange,
    ...props
}: UserFormDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                {/* Mounted only while open, so each opening starts fresh. */}
                <UserForm {...props} onDone={() => onOpenChange(false)} />
            </DialogContent>
        </Dialog>
    );
}

function UserForm({
    user,
    roles,
    encoderOffices,
    passwordRules,
    onDone,
}: Omit<UserFormDialogProps, 'open' | 'onOpenChange'> & {
    onDone: () => void;
}) {
    const editing = user !== undefined;
    const firstOffice = encoderOffices.find((office) => office.available);

    const form = useForm<{
        name: string;
        username: string;
        role: Role;
        office: Office | '';
        password: string;
        password_confirmation: string;
    }>({
        name: user?.name ?? '',
        username: user?.username ?? '',
        role: user?.role ?? 'encoder',
        office: user?.office ?? firstOffice?.value ?? '',
        password: '',
        password_confirmation: '',
    });

    const role = roles.find((option) => option.value === form.data.role);
    const roleLocked = editing && !user.can.change_role;

    function changeRole(value: string) {
        const next = roles.find((option) => option.value === value);

        form.setData((data) => ({
            ...data,
            role: value as Role,
            // Other roles have a fixed office. Back to encoder: pick ER.
            office:
                next?.office ??
                (user?.role === 'encoder' ? user.office : firstOffice?.value) ??
                '',
        }));
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onDone };

        if (editing) {
            form.put(UserController.update.url(user.id), options);
        } else {
            form.post(UserController.store.url(), options);
        }
    }

    return (
        <form onSubmit={submit} noValidate className="grid gap-4">
            <DialogHeader>
                <DialogTitle>
                    {editing ? `Edit ${user.username}` : 'Add user'}
                </DialogTitle>
                <DialogDescription>
                    {editing
                        ? 'Name, username, role and office. To change the password, use Reset password.'
                        : 'The password you set is temporary. The user must choose their own right after logging in.'}
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-1.5">
                <Label htmlFor="user-name">Name</Label>
                <Input
                    id="user-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    maxLength={100}
                    placeholder="e.g. Cruz, Joanna"
                    autoComplete="off"
                    autoFocus
                    aria-invalid={form.errors.name ? true : undefined}
                />
                <InputError message={form.errors.name} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="user-username">Username</Label>
                <Input
                    id="user-username"
                    value={form.data.username}
                    onChange={(event) =>
                        form.setData(
                            'username',
                            event.target.value.toLowerCase(),
                        )
                    }
                    maxLength={50}
                    placeholder="e.g. er.cruz"
                    autoComplete="off"
                    autoCapitalize="none"
                    spellCheck={false}
                    className="font-mono"
                    aria-invalid={form.errors.username ? true : undefined}
                />
                <InputError message={form.errors.username} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="user-role">Role</Label>
                    <Select
                        value={form.data.role}
                        onValueChange={changeRole}
                        disabled={roleLocked}
                    >
                        <SelectTrigger
                            id="user-role"
                            className="w-full"
                            aria-invalid={form.errors.role ? true : undefined}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {roles.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {roleLocked && (
                        <p className="text-xs text-muted-foreground">
                            {user.is_self
                                ? "You can't change your own role."
                                : "You can't change this account's role."}
                        </p>
                    )}
                    <InputError message={form.errors.role} />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="user-office">Office</Label>
                    {role?.office ? (
                        <Input
                            id="user-office"
                            value={role.office}
                            readOnly
                            disabled
                        />
                    ) : (
                        <Select
                            value={form.data.office}
                            onValueChange={(value) =>
                                form.setData('office', value as Office)
                            }
                        >
                            <SelectTrigger
                                id="user-office"
                                className="w-full"
                                aria-invalid={
                                    form.errors.office ? true : undefined
                                }
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {encoderOffices.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                        disabled={
                                            !option.available &&
                                            option.value !== user?.office
                                        }
                                    >
                                        {option.value}
                                        {!option.available && ' (off in v1.0)'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <InputError message={form.errors.office} />
                </div>
            </div>

            {!editing && (
                <>
                    <div className="grid gap-1.5">
                        <Label htmlFor="user-password">
                            Temporary password
                        </Label>
                        <PasswordInput
                            id="user-password"
                            value={form.data.password}
                            onChange={(event) =>
                                form.setData('password', event.target.value)
                            }
                            autoComplete="new-password"
                            passwordrules={passwordRules}
                            aria-invalid={
                                form.errors.password ? true : undefined
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            At least 8 characters, with letters and numbers.
                        </p>
                        <InputError message={form.errors.password} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="user-password-confirmation">
                            Type it again
                        </Label>
                        <PasswordInput
                            id="user-password-confirmation"
                            value={form.data.password_confirmation}
                            onChange={(event) =>
                                form.setData(
                                    'password_confirmation',
                                    event.target.value,
                                )
                            }
                            autoComplete="new-password"
                            passwordrules={passwordRules}
                        />
                    </div>
                </>
            )}

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onDone}
                    disabled={form.processing}
                >
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {editing ? 'Save' : 'Add user'}
                </Button>
            </DialogFooter>
        </form>
    );
}
