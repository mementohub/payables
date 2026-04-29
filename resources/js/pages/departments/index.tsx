import { Form, Head, router } from '@inertiajs/react';
import { Pencil, Plus, Save, Trash2, UserMinus, UserPlus } from 'lucide-react';
import { useState } from 'react';
import DepartmentController from '@/actions/App/Http/Controllers/DepartmentController';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { index as departmentsIndex } from '@/routes/departments';
import type {
    Department,
    DepartmentType,
    Filters,
    Props,
    UserOption as User,
} from './types';

const typeLabels: Record<DepartmentType, string> = {
    supervisor: 'Supervizor',
    master: 'Master',
};

export default function DepartmentsIndex({
    departments,
    users,
    filters,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [createOpen, setCreateOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

    const baseUrl = departmentsIndex().url;

    const applyFilter = (next: Partial<Filters>) => {
        router.get(
            baseUrl,
            {
                search: next.search ?? filters.search ?? undefined,
                type: next.type ?? filters.type ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const editingDepartment =
        editingId === null
            ? null
            : (departments.data.find((d) => d.id === editingId) ?? null);

    return (
        <>
            <Head title="Departamente" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Departamente</h1>
                        <p className="text-sm text-muted-foreground">
                            Supervizorii aprobă facturi pe furnizorii la care
                            sunt atribuiți. Masterii dau OK final după etapa
                            supervizorilor, indiferent de furnizor.
                        </p>
                    </div>
                    <CreateDialog
                        open={createOpen}
                        onOpenChange={setCreateOpen}
                    />
                </div>

                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        applyFilter({ search });
                    }}
                >
                    <Input
                        className="max-w-xs"
                        placeholder="Caută departament sau utilizator…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <Select
                        value={filters.type ?? 'all'}
                        onValueChange={(v) =>
                            applyFilter({
                                type: v === 'all' ? null : (v as DepartmentType),
                            })
                        }
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="Tip" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Toate tipurile</SelectItem>
                            <SelectItem value="supervisor">
                                Supervizori
                            </SelectItem>
                            <SelectItem value="master">Masteri</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button type="submit" variant="secondary">
                        Caută
                    </Button>
                </form>

                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nume</TableHead>
                                <TableHead className="w-[120px]">Tip</TableHead>
                                <TableHead>Membri</TableHead>
                                <TableHead className="text-right">
                                    Acțiuni
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {departments.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="py-6 text-center text-muted-foreground"
                                    >
                                        Niciun departament.
                                    </TableCell>
                                </TableRow>
                            )}
                            {departments.data.map((dept) => (
                                <TableRow key={dept.id} className="align-top">
                                    <TableCell className="font-medium">
                                        {dept.name}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant="outline"
                                            className="text-[10px]"
                                        >
                                            {typeLabels[dept.type]}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        {dept.members.length === 0 ? (
                                            <span className="text-xs text-muted-foreground">
                                                —
                                            </span>
                                        ) : (
                                            <div className="flex flex-wrap gap-1">
                                                {dept.members.map((m) => (
                                                    <Badge
                                                        key={m.id}
                                                        variant="outline"
                                                        className="text-[10px]"
                                                        title={m.email}
                                                    >
                                                        {m.name}
                                                    </Badge>
                                                ))}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center justify-end gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setEditingId(dept.id)
                                                }
                                            >
                                                <Pencil />
                                            </Button>
                                            <Form
                                                {...DepartmentController.destroy.form(
                                                    dept.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                onBefore={() =>
                                                    confirm(
                                                        `Ștergi departamentul ${dept.name}?`,
                                                    )
                                                }
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        size="sm"
                                                        variant="destructive"
                                                        disabled={processing}
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                )}
                                            </Form>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <div className="flex items-center justify-between">
                    <span className="text-xs text-muted-foreground">
                        {departments.from ?? 0}–{departments.to ?? 0} din{' '}
                        {departments.total}
                    </span>
                    <Pagination links={departments.links} />
                </div>
            </div>

            <Dialog
                open={editingDepartment !== null}
                onOpenChange={(o) => !o && setEditingId(null)}
            >
                <DialogContent>
                    {editingDepartment && (
                        <EditDialogContent
                            key={editingDepartment.id}
                            department={editingDepartment}
                            users={users}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function CreateDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
}) {
    const [name, setName] = useState('');
    const [type, setType] = useState<DepartmentType>('supervisor');

    const reset = () => {
        setName('');
        setType('supervisor');
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(v) => {
                if (!v) {
                    reset();
                }
                onOpenChange(v);
            }}
        >
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    Adaugă departament
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Departament nou</DialogTitle>
                    <DialogDescription>
                        Crează un departament de tip supervizor sau master.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...DepartmentController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        reset();
                        onOpenChange(false);
                    }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="create-name">Nume</Label>
                                <Input
                                    id="create-name"
                                    name="name"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    placeholder="Ex: Operațional transport"
                                    required
                                />
                                {errors.name && (
                                    <span className="text-xs text-destructive">
                                        {errors.name}
                                    </span>
                                )}
                            </div>
                            <div className="grid gap-2">
                                <Label>Tip</Label>
                                <Select
                                    value={type}
                                    onValueChange={(v) =>
                                        setType(v as DepartmentType)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="supervisor">
                                            Supervizor
                                        </SelectItem>
                                        <SelectItem value="master">
                                            Master
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <input type="hidden" name="type" value={type} />
                                {errors.type && (
                                    <span className="text-xs text-destructive">
                                        {errors.type}
                                    </span>
                                )}
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Anulează
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing || !name.trim()}
                                >
                                    <Plus />
                                    Creează
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function EditDialogContent({
    department,
    users,
}: {
    department: Department;
    users: User[];
}) {
    const [name, setName] = useState(department.name);
    const [type, setType] = useState<DepartmentType>(department.type);
    const [addUserId, setAddUserId] = useState('');

    const memberIds = new Set(department.members.map((m) => m.id));
    const available = users.filter((u) => !memberIds.has(u.id));

    return (
        <>
            <DialogHeader>
                <DialogTitle>Modifică departament</DialogTitle>
                <DialogDescription>
                    Editează numele, tipul și membrii.
                </DialogDescription>
            </DialogHeader>

            <Form
                {...DepartmentController.update.form(department.id)}
                options={{ preserveScroll: true }}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="edit-name">Nume</Label>
                            <Input
                                id="edit-name"
                                name="name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                required
                            />
                            {errors.name && (
                                <span className="text-xs text-destructive">
                                    {errors.name}
                                </span>
                            )}
                        </div>
                        <div className="grid gap-2">
                            <Label>Tip</Label>
                            <Select
                                value={type}
                                onValueChange={(v) =>
                                    setType(v as DepartmentType)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="supervisor">
                                        Supervizor
                                    </SelectItem>
                                    <SelectItem value="master">
                                        Master
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <input type="hidden" name="type" value={type} />
                            {errors.type && (
                                <span className="text-xs text-destructive">
                                    {errors.type}
                                </span>
                            )}
                        </div>
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing || !name.trim()}
                            >
                                <Save />
                                Salvează
                            </Button>
                        </div>
                    </>
                )}
            </Form>

            <div className="space-y-2 border-t pt-4">
                <h3 className="text-sm font-semibold">Membri</h3>
                {department.members.length === 0 ? (
                    <p className="text-xs text-muted-foreground">
                        Niciun membru.
                    </p>
                ) : (
                    <ul className="flex flex-col gap-1">
                        {department.members.map((m) => (
                            <li
                                key={m.id}
                                className="flex items-center justify-between rounded-md border border-sidebar-border/70 px-2 py-1 text-xs dark:border-sidebar-border"
                            >
                                <div>
                                    <div className="font-medium">{m.name}</div>
                                    <div className="text-muted-foreground">
                                        {m.email}
                                    </div>
                                </div>
                                <Form
                                    {...DepartmentController.detachMember.form([
                                        department.id,
                                        m.id,
                                    ])}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            disabled={processing}
                                        >
                                            <UserMinus className="size-4" />
                                        </Button>
                                    )}
                                </Form>
                            </li>
                        ))}
                    </ul>
                )}

                {available.length > 0 && (
                    <Form
                        {...DepartmentController.attachMember.form(
                            department.id,
                        )}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setAddUserId('')}
                        className="flex items-center gap-2"
                    >
                        {({ processing }) => (
                            <>
                                <Select
                                    value={addUserId}
                                    onValueChange={setAddUserId}
                                >
                                    <SelectTrigger size="sm" className="w-full">
                                        <SelectValue placeholder="Adaugă utilizator…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {available.map((u) => (
                                            <SelectItem
                                                key={u.id}
                                                value={String(u.id)}
                                            >
                                                {u.name}
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    {u.email}
                                                </span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <input
                                    type="hidden"
                                    name="user_id"
                                    value={addUserId}
                                />
                                <Button
                                    size="sm"
                                    type="submit"
                                    disabled={processing || !addUserId}
                                >
                                    <UserPlus className="size-4" />
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

DepartmentsIndex.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[{ title: 'Departamente', href: departmentsIndex() }]}
    >
        {page}
    </AppLayout>
);
