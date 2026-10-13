import { Form, Head, Link, router } from '@inertiajs/react';
import ChoosePasswordController from '@/actions/App/Http/Controllers/Auth/ChoosePasswordController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

type Props = {
    passwordRules: string;
};

// Same field label style as the login panel.
const labelClass = 'text-xs font-semibold tracking-wide text-muted-foreground';

/**
 * "Choose your new password" (planning/rbac.md). Shown right after logging
 * in with a temporary password set by Admin, System Admin or IT. Until the
 * user saves a new one, this page and Log out are the only things they can
 * open (app/Http/Middleware/RequirePasswordChange.php).
 */
export default function ChoosePassword({ passwordRules }: Props) {
    return (
        <>
            <Head title="Choose your new password" />

            <Form
                {...ChoosePasswordController.update.form()}
                resetOnError
                resetOnSuccess
                className="grid gap-3.5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-1">
                            <Label htmlFor="password" className={labelClass}>
                                New password
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                autoFocus
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                                aria-invalid={!!errors.password}
                            />
                            <InputError
                                message={errors.password}
                                className="text-xs"
                            />
                        </div>

                        <div className="grid gap-1">
                            <Label
                                htmlFor="password_confirmation"
                                className={labelClass}
                            >
                                Type it again
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="choose-password-button"
                        >
                            {processing && <Spinner />}
                            Save new password
                        </Button>

                        <Link
                            href={logout()}
                            as="button"
                            onClick={() => router.flushAll()}
                            className="cursor-pointer text-xs text-muted-foreground underline-offset-4 hover:underline"
                            data-test="logout-button"
                        >
                            Log out
                        </Link>
                    </>
                )}
            </Form>
        </>
    );
}

ChoosePassword.layout = {
    title: 'Choose your new password',
    description:
        'Your password was set by the Admin or System Admin. Choose your own before you continue: at least 8 characters, with letters and numbers.',
};
