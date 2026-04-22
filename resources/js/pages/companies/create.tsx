import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import CompanyController from '@/actions/App/Http/Controllers/CompanyController';
import { create as companiesCreate, index as companiesIndex } from '@/routes/companies';

export default function CompanyCreate() {
    return (
        <>
            <Head title="Adaugă companie" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <Button asChild variant="ghost" size="sm">
                        <Link href={companiesIndex()}>
                            <ArrowLeft />
                            Înapoi
                        </Link>
                    </Button>
                </div>
                <h1 className="text-2xl font-semibold">Adaugă companie</h1>
                <p className="text-sm text-muted-foreground">
                    Utilizatorul și parola bazei de date sunt criptate înainte de a fi stocate.
                </p>

                <Form
                    {...CompanyController.store.form()}
                    resetOnSuccess
                    className="max-w-2xl space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Nume companie</Label>
                                    <Input id="name" name="name" required />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="cui">CUI</Label>
                                    <Input id="cui" name="cui" />
                                    <InputError message={errors.cui} />
                                </div>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="db_host">Gazdă BD</Label>
                                    <Input id="db_host" name="db_host" defaultValue="127.0.0.1" required />
                                    <InputError message={errors.db_host} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db_port">Port BD</Label>
                                    <Input id="db_port" name="db_port" defaultValue="5432" required />
                                    <InputError message={errors.db_port} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db_database">Bază de date</Label>
                                    <Input id="db_database" name="db_database" defaultValue="laravel" required />
                                    <InputError message={errors.db_database} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db_driver">Driver</Label>
                                    <Input id="db_driver" name="db_driver" defaultValue="pgsql" readOnly />
                                    <InputError message={errors.db_driver} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db_username">Utilizator BD</Label>
                                    <Input id="db_username" name="db_username" defaultValue="root" required />
                                    <InputError message={errors.db_username} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db_password">Parolă BD</Label>
                                    <Input id="db_password" name="db_password" type="password" autoComplete="new-password" />
                                    <InputError message={errors.db_password} />
                                </div>
                            </div>

                            <Button disabled={processing}>Salvează compania</Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CompanyCreate.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Companii', href: companiesIndex() },
            { title: 'Adaugă', href: companiesCreate() },
        ]}
    >
        {page}
    </AppLayout>
);
