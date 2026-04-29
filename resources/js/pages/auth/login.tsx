import { Form, Head } from '@inertiajs/react';
import LoginController from '@/actions/App/Http/Controllers/Auth/LoginController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { redirect as microsoftRedirect } from '@/routes/auth/microsoft';

export default function Login({ status }: { status?: string }) {
    return (
        <AuthLayout title="Bun venit" description="Autentifică-te în Payables">
            <Head title="Autentificare" />

            {status && (
                <div className="rounded-md bg-emerald-50 px-3 py-2 text-center text-sm text-emerald-700 dark:bg-emerald-700/10 dark:text-emerald-200">
                    {status}
                </div>
            )}

            <div className="rounded-lg border bg-card p-6 shadow-sm">
                <Button asChild variant="outline" className="w-full">
                    <a href={microsoftRedirect().url}>
                        <MicrosoftLogo />
                        Continuă cu Microsoft
                    </a>
                </Button>

                <div className="my-6 flex items-center gap-3">
                    <span className="h-px flex-1 bg-border" />
                    <span className="text-xs tracking-wide text-muted-foreground uppercase">
                        sau
                    </span>
                    <span className="h-px flex-1 bg-border" />
                </div>

                <Form
                    {...LoginController.store.form()}
                    resetOnSuccess={['password']}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    autoComplete="username"
                                    autoFocus
                                    required
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Parolă</Label>
                                <Input
                                    id="password"
                                    name="password"
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center gap-2">
                                <Checkbox id="remember" name="remember" />
                                <Label
                                    htmlFor="remember"
                                    className="text-sm font-normal"
                                >
                                    Ține-mă autentificat
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                            >
                                Autentificare
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </AuthLayout>
    );
}

function MicrosoftLogo() {
    return (
        <svg
            viewBox="0 0 23 23"
            xmlns="http://www.w3.org/2000/svg"
            className="size-4"
            aria-hidden="true"
        >
            <path fill="#f25022" d="M1 1h10v10H1z" />
            <path fill="#7fba00" d="M12 1h10v10H12z" />
            <path fill="#00a4ef" d="M1 12h10v10H1z" />
            <path fill="#ffb900" d="M12 12h10v10H12z" />
        </svg>
    );
}
