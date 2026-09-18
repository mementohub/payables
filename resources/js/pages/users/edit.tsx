import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import UserController from '@/actions/App/Http/Controllers/UserController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { index as departmentsIndex } from '@/routes/departments';
import { edit as usersEdit, index as usersIndex } from '@/routes/users';
import type { EditUser as User } from './types';

const roleOptions = [
    {
        value: 'top_management',
        label: 'Top Management',
        description:
            'Aprobarea finală a facturilor și a rulajelor de plată; contestă sau amână.',
    },
    {
        value: 'finance',
        label: 'Financiar',
        description:
            'Rutează facturile pe departamente, gestionează regulile și pregătește rulajele de plată.',
    },
    {
        value: 'treasury',
        label: 'Trezorerie',
        description: 'Trimite rulajele aprobate la bancă (fișierul BT).',
    },
    {
        value: 'admin',
        label: 'Administrator',
        description:
            'Toate rolurile, plus utilizatori, departamente, companii și întreținere.',
    },
];

export default function UserEdit({ user }: { user: User }) {
    return (
        <>
            <Head title={`Modifică ${user.name}`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <Button asChild variant="ghost" size="sm">
                        <Link href={usersIndex()}>
                            <ArrowLeft />
                            Înapoi
                        </Link>
                    </Button>
                </div>
                <h1 className="text-2xl font-semibold">Modifică {user.name}</h1>

                <Form
                    {...UserController.update.form(user.id)}
                    className="max-w-lg space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nume</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={user.name}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    defaultValue={user.email}
                                    required
                                />
                                <InputError message={errors.email} />
                            </div>
                            <fieldset className="grid gap-3">
                                <legend className="mb-1 text-sm font-medium">
                                    Roluri în fluxul de plată
                                </legend>
                                {roleOptions.map((role) => (
                                    <label
                                        key={role.value}
                                        className="flex items-start gap-3 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            name="roles[]"
                                            value={role.value}
                                            defaultChecked={user.roles.includes(
                                                role.value,
                                            )}
                                            className="mt-0.5 size-4 accent-primary"
                                        />
                                        <span>
                                            <span className="font-medium">
                                                {role.label}
                                            </span>
                                            <span className="block text-muted-foreground">
                                                {role.description}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                                <InputError message={errors.roles} />
                                <p className="text-sm text-muted-foreground">
                                    Aprobă pentru departamentele:{' '}
                                    {user.departments.length > 0
                                        ? user.departments.join(', ')
                                        : 'niciunul'}{' '}
                                    (se schimbă din{' '}
                                    <Link
                                        href={departmentsIndex()}
                                        className="underline"
                                    >
                                        Departamente
                                    </Link>
                                    ).
                                </p>
                            </fieldset>
                            <Button disabled={processing}>
                                Salvează modificările
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

UserEdit.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Utilizatori', href: usersIndex() },
            { title: 'Modifică', href: usersEdit(0) },
        ]}
    >
        {page}
    </AppLayout>
);
