import { router } from '@inertiajs/react';
import { Save } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import PaymentRequestController from '@/actions/App/Http/Controllers/PaymentRequestController';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const STATUS_OPTIONS = [
    { value: 'payable', label: 'De plătit' },
    { value: 'disputed', label: 'Disputat' },
    { value: 'pending', label: 'De verificat' },
];

/**
 * Saves a finished verification into the payment-request register. The payload
 * carries the figures the check produced; note and status are chosen here.
 */
export default function SavePaymentRequest({
    title,
    description,
    payload,
    invoiceIds = [],
}: {
    title: string;
    description: string;
    payload: Record<string, unknown>;
    invoiceIds?: number[];
}) {
    const [note, setNote] = useState('');
    const [status, setStatus] = useState(
        payload.level === 'crit' ? 'disputed' : 'payable',
    );
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        router.post(
            PaymentRequestController.store().url,
            {
                ...payload,
                note: note.trim() || null,
                status,
                invoice_ids: invoiceIds,
            },
            {
                preserveScroll: true,
                onStart: () => {
                    setSaving(true);
                    setErrors({});
                },
                onError: (formErrors) => setErrors(formErrors),
                onFinish: () => setSaving(false),
            },
        );
    }

    const firstError = Object.values(errors)[0];

    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="grid min-w-0 flex-1 gap-1.5">
                        <Label htmlFor="payment-request-note">Notă</Label>
                        <Input
                            id="payment-request-note"
                            placeholder="ce nu concordă, nr. cerere / factură (opțional)"
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            className="w-full"
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="payment-request-status">Status</Label>
                        <Select value={status} onValueChange={setStatus}>
                            <SelectTrigger
                                id="payment-request-status"
                                className="w-40"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {STATUS_OPTIONS.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <Button type="submit" disabled={saving}>
                        <Save />
                        {saving ? 'Se salvează…' : 'Salvează în registru'}
                    </Button>
                </form>
                {firstError && (
                    <p className="mt-3 rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                        {firstError}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
