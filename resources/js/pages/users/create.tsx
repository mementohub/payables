import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import UserController from '@/actions/App/Http/Controllers/UserController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { create as usersCreate, index as usersIndex } from '@/routes/users';

export default function UserCreate() {
    return (
        <>
            <Head title="Adaugă utilizator" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <Button asChild variant="ghost" size="sm">
                        <Link href={usersIndex()}>
                            <ArrowLeft />
                            Înapoi
                        </Link>
                    </Button>
                </div>
                <h1 className="text-2xl font-semibold">Adaugă utilizator</h1>
                <p className="text-sm text-muted-foreground">
                    Utilizatorul va putea fi atribuit ca responsabil pe
                    furnizori. Autentificarea este gestionată separat prin
                    WorkOS.
                </p>

                <Form
                    {...UserController.store.form()}
                    resetOnSuccess
                    className="max-w-lg space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nume</Label>
                                <Input id="name" name="name" required />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    required
                                />
                                <InputError message={errors.email} />
                            </div>
                            <Button disabled={processing}>
                                Salvează utilizatorul
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

UserCreate.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Utilizatori', href: usersIndex() },
            { title: 'Adaugă', href: usersCreate() },
        ]}
    >
        {page}
    </AppLayout>
);
