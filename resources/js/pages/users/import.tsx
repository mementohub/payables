import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import UserController from '@/actions/App/Http/Controllers/UserController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import {
    importMethod as usersImport,
    index as usersIndex,
} from '@/routes/users';

export default function UserImport() {
    return (
        <>
            <Head title="Importă utilizatori" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <Button asChild variant="ghost" size="sm">
                        <Link href={usersIndex()}>
                            <ArrowLeft />
                            Înapoi
                        </Link>
                    </Button>
                </div>
                <h1 className="text-2xl font-semibold">Importă utilizatori</h1>
                <p className="text-sm text-muted-foreground">
                    Lipește lista de email-uri (separate prin virgulă, spațiu
                    sau pe linii noi). Fiecare utilizator primește o parolă
                    aleatorie și se va putea autentifica prin Microsoft cu
                    aceeași adresă. Numele se completează automat la prima
                    autentificare.
                </p>

                <Form
                    {...UserController.importMethod.form()}
                    className="max-w-2xl space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="emails">Email-uri</Label>
                                <Textarea
                                    id="emails"
                                    name="emails"
                                    rows={12}
                                    placeholder="user1@example.com&#10;user2@example.com"
                                    required
                                />
                                <InputError message={errors.emails} />
                            </div>
                            <Button disabled={processing}>
                                Importă utilizatori
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

UserImport.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Utilizatori', href: usersIndex() },
            { title: 'Importă', href: usersImport() },
        ]}
    >
        {page}
    </AppLayout>
);
