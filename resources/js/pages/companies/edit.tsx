import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import CompanyController from '@/actions/App/Http/Controllers/CompanyController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import {
    edit as companiesEdit,
    index as companiesIndex,
} from '@/routes/companies';
import type { CompanyEditPayload as Company } from './types';

export default function CompanyEdit({ company }: { company: Company }) {
    return (
        <>
            <Head title={`Modifică ${company.name}`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <Button asChild variant="ghost" size="sm">
                        <Link href={companiesIndex()}>
                            <ArrowLeft />
                            Înapoi
                        </Link>
                    </Button>
                </div>
                <h1 className="text-2xl font-semibold">
                    Modifică {company.name}
                </h1>
                <p className="text-sm text-muted-foreground">
                    Lasă parola goală pentru a o păstra pe cea existentă.
                </p>

                <Form
                    {...CompanyController.update.form(company.id)}
                    className="max-w-2xl space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Nume companie</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={company.name}
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="cui">CUI</Label>
                                    <Input
                                        id="cui"
                                        name="cui"
                                        defaultValue={company.cui ?? ''}
                                    />
                                    <InputError message={errors.cui} />
                                </div>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="db_host">Gazdă BD</Label>
                                    <Input
                                        id="db_host"
                                        name="db_host"
                                        defaultValue={company.db_host}
                                        required
                                    />
                                    <InputError message={errors.db_host} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db_port">Port BD</Label>
                                    <Input
                                        id="db_port"
                                        name="db_port"
                                        defaultValue={company.db_port}
                                        required
                                    />
                                    <InputError message={errors.db_port} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db_database">
                                        Bază de date
                                    </Label>
                                    <Input
                                        id="db_database"
                                        name="db_database"
                                        defaultValue={company.db_database}
                                        required
                                    />
                                    <InputError message={errors.db_database} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db_driver">Driver</Label>
                                    <Input
                                        id="db_driver"
                                        name="db_driver"
                                        defaultValue={company.db_driver}
                                        readOnly
                                    />
                                    <InputError message={errors.db_driver} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db_username">
                                        Utilizator BD
                                    </Label>
                                    <Input
                                        id="db_username"
                                        name="db_username"
                                        defaultValue={company.db_username}
                                        required
                                    />
                                    <InputError message={errors.db_username} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db_password">
                                        Parolă BD
                                    </Label>
                                    <Input
                                        id="db_password"
                                        name="db_password"
                                        type="password"
                                        autoComplete="new-password"
                                        placeholder="•••••• (nemodificată)"
                                    />
                                    <InputError message={errors.db_password} />
                                </div>
                            </div>

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

CompanyEdit.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Companii', href: companiesIndex() },
            { title: 'Modifică', href: companiesEdit(0) },
        ]}
    >
        {page}
    </AppLayout>
);
