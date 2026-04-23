import { Form, Head } from '@inertiajs/react';
import { Plus, Trash2, UserMinus, UserPlus } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import DepartmentController from '@/actions/App/Http/Controllers/DepartmentController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index as departmentsIndex } from '@/routes/departments';

type Member = { id: number; name: string; email: string };

type Department = {
    id: number;
    name: string;
    type: 'supervisor' | 'master';
    members: Member[];
};

type User = { id: number; name: string; email: string };

type Props = {
    departments: Department[];
    users: User[];
};

const typeLabels: Record<Department['type'], string> = {
    supervisor: 'Supervizor',
    master: 'Master',
};

export default function DepartmentsIndex({ departments, users }: Props) {
    const [name, setName] = useState('');
    const [type, setType] = useState<'supervisor' | 'master'>('supervisor');

    const supervisorDepts = departments.filter((d) => d.type === 'supervisor');
    const masterDepts = departments.filter((d) => d.type === 'master');

    return (
        <>
            <Head title="Departamente" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Departamente</h1>
                    <p className="text-sm text-muted-foreground">
                        Supervizorii aprobă facturi pe furnizorii la care sunt atribuiți. Masterii dau OK final după
                        etapa supervizorilor, indiferent de furnizor.
                    </p>
                </div>

                <Card className="gap-2 py-3">
                    <CardHeader className="px-4 pb-0">
                        <CardTitle className="text-sm">Creează departament</CardTitle>
                    </CardHeader>
                    <CardContent className="px-4 pb-3">
                        <Form
                            {...DepartmentController.store.form()}
                            options={{ preserveScroll: true }}
                            onSuccess={() => {
                                setName('');
                                setType('supervisor');
                            }}
                            className="flex flex-wrap items-end gap-2"
                        >
                            {({ processing }) => (
                                <>
                                    <div className="grid gap-1">
                                        <Label className="text-xs">Nume</Label>
                                        <Input
                                            name="name"
                                            value={name}
                                            onChange={(e) => setName(e.target.value)}
                                            placeholder="Ex: Operațional transport"
                                            className="min-h-10 w-[260px]"
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label className="text-xs">Tip</Label>
                                        <Select
                                            value={type}
                                            onValueChange={(v) => setType(v as 'supervisor' | 'master')}
                                        >
                                            <SelectTrigger className="min-h-10 w-[180px]">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="supervisor">Supervizor</SelectItem>
                                                <SelectItem value="master">Master</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <input type="hidden" name="type" value={type} />
                                    </div>
                                    <Button type="submit" disabled={processing || ! name.trim()}>
                                        <Plus />
                                        Creează
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <DepartmentGroup title="Supervizori" items={supervisorDepts} users={users} />
                <DepartmentGroup title="Masteri" items={masterDepts} users={users} />
            </div>
        </>
    );
}

function DepartmentGroup({
    title,
    items,
    users,
}: {
    title: string;
    items: Department[];
    users: User[];
}) {
    return (
        <div className="space-y-2">
            <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">{title}</h2>
            {items.length === 0 ? (
                <p className="text-xs text-muted-foreground">Niciun departament.</p>
            ) : (
                <div className="grid gap-3 md:grid-cols-2">
                    {items.map((dept) => (
                        <DepartmentCard key={dept.id} dept={dept} users={users} />
                    ))}
                </div>
            )}
        </div>
    );
}

function DepartmentCard({ dept, users }: { dept: Department; users: User[] }) {
    const [addUserId, setAddUserId] = useState('');
    const memberIds = new Set(dept.members.map((m) => m.id));
    const available = users.filter((u) => ! memberIds.has(u.id));

    return (
        <Card className="gap-2 py-3">
            <CardHeader className="flex flex-row items-center justify-between px-4 pb-0">
                <div className="flex items-center gap-2">
                    <CardTitle className="text-sm">{dept.name}</CardTitle>
                    <Badge variant="outline" className="text-[10px]">
                        {typeLabels[dept.type]}
                    </Badge>
                </div>
                <Form
                    {...DepartmentController.destroy.form(dept.id)}
                    options={{ preserveScroll: true }}
                    onBefore={() => confirm(`Ștergi departamentul ${dept.name}?`)}
                >
                    {({ processing }) => (
                        <Button size="sm" variant="ghost" disabled={processing}>
                            <Trash2 className="size-4 text-destructive" />
                        </Button>
                    )}
                </Form>
            </CardHeader>
            <CardContent className="space-y-2 px-4 pb-3">
                {dept.members.length === 0 ? (
                    <p className="text-xs text-muted-foreground">Niciun membru.</p>
                ) : (
                    <ul className="flex flex-col gap-1">
                        {dept.members.map((member) => (
                            <li
                                key={member.id}
                                className="flex items-center justify-between rounded-md border border-sidebar-border/70 px-2 py-1 text-xs dark:border-sidebar-border"
                            >
                                <div>
                                    <div className="font-medium">{member.name}</div>
                                    <div className="text-muted-foreground">{member.email}</div>
                                </div>
                                <Form
                                    {...DepartmentController.detachMember.form([dept.id, member.id])}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button size="sm" variant="ghost" disabled={processing}>
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
                        {...DepartmentController.attachMember.form(dept.id)}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setAddUserId('')}
                        className="flex items-center gap-2"
                    >
                        {({ processing }) => (
                            <>
                                <Select value={addUserId} onValueChange={setAddUserId}>
                                    <SelectTrigger size="sm" className="w-full">
                                        <SelectValue placeholder="Adaugă utilizator…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {available.map((u) => (
                                            <SelectItem key={u.id} value={String(u.id)}>
                                                {u.name}
                                                <span className="ml-2 text-xs text-muted-foreground">{u.email}</span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <input type="hidden" name="user_id" value={addUserId} />
                                <Button size="sm" type="submit" disabled={processing || ! addUserId}>
                                    <UserPlus className="size-4" />
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </CardContent>
        </Card>
    );
}

DepartmentsIndex.layout = (page: React.ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Departamente', href: departmentsIndex() }]}>{page}</AppLayout>
);
