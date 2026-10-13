import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import UserAccountController from '@/actions/App/Http/Controllers/Users/UserAccountController';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { UserRow } from './types';

type ResetPasswordDialogProps = {
    /** The account to reset, or null when closed. */
    user: UserRow | null;
    onClose: () => void;
    passwordRules: string;
};

/**
 * "Reset password" (planning/rbac.md): the Admin or System Admin first
 * re-enters their own password, then sets a temporary one. The user must
 * choose their own right after logging in with it. A login lock is cleared
 * too.
 */
export function ResetPasswordDialog({
    user,
    onClose,
    passwordRules,
}: ResetPasswordDialogProps) {
    return (
        <Dialog
            open={user !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                {user && (
                    <ResetPasswordForm
                        user={user}
                        onDone={onClose}
                        passwordRules={passwordRules}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function ResetPasswordForm({
    user,
    onDone,
    passwordRules,
}: {
    user: UserRow;
    onDone: () => void;
    passwordRules: string;
}) {
    const form = useForm({
        password: '',
        password_confirmation: '',
        own_password: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        form.post(UserAccountController.resetPassword.url(user.id), {
            preserveScroll: true,
            onSuccess: onDone,
            onError: () => form.reset('own_password'),
        });
    }

    return (
        <form onSubmit={submit} noValidate className="grid gap-4">
            <DialogHeader>
                <DialogTitle>Reset password of {user.username}</DialogTitle>
                <DialogDescription>
                    The new password is temporary. {user.name} must choose their
                    own right after logging in.
                    {user.is_locked && ' This also unlocks the account.'}
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-1.5">
                <Label htmlFor="reset-password">Temporary password</Label>
                <PasswordInput
                    id="reset-password"
                    value={form.data.password}
                    onChange={(event) =>
                        form.setData('password', event.target.value)
                    }
                    autoComplete="new-password"
                    passwordrules={passwordRules}
                    autoFocus
                    aria-invalid={form.errors.password ? true : undefined}
                />
                <p className="text-xs text-muted-foreground">
                    At least 8 characters, with letters and numbers.
                </p>
                <InputError message={form.errors.password} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="reset-password-confirmation">
                    Type it again
                </Label>
                <PasswordInput
                    id="reset-password-confirmation"
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

            <div className="grid gap-1.5 border-t pt-4">
                <Label htmlFor="reset-own-password">
                    Your own password, to confirm
                </Label>
                <PasswordInput
                    id="reset-own-password"
                    value={form.data.own_password}
                    onChange={(event) =>
                        form.setData('own_password', event.target.value)
                    }
                    autoComplete="current-password"
                    aria-invalid={form.errors.own_password ? true : undefined}
                />
                <InputError message={form.errors.own_password} />
            </div>

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
                    Reset password
                </Button>
            </DialogFooter>
        </form>
    );
}
