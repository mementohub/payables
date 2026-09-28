import { Head, useForm } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { index as teamIndex, store as teamStore } from '@/routes/team';

type Member = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    departments: string[];
    is_me: boolean;
};

/**
 * Echipa departamentului: cine aprobă alături de tine și cum mai aduci pe
 * cineva. Colegul adăugat intră cu contul lui Microsoft, deja pregătit pe
 * departamentele tale.
 */
export default function TeamIndex({
    departments,
    members,
}: {
    departments: { id: number; name: string }[];
    members: Member[];
}) {
    const form = useForm({ emails: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(teamStore().url, {
            preserveScroll: true,
            onSuccess: () => form.reset('emails'),
        });
    };

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Echipa mea', href: teamIndex().url }]}
        >
            <Head title="Echipa mea" />

            <div className="flex flex-col gap-4 p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Adaugă un coleg</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="grid gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="emails">Adrese de e-mail</Label>
                                <Textarea
                                    id="emails"
                                    rows={3}
                                    placeholder="nume@christiantour.ro, alt.nume@christiantour.ro"
                                    value={form.data.emails}
                                    onChange={(e) =>
                                        form.setData('emails', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.emails} />
                                <p className="text-xs text-muted-foreground">
                                    Colegul primește rolul operațional și
                                    departamentele dumneavoastră
                                    {departments.length > 0 &&
                                        `: ${departments.map((d) => d.name).join(', ')}`}
                                    . Intră cu contul Microsoft, nu are nevoie
                                    de parolă. Alte roluri le dă un
                                    administrator.
                                </p>
                            </div>
                            <div>
                                <Button
                                    type="submit"
                                    disabled={
                                        form.processing ||
                                        form.data.emails.trim() === ''
                                    }
                                >
                                    <UserPlus />
                                    Adaugă pe departamentele mele
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            Cine aprobă pe departamentele mele
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nume</TableHead>
                                    <TableHead>E-mail</TableHead>
                                    <TableHead>Departamente</TableHead>
                                    <TableHead>Rol</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {members.map((member) => (
                                    <TableRow key={member.id}>
                                        <TableCell className="font-medium">
                                            {member.name}
                                            {member.is_me && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    (dumneavoastră)
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {member.email}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {member.departments.join(', ') ||
                                                '—'}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {member.roles.join(', ') ||
                                                'operational'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
