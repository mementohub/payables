import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, UserMinus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import DepartmentController from '@/actions/App/Http/Controllers/DepartmentController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { index as departmentsIndex } from '@/routes/departments';
import type { DepartmentGroup } from '@/types/approvals';
import type { Department, Props, UserOption } from './types';

const groups: { value: DepartmentGroup; title: string; description: string }[] =
    [
        {
            value: 'product',
            title: 'Categorii de produs',
            description:
                'Aprobă costurile turistice: liniile ajung aici după rezervarea eTrip, contractul charter sau biletul Tina.',
        },
        {
            value: 'channel',
            title: 'Canale de vânzare',
            description:
                'Aprobă costurile proprii (agenții, francize, site, B2B); pe liniile turistice canalul se păstrează doar pentru raportare.',
        },
        {
            value: 'support',
            title: 'Departamente suport',
            description:
                'Aprobă costurile corporate: marketing, financiar, administrativ, calitate.',
        },
    ];

type DepartmentForm = {
    name: string;
    group: DepartmentGroup;
    parent_id: string;
    is_active: boolean;
};

export default function DepartmentsIndex({ departments, users }: Props) {
    const [editing, setEditing] = useState<Department | 'new' | null>(null);

    return (
        <>
            <Head title="Departamente" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">Departamente</h1>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Fiecare factură de furnizor se împarte, linie cu
                            linie, pe departamentele de mai jos. Membrii unui
                            departament aprobă partea lui din factură; apoi
                            decide Top Management.
                        </p>
                    </div>
                    <Button onClick={() => setEditing('new')}>
                        <Plus />
                        Departament nou
                    </Button>
                </div>

                {groups.map((group) => (
                    <section key={group.value} className="grid gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">
                                {group.title}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {group.description}
                            </p>
                        </div>
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {departments
                                .filter((d) => d.group === group.value)
                                .map((department) => (
                                    <DepartmentCard
                                        key={department.id}
                                        department={department}
                                        parent={departments.find(
                                            (d) =>
                                                d.id === department.parent_id,
                                        )}
                                        users={users}
                                        onEdit={() => setEditing(department)}
                                    />
                                ))}
                        </div>
                    </section>
                ))}
            </div>

            {editing !== null && (
                <DepartmentDialog
                    department={editing === 'new' ? null : editing}
                    departments={departments}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function DepartmentCard({
    department,
    parent,
    users,
    onEdit,
}: {
    department: Department;
    parent: Department | undefined;
    users: UserOption[];
    onEdit: () => void;
}) {
    const candidates = users.filter(
        (user) => !department.members.some((m) => m.id === user.id),
    );

    const addMember = (userId: string) =>
        router.post(
            DepartmentController.attachMember(department.id).url,
            { user_id: Number(userId) },
            { preserveScroll: true },
        );

    const removeMember = (userId: number) =>
        router.delete(
            DepartmentController.detachMember({
                department: department.id,
                user: userId,
            }).url,
            { preserveScroll: true },
        );

    return (
        <Card className={department.is_active ? '' : 'opacity-60'}>
            <CardHeader className="gap-1">
                <div className="flex items-start justify-between gap-2">
                    <div>
                        <CardTitle className="text-base">
                            {department.name}
                        </CardTitle>
                        <CardDescription>
                            {parent ? `în ${parent.name}` : department.code}
                        </CardDescription>
                    </div>
                    <div className="flex items-center gap-1">
                        {!department.is_active && (
                            <Badge variant="outline">Inactiv</Badge>
                        )}
                        {department.pending_count > 0 && (
                            <Badge
                                variant="outline"
                                className="border-amber-600/40 text-amber-700 dark:text-amber-300"
                            >
                                {department.pending_count} de aprobat
                            </Badge>
                        )}
                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={onEdit}
                            aria-label={`Modifică ${department.name}`}
                        >
                            <Pencil />
                        </Button>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="grid gap-2">
                {department.members.length === 0 ? (
                    <p className="text-sm text-amber-700 dark:text-amber-300">
                        Nimeni nu aprobă încă pentru acest departament.
                    </p>
                ) : (
                    <ul className="grid gap-1">
                        {department.members.map((member) => (
                            <li
                                key={member.id}
                                className="flex items-center justify-between gap-2 text-sm"
                            >
                                <span>
                                    {member.name}{' '}
                                    <span className="text-muted-foreground">
                                        {member.email}
                                    </span>
                                </span>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-7"
                                    onClick={() => removeMember(member.id)}
                                    aria-label={`Scoate ${member.name}`}
                                >
                                    <UserMinus className="size-4" />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
                {candidates.length > 0 && (
                    <Select value="" onValueChange={addMember}>
                        <SelectTrigger size="sm" className="w-full">
                            <SelectValue placeholder="Adaugă un aprobator…" />
                        </SelectTrigger>
                        <SelectContent>
                            {candidates.map((user) => (
                                <SelectItem
                                    key={user.id}
                                    value={String(user.id)}
                                >
                                    {user.name} ({user.email})
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </CardContent>
        </Card>
    );
}

function DepartmentDialog({
    department,
    departments,
    onClose,
}: {
    department: Department | null;
    departments: Department[];
    onClose: () => void;
}) {
    const form = useForm<DepartmentForm>({
        name: department?.name ?? '',
        group: department?.group ?? 'support',
        parent_id: department?.parent_id ? String(department.parent_id) : '',
        is_active: department?.is_active ?? true,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            parent_id: data.parent_id === '' ? null : Number(data.parent_id),
        }));

        const options = { preserveScroll: true, onSuccess: onClose };

        if (department) {
            form.put(DepartmentController.update(department.id).url, options);
        } else {
            form.post(DepartmentController.store().url, options);
        }
    };

    const parents = departments.filter(
        (d) =>
            d.id !== department?.id &&
            d.group === form.data.group &&
            d.parent_id === null,
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {department
                                ? `Modifică ${department.name}`
                                : 'Departament nou'}
                        </DialogTitle>
                        <DialogDescription>
                            Regulile de rutare trimit liniile de factură către
                            departamente; un departament nou primește linii după
                            ce îl folosește o regulă.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="department-name">Nume</Label>
                        <Input
                            id="department-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Grup</Label>
                        <Select
                            value={form.data.group}
                            onValueChange={(value) =>
                                form.setData({
                                    ...form.data,
                                    group: value as DepartmentGroup,
                                    parent_id: '',
                                })
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {groups.map((group) => (
                                    <SelectItem
                                        key={group.value}
                                        value={group.value}
                                    >
                                        {group.title}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.group} />
                    </div>

                    {parents.length > 0 && (
                        <div className="grid gap-2">
                            <Label>Face parte din</Label>
                            <Select
                                value={form.data.parent_id || 'none'}
                                onValueChange={(value) =>
                                    form.setData(
                                        'parent_id',
                                        value === 'none' ? '' : value,
                                    )
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">—</SelectItem>
                                    {parents.map((parent) => (
                                        <SelectItem
                                            key={parent.id}
                                            value={String(parent.id)}
                                        >
                                            {parent.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.parent_id} />
                        </div>
                    )}

                    {department && (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(e) =>
                                    form.setData('is_active', e.target.checked)
                                }
                                className="size-4 accent-primary"
                            />
                            Activ
                        </label>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Renunță
                        </Button>
                        <Button disabled={form.processing}>Salvează</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

DepartmentsIndex.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[{ title: 'Departamente', href: departmentsIndex() }]}
    >
        {page}
    </AppLayout>
);
