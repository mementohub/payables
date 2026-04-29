import { Form, Head, Link } from '@inertiajs/react';
import { Pencil, Trash2, Upload } from 'lucide-react';
import UserController from '@/actions/App/Http/Controllers/UserController';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import {
    edit as usersEdit,
    importMethod as usersImport,
    index as usersIndex,
} from '@/routes/users';
import type { IndexProps as Props } from './types';

export default function UsersIndex({ users, current_user_id }: Props) {
    return (
        <>
            <Head title="Utilizatori" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">Utilizatori</h1>
                        <p className="text-sm text-muted-foreground">
                            Utilizatori disponibili pentru atribuire în
                            departamente.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={usersImport()}>
                            <Upload />
                            Importă utilizatori
                        </Link>
                    </Button>
                </div>

                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-12"></TableHead>
                                <TableHead>Nume</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead className="text-right">
                                    Departamente
                                </TableHead>
                                <TableHead>Creat</TableHead>
                                <TableHead className="text-right">
                                    Acțiuni
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-6 text-center text-muted-foreground"
                                    >
                                        Niciun utilizator încă.
                                    </TableCell>
                                </TableRow>
                            )}
                            {users.map((user) => {
                                const isCurrent = user.id === current_user_id;

                                return (
                                    <TableRow key={user.id}>
                                        <TableCell>
                                            <Avatar size="sm">
                                                <AvatarFallback>
                                                    {user.initials}
                                                </AvatarFallback>
                                            </Avatar>
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {user.name}
                                            {isCurrent && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    (tu)
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {user.email}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {user.departments_count}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {user.created_at ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center justify-end gap-2">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={usersEdit(
                                                            user.id,
                                                        )}
                                                    >
                                                        <Pencil />
                                                    </Link>
                                                </Button>
                                                {!isCurrent && (
                                                    <Form
                                                        {...UserController.destroy.form(
                                                            user.id,
                                                        )}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                        onBefore={() =>
                                                            confirm(
                                                                `Ștergi utilizatorul ${user.name}?`,
                                                            )
                                                        }
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                size="sm"
                                                                variant="destructive"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                <Trash2 />
                                                            </Button>
                                                        )}
                                                    </Form>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

UsersIndex.layout = (page: React.ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Utilizatori', href: usersIndex() }]}>
        {page}
    </AppLayout>
);
