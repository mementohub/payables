import { router } from '@inertiajs/react';
import { useState } from 'react';
import RoutingController from '@/actions/App/Http/Controllers/Approvals/RoutingController';
import DepartmentSelect from '@/components/department-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type { DepartmentRef } from '@/types/approvals';

/**
 * Finance sends one or more invoices to a department by hand; optionally
 * every future invoice of their suppliers too.
 */
export default function RouteInvoicesDialog({
    invoiceIds,
    departments,
    title,
    onClose,
    onDone,
}: {
    invoiceIds: number[];
    departments: DepartmentRef[];
    title: string;
    onClose: () => void;
    onDone?: () => void;
}) {
    const [department, setDepartment] = useState('');
    const [remember, setRemember] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    const submit = () =>
        router.post(
            RoutingController.assignMany().url,
            {
                invoice_ids: invoiceIds,
                department_id: Number(department),
                remember,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ?? 'Rutarea nu a reușit.',
                    ),
                onSuccess: () => {
                    onDone?.();
                    onClose();
                },
            },
        );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        Toate liniile facturii merg la departamentul ales, care
                        o aprobă; regulile nu o mai mută de acolo.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label>Departament</Label>
                        <DepartmentSelect
                            departments={departments}
                            value={department}
                            onChange={setDepartment}
                        />
                    </div>
                    <label className="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={remember}
                            onChange={(e) => setRemember(e.target.checked)}
                            className="mt-0.5 size-4 accent-primary"
                        />
                        <span>
                            Trimite de acum încolo toate facturile acestui
                            furnizor aici
                            <span className="block text-xs text-muted-foreground">
                                Creează o regulă pentru furnizor (se vede în
                                Reguli de rutare).
                            </span>
                        </span>
                    </label>
                    {error && (
                        <p className="text-sm text-destructive">{error}</p>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        Renunță
                    </Button>
                    <Button
                        disabled={department === '' || processing}
                        onClick={submit}
                    >
                        Rutează
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
