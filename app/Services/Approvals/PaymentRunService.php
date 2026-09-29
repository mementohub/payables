<?php

namespace App\Services\Approvals;

use App\Models\CashFlowSnapshot;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\PaymentRun;
use App\Models\PaymentRunItem;
use App\Models\User;
use App\Services\CashFlow\OmcCashFlowReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The weekly payment run: the list Treasury pays.
 *
 * It takes only invoices Top Management has already approved, due by the
 * date asked for and not in another run. Nothing is decided here — the
 * approval happens in Aprobări, invoice by invoice; a run that could also
 * approve meant the same decision had two doors.
 *
 *   approved  the list is ready: every invoice in it is approved;
 *   exported  Treasury sent it to the bank;
 *   closed    every invoice in it is paid in OMC (or it was closed by hand).
 *
 * `review` and `final` survive for the runs built under the old rules.
 */
class PaymentRunService
{
    public function __construct(private InvoiceWorkflow $workflow) {}

    public function create(Company $company, Carbon $payDate, Carbon $dueUntil, User $user, ?string $note = null): PaymentRun
    {
        $this->authorize($user, User::ROLE_FINANCE, 'Rulajele de plată le pregătește Financiar.');

        return DB::transaction(function () use ($company, $payDate, $dueUntil, $user, $note) {
            $run = PaymentRun::query()->create([
                'company_id' => $company->id,
                'reference' => $this->reference($payDate),
                'pay_date' => $payDate->toDateString(),
                'due_until' => $dueUntil->toDateString(),
                'status' => PaymentRun::APPROVED,
                'note' => $note,
                'created_by_id' => $user->id,
            ]);

            $busy = PaymentRunItem::query()
                ->where('status', PaymentRunItem::INCLUDED)
                ->whereHas('run', fn ($q) => $q->whereIn('status', PaymentRun::ACTIVE)->whereKeyNot($run->id))
                ->pluck('invoice_id');

            $invoices = Invoice::query()
                ->where('company_id', $company->id)
                ->where('partener_type', 'furnizor')
                ->whereNull('omc_removed_at')
                ->where('val_mon', '>', 0)
                ->whereRaw('val_mon - val_mon_paid - val_mon_storno > 0.01')
                ->where(fn ($q) => $q->whereNull('data_scadenta')->orWhere('data_scadenta', '<=', $dueUntil->toDateString()))
                // Doar ce a primit aprobarea finală: rulajul e lista de plată,
                // nu încă un loc unde se decide.
                ->where('approval_status', InvoiceWorkflow::APPROVED)
                ->where(fn ($q) => $q->whereNull('postponed_until')->orWhere('postponed_until', '<=', $payDate->toDateString()))
                ->whereNotIn('id', $busy->all())
                ->with('departmentApprovals:id,invoice_id,department_id,amount,approved_amount,status')
                ->get(['id', 'department_id', 'moneda', 'val_mon', 'val_mon_paid', 'val_mon_storno', 'approved_amount']);

            $now = now();

            foreach ($invoices->chunk(500) as $chunk) {
                PaymentRunItem::query()->insert($chunk->map(fn (Invoice $invoice) => [
                    'payment_run_id' => $run->id,
                    'invoice_id' => $invoice->id,
                    'department_id' => $invoice->department_id,
                    // Cât s-a aprobat, nu cât e factura: o aprobare parțială
                    // trimite la bancă doar partea bună de plată.
                    'amount' => $invoice->approvedForPayment(),
                    'currency' => $invoice->moneda,
                    'status' => PaymentRunItem::INCLUDED,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all());
            }

            return $this->recompute($run);
        });
    }

    /**
     * Where the run stands, from the invoices still in it.
     */
    public function recompute(PaymentRun $run): PaymentRun
    {
        if (! in_array($run->status, [PaymentRun::REVIEW, PaymentRun::FINAL, PaymentRun::EXPORTED], true)) {
            return $run;
        }

        $statuses = Invoice::query()
            ->whereIn('id', $run->items()->where('status', PaymentRunItem::INCLUDED)->select('invoice_id'))
            ->get(['id', 'approval_status', 'val_mon', 'val_mon_paid', 'val_mon_storno']);

        if ($run->status === PaymentRun::EXPORTED) {
            if ($statuses->isNotEmpty() && $statuses->every(fn (Invoice $invoice) => $invoice->outstandingAmount() <= 0.01)) {
                $run->forceFill(['status' => PaymentRun::CLOSED])->save();
            }

            return $run;
        }

        // Rulajele vechi, construite când se mai aproba din ele, se așază
        // singure: dacă tot ce a rămas în ele e aprobat, sunt gata de plată.
        $ready = $statuses->isNotEmpty() && $statuses->every(fn (Invoice $invoice) => $invoice->approval_status === InvoiceWorkflow::APPROVED);
        $run->forceFill(['status' => $ready ? PaymentRun::APPROVED : PaymentRun::REVIEW])->save();

        return $run;
    }

    /**
     * Take an invoice out of the run (it stays open for the next one), or
     * put it back.
     */
    public function setIncluded(PaymentRun $run, PaymentRunItem $item, bool $included, User $user, ?string $comment = null): void
    {
        $this->authorize($user, User::ROLE_FINANCE, 'Conținutul rulajului îl schimbă Financiar sau Top Management.', User::ROLE_TOP_MANAGEMENT);
        $this->ensureOpen($run);

        $item->forceFill([
            'status' => $included ? PaymentRunItem::INCLUDED : PaymentRunItem::EXCLUDED,
            'comment' => $comment,
            'decided_by_id' => $user->id,
            'decided_at' => now(),
        ])->save();

        $this->event($item->invoice_id, $user, $included ? 'run_included' : 'run_excluded', $comment, $run);
        $this->recompute($run);
    }

    /**
     * The invoices Treasury sends to the bank: in the run and approved.
     *
     * @return list<int>
     */
    public function payableInvoiceIds(PaymentRun $run): array
    {
        return Invoice::query()
            ->whereIn('id', $run->items()->where('status', PaymentRunItem::INCLUDED)->select('invoice_id'))
            ->where('approval_status', InvoiceWorkflow::APPROVED)
            ->whereRaw('val_mon - val_mon_paid - val_mon_storno > 0.01')
            ->pluck('id')
            ->all();
    }

    public function markExported(PaymentRun $run, User $user): void
    {
        $this->authorize($user, User::ROLE_TREASURY, 'Rulajul îl trimite la bancă Trezoreria.');

        if (! in_array($run->status, [PaymentRun::APPROVED, PaymentRun::EXPORTED], true)) {
            throw ValidationException::withMessages(['run' => 'Doar un rulaj aprobat se trimite la bancă.']);
        }

        DB::transaction(function () use ($run, $user) {
            foreach ($this->payableInvoiceIds($run) as $invoiceId) {
                $this->event($invoiceId, $user, 'exported', null, $run);
            }

            $run->forceFill(['status' => PaymentRun::EXPORTED, 'exported_by_id' => $user->id, 'exported_at' => now()])->save();
        });
    }

    public function close(PaymentRun $run, User $user, bool $cancel = false): void
    {
        $this->authorize($user, User::ROLE_FINANCE, 'Rulajul îl închide Financiar.');
        $run->forceFill(['status' => $cancel ? PaymentRun::CANCELLED : PaymentRun::CLOSED])->save();
    }

    /**
     * The run against the treasury: its total in lei and the balance the
     * WCFR forecast expects at the end of the payment week (the forecast
     * already counts the supplier invoices falling due, these included).
     *
     * @return array{total_lei: float, by_currency: array<string, float>, week: ?string, closing: ?float, minimum: ?float, margin: ?float, built_at: ?string}
     */
    public function cashPosition(PaymentRun $run): array
    {
        $snapshot = CashFlowSnapshot::latest();
        $payload = is_array($snapshot?->payload) ? $snapshot->payload : [];
        $fx = (array) ($payload['fx'] ?? []);
        $byCurrency = [];
        $total = 0.0;

        foreach ($run->items()->where('status', PaymentRunItem::INCLUDED)->get(['amount', 'currency']) as $item) {
            $currency = OmcCashFlowReader::currency((string) ($item->currency ?? 'RON'));
            $byCurrency[$currency] = round(($byCurrency[$currency] ?? 0) + $item->amount, 2);
            $total += $item->amount * ($currency === 'RON' ? 1.0 : (float) ($fx[$currency] ?? 1.0));
        }

        $week = null;
        $closing = null;
        $index = null;

        foreach ((array) ($payload['weeks'] ?? []) as $i => $monday) {
            if ($monday <= $run->pay_date->toDateString() && Carbon::parse($monday)->addDays(7)->toDateString() > $run->pay_date->toDateString()) {
                [$week, $index] = [$monday, $i];
            }
        }

        if ($index !== null) {
            $line = collect((array) ($payload['lines'] ?? []))->firstWhere('code', 'E2');
            $closing = isset($line['values'][$index]) ? (float) $line['values'][$index] : null;
        }

        $minimum = isset($payload['params']['thresholds']['minimum']) ? (float) $payload['params']['thresholds']['minimum'] : null;

        return [
            'total_lei' => round($total, 2),
            'by_currency' => $byCurrency,
            'week' => $week,
            'closing' => $closing,
            'minimum' => $minimum,
            'margin' => $closing !== null && $minimum !== null ? round($closing - $minimum, 2) : null,
            'built_at' => $snapshot?->built_at?->toIso8601String(),
        ];
    }

    private function reference(Carbon $payDate): string
    {
        $base = sprintf('Plăți %s S%02d', $payDate->isoFormat('GGGG'), $payDate->isoWeek());
        $reference = $base;
        $n = 2;

        while (PaymentRun::query()->where('reference', $reference)->exists()) {
            $reference = "{$base}-{$n}";
            $n++;
        }

        return $reference;
    }

    /**
     * Cât timp lista n-a plecat la bancă, se poate scoate ceva din ea — o
     * factură pe care se așteaptă o notă de credit, una pe care se vrea plata
     * săptămâna viitoare. După trimitere, nu se mai umblă la ea.
     */
    private function ensureOpen(PaymentRun $run): void
    {
        if (! in_array($run->status, [PaymentRun::REVIEW, PaymentRun::FINAL, PaymentRun::APPROVED], true)) {
            throw ValidationException::withMessages(['run' => 'Rulajul a plecat la bancă; conținutul lui nu se mai schimbă.']);
        }
    }

    private function authorize(User $user, string $role, string $message, ?string $alsoRole = null): void
    {
        if (! $user->hasRole($role) && ($alsoRole === null || ! $user->hasRole($alsoRole))) {
            throw new AuthorizationException($message);
        }
    }

    private function event(int $invoiceId, User $user, string $type, ?string $body, PaymentRun $run): void
    {
        InvoiceEvent::query()->create([
            'invoice_id' => $invoiceId,
            'user_id' => $user->id,
            'type' => $type,
            'body' => $body,
            'payload' => ['run' => $run->reference, 'run_id' => $run->id],
        ]);
    }
}
