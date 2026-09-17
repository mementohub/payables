import { Form, Link } from '@inertiajs/react';
import { ClipboardCheck, Link2, Link2Off } from 'lucide-react';
import { useState } from 'react';
import EtripSupplierController from '@/actions/App/Http/Controllers/EtripSupplierController';
import EtripSupplierPicker from '@/components/etrip-supplier-picker';
import type { EtripSupplierOption } from '@/components/etrip-supplier-picker';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import { index as paymentChecksIndex } from '@/routes/payment-checks';
import type { PartnerDetail } from './types';

const SOURCE_LABELS: Record<string, string> = {
    cui: 'potrivit după CUI',
    name: 'potrivit după nume',
    manual: 'legat manual',
};

export default function EtripSupplierSection({
    partner,
}: {
    partner: PartnerDetail;
}) {
    const [open, setOpen] = useState(false);
    const [picked, setPicked] = useState<EtripSupplierOption | null>(null);
    const linked = partner.etrip_supplier;

    return (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border border-sidebar-border/70 px-4 py-3 text-sm dark:border-sidebar-border">
            <span className="font-medium">Furnizor eTrip</span>

            {linked ? (
                <>
                    <Badge variant="secondary">
                        {linked.name} [{linked.code}]
                    </Badge>
                    {linked.currency && (
                        <span className="text-muted-foreground">
                            {linked.currency}
                        </span>
                    )}
                    {linked.match_source && (
                        <span className="text-xs text-muted-foreground">
                            {SOURCE_LABELS[linked.match_source] ??
                                linked.match_source}
                        </span>
                    )}
                    <Button asChild size="sm" variant="outline">
                        <Link
                            href={paymentChecksIndex({
                                query: {
                                    connection: linked.etrip_connection,
                                    supplier: linked.code,
                                },
                            })}
                        >
                            <ClipboardCheck />
                            Verifică cereri pe check-in
                        </Link>
                    </Button>
                </>
            ) : (
                <span className="text-muted-foreground">
                    Nelegat — cererile pe check-in nu pot fi urmărite pentru
                    acest furnizor.
                </span>
            )}

            <div className="ml-auto flex items-center gap-1">
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild>
                        <Button
                            size="sm"
                            variant={linked ? 'ghost' : 'secondary'}
                        >
                            <Link2 />
                            {linked ? 'Schimbă' : 'Leagă furnizor eTrip'}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Leagă furnizorul eTrip</DialogTitle>
                            <DialogDescription>
                                Alege furnizorul din eTrip care corespunde
                                partenerului {partner.name}. Legătura manuală nu
                                este suprascrisă de sincronizare.
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            {...EtripSupplierController.link.form(partner.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setOpen(false)}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="etrip-supplier-picker">
                                            Furnizor eTrip
                                        </Label>
                                        <EtripSupplierPicker
                                            id="etrip-supplier-picker"
                                            bases={partner.etrip_bases}
                                            value={
                                                picked
                                                    ? {
                                                          connection:
                                                              picked.connection,
                                                          code: picked.code,
                                                      }
                                                    : null
                                            }
                                            onChange={setPicked}
                                        />
                                        <input
                                            type="hidden"
                                            name="etrip_connection"
                                            value={picked?.connection ?? ''}
                                        />
                                        <input
                                            type="hidden"
                                            name="etrip_supplier_code"
                                            value={picked?.code ?? ''}
                                        />
                                        <InputError
                                            message={errors.etrip_supplier_code}
                                        />
                                    </div>
                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setOpen(false)}
                                        >
                                            Anulează
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing || !picked}
                                        >
                                            <Link2 />
                                            Leagă
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>

                {linked && (
                    <Form
                        {...EtripSupplierController.unlink.form(partner.id)}
                        options={{ preserveScroll: true }}
                        onBefore={() =>
                            confirm(`Ștergi legătura cu ${linked.name}?`)
                        }
                    >
                        {({ processing }) => (
                            <Button
                                size="sm"
                                variant="ghost"
                                disabled={processing}
                                title="Dezleagă"
                            >
                                <Link2Off />
                            </Button>
                        )}
                    </Form>
                )}
            </div>
        </div>
    );
}
