import { Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import PaymentCheckResult, {
    FALLBACK_CURRENCIES,
    PaymentCheckSkeleton,
    fetchCheck,
} from '@/components/invoice-payment-check';
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
import type { PaymentCheck } from '@/types/payment-check';

export default function PaymentCheckPanel({
    partnerId,
    companyId,
    partnerName,
}: {
    partnerId: number;
    companyId: number;
    partnerName: string;
}) {
    const [amount, setAmount] = useState('');
    const [currency, setCurrency] = useState('');
    const [check, setCheck] = useState<PaymentCheck | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const currencies = useMemo(() => {
        const known = check?.currencies ?? [];

        return known.length > 0 ? known : FALLBACK_CURRENCIES;
    }, [check]);

    const selectedCurrency = currency || currencies[0];

    useEffect(() => {
        let cancelled = false;

        fetchCheck(partnerId, {})
            .then((data) => {
                if (!cancelled) {
                    setCheck(data);
                }
            })
            .catch((err: Error) => {
                if (!cancelled) {
                    setError(err.message);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [partnerId]);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const requested = amount.trim();

        if (!requested) {
            return;
        }

        setLoading(true);
        setError(null);

        fetchCheck(partnerId, { amount: requested, currency: selectedCurrency })
            .then(setCheck)
            .catch((err: Error) => setError(err.message))
            .finally(() => setLoading(false));
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Verificare cerere de plată</CardTitle>
                <CardDescription>
                    Facturile neachitate din ERP, ultimele 24 de luni și tiparul
                    lunar, ca să vezi dacă suma cerută este o factură
                    înregistrată și dacă se încadrează în tipar.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="payment-check-amount">
                            Suma cerută
                        </Label>
                        <Input
                            id="payment-check-amount"
                            type="number"
                            step="0.01"
                            min="0"
                            inputMode="decimal"
                            placeholder="ex. 20431.86"
                            value={amount}
                            onChange={(event) => setAmount(event.target.value)}
                            className="w-44"
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="payment-check-currency">Monedă</Label>
                        <Select
                            value={selectedCurrency}
                            onValueChange={setCurrency}
                        >
                            <SelectTrigger
                                id="payment-check-currency"
                                className="w-28"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {currencies.map((code) => (
                                    <SelectItem key={code} value={code}>
                                        {code}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <Button type="submit" disabled={loading || !amount}>
                        <Search />
                        Verifică
                    </Button>
                </form>

                {error && (
                    <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                        {error}
                    </p>
                )}

                {loading && !check ? <PaymentCheckSkeleton /> : null}

                {check && (
                    <PaymentCheckResult
                        check={check}
                        companyId={companyId}
                        partnerId={partnerId}
                        partnerName={partnerName}
                    />
                )}
            </CardContent>
        </Card>
    );
}
