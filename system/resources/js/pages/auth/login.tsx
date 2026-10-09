import { Form, Head } from '@inertiajs/react';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';

type Props = {
    status?: string;
};

// Same field label style as the prototype's login panel.
const labelClass = 'text-xs font-semibold tracking-wide text-muted-foreground';

/**
 * Login by username (prototype/prototype.html, "Login"). The server sends
 * every refusal as the "username" error: wrong password with the attempts
 * left, locked, or deactivated (app/Actions/Fortify/AuthenticateUser.php).
 *
 * No sign-up and no "Forgot password": Admin or System Admin creates
 * accounts and resets passwords (planning/rbac.md).
 */
export default function Login({ status }: Props) {
    return (
        <>
            <Head title="Log in" />

            {status && <p className="text-sm font-medium text-ok">{status}</p>}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                resetOnError={['password']}
                className="grid gap-3.5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-1">
                            <Label htmlFor="username" className={labelClass}>
                                Username
                            </Label>
                            <Input
                                id="username"
                                type="text"
                                name="username"
                                required
                                autoFocus
                                autoComplete="username"
                                autoCapitalize="none"
                                spellCheck={false}
                                aria-invalid={!!errors.username}
                                aria-describedby="login-error"
                            />
                        </div>

                        <div className="grid gap-1">
                            <Label htmlFor="password" className={labelClass}>
                                Password
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                autoComplete="current-password"
                                aria-invalid={!!errors.username}
                                aria-describedby="login-error"
                            />
                            <p
                                id="login-error"
                                role="alert"
                                className="text-xs text-bad empty:hidden"
                                data-test="login-error"
                            >
                                {errors.username ?? errors.password}
                            </p>
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="login-button"
                        >
                            {processing && <Spinner />}
                            Log in
                        </Button>

                        <p className="text-xs text-muted-foreground">
                            No account or forgot your password? Ask the Admin or
                            System Admin.
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}
