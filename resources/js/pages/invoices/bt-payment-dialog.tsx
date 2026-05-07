import { AlertTriangle, Download, Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import PaymentExportController from '@/actions/App/Http/Controllers/PaymentExportController';
import { BtLogo } from '@/components/icons/bt-logo';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

type SupplierAccount = {
    iban: string;
    currency: string;
    bank: string | null;
    bic: string | null;
    swift: string | null;
    is_default: boolean;
};

export type BtRow = {
    invoice_id: number;
    order_number: number;
    beneficiary_name: string;
    beneficiary_fiscal_code: string;
    target_account_number: string | null;
    beneficiary_bank_bic: string | null;
    amount: number;
    currency: string | null;
    payment_ref_1: string;
    payment_ref_2: string;
    value_date: string;
    urgent: 'F' | 'T';
    supplier_accounts: SupplierAccount[];
    warnings: string[];
};

export type CompanyAccount = {
    id: number;
    bank: string | null;
    iban: string;
    currency: string;
    bic: string | null;
    swift: string | null;
    is_default: boolean;
};

export type BtPreparePayload = {
    company: { id: number; name: string; cui: string | null };
    rows: BtRow[];
    company_accounts: CompanyAccount[];
    invoices_skipped: number;
    truncated?: boolean;
    limit?: number;
};

export type BtPrepareRequest = {
    invoice_ids?: number[];
    select_all?: boolean;
    filters?: Record<string, string | number | null | undefined>;
};

export function BtPaymentDialog({
    open,
    onOpenChange,
    request,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    request: BtPrepareRequest;
}) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [data, setData] = useState<BtPreparePayload | null>(null);
    const [rows, setRows] = useState<BtRow[]>([]);
    const [includedIds, setIncludedIds] = useState<Set<number>>(new Set());
    const [sourceAccountId, setSourceAccountId] = useState<string>('');

    useEffect(() => {
        if (
            !open ||
            (!request.select_all &&
                (!request.invoice_ids || request.invoice_ids.length === 0))
        ) {
            return;
        }

        setLoading(true);
        setError(null);
        setData(null);

        const controller = new AbortController();

        const cleanFilters: Record<string, string | number> = {};
        Object.entries(request.filters ?? {}).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '') {
                cleanFilters[key] = value;
            }
        });

        fetch(PaymentExportController.btPrepare().url, {
            method: 'POST',
            credentials: 'same-origin',
            signal: controller.signal,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
            body: JSON.stringify({
                invoice_ids: request.invoice_ids ?? [],
                select_all: request.select_all ? 1 : 0,
                ...cleanFilters,
            }),
        })
            .then(async (res) => {
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }

                return (await res.json()) as BtPreparePayload;
            })
            .then((payload) => {
                setData(payload);
                setRows(payload.rows);
                setIncludedIds(
                    new Set(
                        payload.rows
                            .filter(
                                (r) =>
                                    r.target_account_number !== null &&
                                    r.amount > 0,
                            )
                            .map((r) => r.invoice_id),
                    ),
                );

                const dominantCurrency = pickDominantCurrency(payload.rows);
                const matching = payload.company_accounts.find(
                    (a) =>
                        a.currency === dominantCurrency &&
                        a.is_default &&
                        normalizeIban(a.iban),
                );
                const fallback = payload.company_accounts.find(
                    (a) =>
                        a.currency === dominantCurrency &&
                        normalizeIban(a.iban),
                );
                const anyDefault = payload.company_accounts.find(
                    (a) => a.is_default && normalizeIban(a.iban),
                );

                setSourceAccountId(
                    String(
                        matching?.id ??
                            fallback?.id ??
                            anyDefault?.id ??
                            payload.company_accounts[0]?.id ??
                            '',
                    ),
                );
            })
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    setError('Nu am putut pregăti exportul. Încearcă din nou.');
                }
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [open, request]);

    const sourceAccount = useMemo(
        () =>
            data?.company_accounts.find(
                (a) => String(a.id) === sourceAccountId,
            ) ?? null,
        [data, sourceAccountId],
    );

    const includedRows = useMemo(
        () => rows.filter((r) => includedIds.has(r.invoice_id)),
        [rows, includedIds],
    );

    const updateRow = (invoiceId: number, patch: Partial<BtRow>) => {
        setRows((prev) =>
            prev.map((r) =>
                r.invoice_id === invoiceId ? { ...r, ...patch } : r,
            ),
        );
    };

    const toggleRow = (invoiceId: number) => {
        setIncludedIds((prev) => {
            const next = new Set(prev);

            if (next.has(invoiceId)) {
                next.delete(invoiceId);
            } else {
                next.add(invoiceId);
            }

            return next;
        });
    };

    const canDownload =
        sourceAccount !== null &&
        includedRows.length > 0 &&
        includedRows.every(
            (r) =>
                !!r.target_account_number &&
                r.amount > 0 &&
                !!r.beneficiary_name &&
                !!r.value_date,
        );

    const handleDownload = () => {
        if (!sourceAccount || includedRows.length === 0) {
            return;
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = PaymentExportController.btDownload().url;

        const csrf = document.querySelector<HTMLMetaElement>(
            'meta[name="csrf-token"]',
        )?.content;

        if (csrf) {
            appendInput(form, '_token', csrf);
        }

        appendInput(form, 'source_account', sourceAccount.iban);

        includedRows.forEach((row, idx) => {
            const prefix = `rows[${idx}]`;
            appendInput(
                form,
                `${prefix}[beneficiary_name]`,
                row.beneficiary_name,
            );
            appendInput(
                form,
                `${prefix}[target_account_number]`,
                row.target_account_number ?? '',
            );
            appendInput(
                form,
                `${prefix}[beneficiary_bank_bic]`,
                row.beneficiary_bank_bic ?? '',
            );
            appendInput(
                form,
                `${prefix}[beneficiary_fiscal_code]`,
                row.beneficiary_fiscal_code ?? '',
            );
            appendInput(form, `${prefix}[amount]`, String(row.amount));
            appendInput(form, `${prefix}[payment_ref_1]`, row.payment_ref_1);
            appendInput(form, `${prefix}[payment_ref_2]`, row.payment_ref_2);
            appendInput(form, `${prefix}[value_date]`, row.value_date);
            appendInput(form, `${prefix}[urgent]`, row.urgent);
        });

        document.body.appendChild(form);
        form.submit();
        form.remove();

        onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex !max-w-[95vw] flex-col gap-0 p-0 sm:!max-w-[1400px]">
                <DialogHeader className="border-b px-6 py-4">
                    <DialogTitle className="flex items-center gap-2">
                        <BtLogo className="h-6 w-auto text-foreground" />
                        <span>Șablon plată</span>
                    </DialogTitle>
                    <DialogDescription>
                        Verifică, ajustează și descarcă fișierul. Doar rândurile
                        bifate ajung în export.
                    </DialogDescription>
                </DialogHeader>

                <div className="max-h-[calc(90vh-9rem)] overflow-y-auto px-6 py-4">
                    {loading && (
                        <div className="flex items-center justify-center py-10">
                            <Loader2 className="size-6 animate-spin text-muted-foreground" />
                        </div>
                    )}

                    {error && (
                        <div className="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                            {error}
                        </div>
                    )}

                    {data && !loading && (
                        <div className="flex flex-col gap-4">
                            {data.invoices_skipped > 0 && (
                                <div className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm dark:border-amber-700 dark:bg-amber-950/30">
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600" />
                                    <span>
                                        {data.invoices_skipped} factur
                                        {data.invoices_skipped === 1
                                            ? 'ă'
                                            : 'i'}{' '}
                                        nu sunt complet aprobate (bun de plată)
                                        și au fost ignorate.
                                    </span>
                                </div>
                            )}

                            {data.truncated && (
                                <div className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm dark:border-amber-700 dark:bg-amber-950/30">
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600" />
                                    <span>
                                        Limită {data.limit} rânduri pe export.
                                        Restrânge filtrul ca să incluzi tot.
                                    </span>
                                </div>
                            )}

                            <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
                                <div>
                                    <Label className="text-xs text-muted-foreground uppercase">
                                        Cont sursă plată
                                    </Label>
                                    <Select
                                        value={sourceAccountId}
                                        onValueChange={setSourceAccountId}
                                    >
                                        <SelectTrigger className="mt-1 min-h-11">
                                            <SelectValue placeholder="Alege contul…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {data.company_accounts.length ===
                                                0 && (
                                                <SelectItem
                                                    value="none"
                                                    disabled
                                                >
                                                    Nicio companie sincronizată
                                                    cu conturi
                                                </SelectItem>
                                            )}
                                            {data.company_accounts.map((a) => (
                                                <SelectItem
                                                    key={a.id}
                                                    value={String(a.id)}
                                                >
                                                    {a.iban} · {a.currency}
                                                    {a.bank
                                                        ? ` · ${a.bank}`
                                                        : ''}
                                                    {a.is_default
                                                        ? ' · implicit'
                                                        : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Companie:{' '}
                                    <span className="font-medium text-foreground">
                                        {data.company.name}
                                    </span>
                                </div>
                            </div>

                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full text-xs">
                                    <thead className="bg-muted/50 text-left text-muted-foreground uppercase">
                                        <tr>
                                            <th className="w-8 px-2 py-2"></th>
                                            <th className="px-2 py-2">
                                                Beneficiar
                                            </th>
                                            <th className="px-2 py-2">CUI</th>
                                            <th className="px-2 py-2">IBAN</th>
                                            <th className="px-2 py-2">BIC</th>
                                            <th className="px-2 py-2 text-right">
                                                Sumă
                                            </th>
                                            <th className="px-2 py-2">
                                                Detalii 1
                                            </th>
                                            <th className="px-2 py-2">
                                                Detalii 2
                                            </th>
                                            <th className="px-2 py-2">
                                                Dată val.
                                            </th>
                                            <th className="px-2 py-2">
                                                Urgent
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {rows.map((row) => {
                                            const included = includedIds.has(
                                                row.invoice_id,
                                            );
                                            const ibanOptions =
                                                row.supplier_accounts;

                                            return (
                                                <tr
                                                    key={row.invoice_id}
                                                    className={
                                                        included
                                                            ? ''
                                                            : 'opacity-50'
                                                    }
                                                >
                                                    <td className="px-2 py-1.5">
                                                        <Checkbox
                                                            checked={included}
                                                            onCheckedChange={() =>
                                                                toggleRow(
                                                                    row.invoice_id,
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-2 py-1.5">
                                                        <Input
                                                            className="h-8 min-w-[180px]"
                                                            value={
                                                                row.beneficiary_name
                                                            }
                                                            onChange={(e) =>
                                                                updateRow(
                                                                    row.invoice_id,
                                                                    {
                                                                        beneficiary_name:
                                                                            e
                                                                                .target
                                                                                .value,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-2 py-1.5">
                                                        <Input
                                                            className="h-8 w-[110px]"
                                                            value={
                                                                row.beneficiary_fiscal_code
                                                            }
                                                            onChange={(e) =>
                                                                updateRow(
                                                                    row.invoice_id,
                                                                    {
                                                                        beneficiary_fiscal_code:
                                                                            e
                                                                                .target
                                                                                .value,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-2 py-1.5">
                                                        {ibanOptions.length >
                                                        1 ? (
                                                            <Select
                                                                value={
                                                                    row.target_account_number ??
                                                                    ''
                                                                }
                                                                onValueChange={(
                                                                    v,
                                                                ) => {
                                                                    const sel =
                                                                        ibanOptions.find(
                                                                            (
                                                                                a,
                                                                            ) =>
                                                                                a.iban ===
                                                                                v,
                                                                        );
                                                                    updateRow(
                                                                        row.invoice_id,
                                                                        {
                                                                            target_account_number:
                                                                                v,
                                                                            beneficiary_bank_bic:
                                                                                formatBic(
                                                                                    sel?.swift,
                                                                                    sel?.bic,
                                                                                ),
                                                                        },
                                                                    );
                                                                }}
                                                            >
                                                                <SelectTrigger className="h-8 min-w-[260px]">
                                                                    <SelectValue placeholder="—" />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    {ibanOptions.map(
                                                                        (a) => (
                                                                            <SelectItem
                                                                                key={
                                                                                    a.iban
                                                                                }
                                                                                value={
                                                                                    a.iban
                                                                                }
                                                                            >
                                                                                {
                                                                                    a.iban
                                                                                }{' '}
                                                                                ·{' '}
                                                                                {
                                                                                    a.currency
                                                                                }
                                                                                {a.is_default
                                                                                    ? ' · implicit'
                                                                                    : ''}
                                                                            </SelectItem>
                                                                        ),
                                                                    )}
                                                                </SelectContent>
                                                            </Select>
                                                        ) : (
                                                            <Input
                                                                className="h-8 min-w-[260px]"
                                                                value={
                                                                    row.target_account_number ??
                                                                    ''
                                                                }
                                                                onChange={(e) =>
                                                                    updateRow(
                                                                        row.invoice_id,
                                                                        {
                                                                            target_account_number:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                                placeholder={
                                                                    row.warnings
                                                                        .length >
                                                                    0
                                                                        ? row
                                                                              .warnings[0]
                                                                        : ''
                                                                }
                                                            />
                                                        )}
                                                    </td>
                                                    <td className="px-2 py-1.5">
                                                        <Input
                                                            className="h-8 w-[110px]"
                                                            value={
                                                                row.beneficiary_bank_bic ??
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                updateRow(
                                                                    row.invoice_id,
                                                                    {
                                                                        beneficiary_bank_bic:
                                                                            e
                                                                                .target
                                                                                .value,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-2 py-1.5 text-right tabular-nums">
                                                        <Input
                                                            type="number"
                                                            step="0.01"
                                                            className="h-8 w-[110px] text-right"
                                                            value={row.amount}
                                                            onChange={(e) =>
                                                                updateRow(
                                                                    row.invoice_id,
                                                                    {
                                                                        amount:
                                                                            Number(
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            ) ||
                                                                            0,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-2 py-1.5">
                                                        <Input
                                                            className="h-8 min-w-[140px]"
                                                            value={
                                                                row.payment_ref_1
                                                            }
                                                            onChange={(e) =>
                                                                updateRow(
                                                                    row.invoice_id,
                                                                    {
                                                                        payment_ref_1:
                                                                            e
                                                                                .target
                                                                                .value,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-2 py-1.5">
                                                        <Input
                                                            className="h-8 min-w-[140px]"
                                                            value={
                                                                row.payment_ref_2
                                                            }
                                                            onChange={(e) =>
                                                                updateRow(
                                                                    row.invoice_id,
                                                                    {
                                                                        payment_ref_2:
                                                                            e
                                                                                .target
                                                                                .value,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-2 py-1.5">
                                                        <Input
                                                            type="date"
                                                            className="h-8 w-[140px]"
                                                            value={
                                                                row.value_date
                                                            }
                                                            onChange={(e) =>
                                                                updateRow(
                                                                    row.invoice_id,
                                                                    {
                                                                        value_date:
                                                                            e
                                                                                .target
                                                                                .value,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-2 py-1.5">
                                                        <Select
                                                            value={row.urgent}
                                                            onValueChange={(
                                                                v,
                                                            ) =>
                                                                updateRow(
                                                                    row.invoice_id,
                                                                    {
                                                                        urgent: v as
                                                                            | 'F'
                                                                            | 'T',
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger className="h-8 w-[70px]">
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="F">
                                                                    F
                                                                </SelectItem>
                                                                <SelectItem value="T">
                                                                    T
                                                                </SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </div>

                <DialogFooter className="border-t px-6 py-4">
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Anulează
                    </Button>
                    <Button onClick={handleDownload} disabled={!canDownload}>
                        <Download className="size-4" />
                        Descarcă xlsx ({includedRows.length})
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function pickDominantCurrency(rows: BtRow[]): string | null {
    const counts = new Map<string, number>();
    rows.forEach((r) => {
        if (r.currency) {
            counts.set(r.currency, (counts.get(r.currency) ?? 0) + 1);
        }
    });
    let max = 0;
    let pick: string | null = null;
    counts.forEach((count, currency) => {
        if (count > max) {
            max = count;
            pick = currency;
        }
    });

    return pick;
}

function formatBic(
    swift?: string | null,
    bicShort?: string | null,
): string | null {
    const s = (swift ?? '').trim();
    const b = (bicShort ?? '').trim();

    if (!s && !b) {
        return null;
    }

    const base = s || b;

    return base.length === 8 ? `${base}XXX` : base;
}

function normalizeIban(iban: string): boolean {
    return /^[A-Z]{2}\d{2}[A-Z0-9]{4,}$/i.test(iban.replace(/\s+/g, ''));
}

function appendInput(form: HTMLFormElement, name: string, value: string) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
}
