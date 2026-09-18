<?php

namespace App\Services\Routing;

use App\Models\AssignmentRule;
use App\Models\CharterContract;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\InvoiceEvent;
use App\Models\InvoiceLineDepartment;
use App\Models\User;
use App\Services\Approvals\InvoiceWorkflow;
use App\Services\CashFlow\ActualCashFlowClassifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends every line of a supplier invoice to a department, first rule that
 * applies:
 *
 *  1. the OMC cost centre on the line (loc), through the loc rules;
 *  2. a charter contract's counterparty → Charters (its lines carry the
 *     rotation, not a booking);
 *  3. a Tina ticket (TN_…) → Ticketing, or Corporate if booked there;
 *  4. the eTrip booking on the line → the product category that sold it,
 *     with the booking's sales channel;
 *  5. the office the invoice was booked at, through the office rules;
 *  6. a rule for the supplier;
 *  7. where the supplier's past lines on the same account went, then where
 *     its past lines went at all;
 *  8. an account rule;
 *
 * otherwise the line waits for someone to route it. Lines routed by hand
 * are left alone. For lines the cost centre decided, what rules 2–8 would
 * have said is kept too: how often they agree is how far the routing can
 * be trusted before the accountants tag an invoice.
 */
class DepartmentAssigner
{
    /** @var array<string, int> */
    private array $departments = [];

    /** @var array<string, Collection<int, AssignmentRule>> */
    private array $rules = [];

    /** @var array<string, true> */
    private array $charterPartners = [];

    /** @var array<string, int> partner|account → department */
    private array $accountHistory = [];

    /** @var array<int, int> partner → department */
    private array $partnerHistory = [];

    public function __construct(private BookingResolver $bookings, private InvoiceWorkflow $workflow) {}

    /**
     * Route the invoices that have never been routed or that OMC changed
     * since; with `$since`, every invoice dated from then on as well.
     *
     * @param  callable(string): void|null  $progress
     * @return array{invoices: int, lines: int, unassigned: int}
     */
    public function assignPending(?Carbon $since = null, ?callable $progress = null): array
    {
        $this->load();
        $totals = ['invoices' => 0, 'lines' => 0, 'unassigned' => 0];

        Invoice::query()
            ->whereIn('partener_type', ['furnizor'])
            ->whereNull('omc_removed_at')
            ->where(function ($query) use ($since) {
                $query->whereNull('assigned_at')
                    ->orWhereColumn('omc_modified_at', '>', 'assigned_at')
                    ->when($since !== null, fn ($q) => $q->orWhere('data_doc', '>=', $since->toDateString()));
            })
            ->select(['id', 'partner_id', 'office', 'com_int'])
            ->with('partner:id,name')
            ->chunkById(500, function (Collection $invoices) use (&$totals, $progress) {
                $result = $this->assignLoaded($invoices);

                foreach ($result as $key => $count) {
                    $totals[$key] += $count;
                }

                if ($progress !== null) {
                    $progress(sprintf('%d facturi, %d linii, %d fără departament', $totals['invoices'], $totals['lines'], $totals['unassigned']));
                }
            });

        return $totals;
    }

    /**
     * Route the given invoices now.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return array{invoices: int, lines: int, unassigned: int}
     */
    public function assign(Collection $invoices): array
    {
        $this->load();

        return $this->assignLoaded((new EloquentCollection($invoices->all()))->loadMissing('partner:id,name'));
    }

    /**
     * Send the whole invoice, or some of its lines, to a department by hand.
     *
     * @param  list<int>|null  $scvs  null for every line
     */
    public function assignManually(Invoice $invoice, Department $department, ?int $userId, ?array $scvs = null): void
    {
        $lines = InvoiceDetail::query()->where('invoice_id', $invoice->id)
            ->when($scvs !== null, fn ($q) => $q->whereIn('scv', $scvs))
            ->get(['scv', 'cant', 'pret']);
        $now = now();

        InvoiceLineDepartment::query()->upsert($lines->map(fn (InvoiceDetail $line) => [
            'invoice_id' => $invoice->id,
            'scv' => $line->scv,
            'amount' => round((float) $line->cant * (float) $line->pret, 2),
            'department_id' => $department->id,
            'channel_id' => null,
            'predicted_department_id' => null,
            'rule' => 'manual',
            'detail' => null,
            'is_manual' => true,
            'assigned_by_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all(), ['invoice_id', 'scv'], ['amount', 'department_id', 'channel_id', 'predicted_department_id', 'rule', 'detail', 'is_manual', 'assigned_by_id', 'updated_at']);

        $this->summarise([$invoice->id]);
    }

    /**
     * A department hands its share of an invoice to another department it
     * was meant for: the lines routed to it move there (as a manual routing,
     * so the rules do not send them back), and that department approves.
     */
    public function redirect(Invoice $invoice, Department $from, Department $to, User $user, string $reason): void
    {
        if (! $user->approvesFor($from)) {
            throw new AuthorizationException('Nu decideți pentru departamentul '.$from->name.'.');
        }

        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_department_id' => 'Alegeți alt departament.']);
        }

        $scvs = InvoiceLineDepartment::query()
            ->where('invoice_id', $invoice->id)
            ->where('department_id', $from->id)
            ->pluck('scv')
            ->all();

        if ($scvs === []) {
            throw ValidationException::withMessages(['department_id' => "Factura {$invoice->nr_doc} nu are nicio linie la {$from->name}."]);
        }

        DB::transaction(function () use ($invoice, $from, $to, $user, $reason, $scvs) {
            $this->assignManually($invoice, $to, $user->id, $scvs);

            InvoiceEvent::query()->create([
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'department_id' => $from->id,
                'type' => 'department_redirected',
                'body' => $reason,
                'payload' => ['to' => $to->name, 'to_id' => $to->id, 'lines' => count($scvs)],
            ]);
        });
    }

    /**
     * Give the lines routed by hand back to the rules.
     */
    public function release(Invoice $invoice): void
    {
        InvoiceLineDepartment::query()->where('invoice_id', $invoice->id)->where('is_manual', true)->delete();
        $invoice->forceFill(['assigned_at' => null])->save();
        $this->assign(collect([$invoice]));
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return array{invoices: int, lines: int, unassigned: int}
     */
    private function assignLoaded(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return ['invoices' => 0, 'lines' => 0, 'unassigned' => 0];
        }

        $byId = $invoices->keyBy('id');
        $lines = InvoiceDetail::query()
            ->whereIn('invoice_id', $byId->keys()->all())
            ->get(['invoice_id', 'scv', 'cant', 'pret', 'account', 'analytic', 'loc', 'com_int']);
        $manual = InvoiceLineDepartment::query()
            ->whereIn('invoice_id', $byId->keys()->all())
            ->where('is_manual', true)
            ->get(['invoice_id', 'scv'])
            ->mapWithKeys(fn (InvoiceLineDepartment $row) => [$row->invoice_id.'|'.$row->scv => true])
            ->all();

        $bookingIds = $lines
            ->map(fn (InvoiceDetail $line) => BookingResolver::bookingId($line->com_int ?? $byId[$line->invoice_id]->com_int))
            ->filter()
            ->values()
            ->all();
        $bookings = $bookingIds === [] ? [] : $this->bookings->resolve($bookingIds);

        $now = now();
        $payload = [];
        $unassigned = 0;

        foreach ($lines as $line) {
            if (isset($manual[$line->invoice_id.'|'.$line->scv])) {
                continue;
            }

            $invoice = $byId[$line->invoice_id];
            [$department, $channel, $rule, $detail, $predicted] = $this->route($invoice, $line, $bookings);
            $unassigned += $department === null ? 1 : 0;

            $payload[] = [
                'invoice_id' => $line->invoice_id,
                'scv' => $line->scv,
                'amount' => round((float) $line->cant * (float) $line->pret, 2),
                'department_id' => $department,
                'channel_id' => $channel,
                'predicted_department_id' => $predicted,
                'rule' => $rule,
                'detail' => $detail !== null ? mb_substr($detail, 0, 255) : null,
                'is_manual' => false,
                'assigned_by_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($byId, $payload) {
            // Lines OMC no longer has (and the automatic routing of the rest)
            // are replaced; the ones routed by hand stay.
            InvoiceLineDepartment::query()->whereIn('invoice_id', $byId->keys()->all())->where('is_manual', false)->delete();

            foreach (array_chunk($payload, 500) as $chunk) {
                InvoiceLineDepartment::query()->insert($chunk);
            }
        });

        $this->summarise($byId->keys()->all());

        return ['invoices' => $byId->count(), 'lines' => count($payload), 'unassigned' => $unassigned];
    }

    /**
     * @param  array<int, array{department: ?string, channel: ?string, detail: string}>  $bookings
     * @return array{0: ?int, 1: ?int, 2: string, 3: ?string, 4: ?int}
     */
    private function route(Invoice $invoice, InvoiceDetail $line, array $bookings): array
    {
        if ($line->loc !== null && ($rule = $this->firstRule('loc', $line->loc)) !== null) {
            [$predicted] = $this->predict($invoice, $line, $bookings);

            return [$rule->department_id, null, 'loc', "Loc de cheltuială OMC: {$line->loc}", $predicted];
        }

        [$department, $channel, $rule, $detail] = $this->predict($invoice, $line, $bookings);

        return [$department, $channel, $rule, $detail, $department];
    }

    /**
     * Rules 2–8.
     *
     * @param  array<int, array{department: ?string, channel: ?string, detail: string}>  $bookings
     * @return array{0: ?int, 1: ?int, 2: string, 3: ?string}
     */
    private function predict(Invoice $invoice, InvoiceDetail $line, array $bookings): array
    {
        $partnerName = $invoice->partner?->name;
        $reference = $line->com_int ?? $invoice->com_int;

        if ($partnerName !== null && isset($this->charterPartners[ActualCashFlowClassifier::normalize($partnerName)])) {
            return [$this->department(config('routing.charter_department')), null, 'charter', "Contract charter cu {$partnerName}"];
        }

        if ($reference !== null && str_starts_with($reference, (string) config('routing.ticket_prefix', 'TN_'))) {
            $corporate = mb_strtolower((string) $invoice->office) === mb_strtolower((string) config('routing.corporate_office'));

            return [
                $this->department(config($corporate ? 'routing.corporate_department' : 'routing.ticketing_department')),
                null,
                'ticket',
                'Bilet Tina '.$reference.($corporate ? ' (birou Corporate)' : ''),
            ];
        }

        $bookingId = BookingResolver::bookingId($reference);

        if ($bookingId !== null && isset($bookings[$bookingId]) && $bookings[$bookingId]['department'] !== null) {
            $booking = $bookings[$bookingId];

            return [$this->department($booking['department']), $this->department($booking['channel']), 'booking', $booking['detail']];
        }

        if ($invoice->office !== null && ($rule = $this->firstRule('office', $invoice->office)) !== null) {
            return [$rule->department_id, null, 'office', "Birou OMC: {$invoice->office}"];
        }

        if ($partnerName !== null && ($rule = $this->firstRule('partner', $partnerName)) !== null) {
            return [$rule->department_id, null, 'partner', "Regulă pentru furnizorul {$partnerName}"];
        }

        $history = $invoice->partner_id !== null
            ? ($this->accountHistory[$invoice->partner_id.'|'.$line->account] ?? $this->partnerHistory[$invoice->partner_id] ?? null)
            : null;

        if ($history !== null) {
            return [$history, null, 'history', 'Unde au mers liniile anterioare ale furnizorului'];
        }

        if ($line->account !== null && ($rule = $this->firstRule('account', $line->account)) !== null) {
            return [$rule->department_id, null, 'account', "Cont {$line->account}"];
        }

        return [null, null, 'none', $bookingId !== null ? "Rezervarea {$bookingId} nu a fost găsită în eTrip" : null];
    }

    /**
     * The department holding most of each invoice's value, and whether all,
     * some or none of its lines are routed.
     *
     * @param  list<int>  $invoiceIds
     */
    private function summarise(array $invoiceIds): void
    {
        $rows = InvoiceLineDepartment::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->get(['invoice_id', 'department_id', 'amount'])
            ->groupBy('invoice_id');
        $now = now();

        foreach ($invoiceIds as $id) {
            $lines = $rows->get($id, collect());
            $routed = $lines->whereNotNull('department_id');
            $top = $routed->groupBy('department_id')->map(fn (Collection $group) => $group->sum(fn ($row) => abs($row->amount)))->sortDesc()->keys()->first();

            Invoice::query()->whereKey($id)->update([
                'department_id' => $top,
                'assignment_state' => $lines->isEmpty() || $routed->isEmpty() ? 'unassigned' : ($routed->count() === $lines->count() ? 'assigned' : 'partial'),
                'assigned_at' => $now,
            ]);
        }

        // The departments that approve follow the routing.
        $this->workflow->refresh($invoiceIds);
    }

    private function load(): void
    {
        $this->departments = Department::idsByCode();
        $this->rules = AssignmentRule::query()->orderByRaw("case when pattern like '~%' then 1 else 0 end")->orderBy('id')->get()->groupBy('kind')->all();
        $this->charterPartners = CharterContract::query()->pluck('counterparty')->filter()
            ->mapWithKeys(fn (string $name) => [ActualCashFlowClassifier::normalize($name) => true])
            ->all();
        $this->loadHistory();
    }

    /**
     * Where each supplier's lines went when the cost centre or a person
     * decided: per supplier and account, and per supplier.
     */
    private function loadHistory(): void
    {
        $share = (float) config('routing.history_share', 0.6);
        $minimum = (int) config('routing.history_min_lines', 3);

        $rows = DB::table('invoice_line_departments as l')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->join('invoice_details as d', function ($join) {
                $join->on('d.invoice_id', '=', 'l.invoice_id')->on('d.scv', '=', 'l.scv');
            })
            ->whereIn('l.rule', ['loc', 'manual'])
            ->whereNotNull('l.department_id')
            ->whereNotNull('i.partner_id')
            ->groupBy('i.partner_id', 'd.account', 'l.department_id')
            ->select(['i.partner_id', 'd.account', 'l.department_id', DB::raw('count(*) as n')])
            ->get();

        $this->accountHistory = $this->dominant($rows->groupBy(fn ($row) => $row->partner_id.'|'.$row->account), $share, $minimum);
        $this->partnerHistory = $this->dominant($rows->groupBy('partner_id'), $share, $minimum);
    }

    /**
     * @param  Collection<array-key, Collection<int, object>>  $groups
     * @return array<array-key, int>
     */
    private function dominant(Collection $groups, float $share, int $minimum): array
    {
        $result = [];

        foreach ($groups as $key => $rows) {
            $byDepartment = $rows->groupBy('department_id')->map(fn (Collection $group) => $group->sum('n'));
            $total = $byDepartment->sum();
            $top = $byDepartment->sortDesc();

            if ($total >= $minimum && $top->first() / $total >= $share) {
                $result[$key] = (int) $top->keys()->first();
            }
        }

        return $result;
    }

    private function firstRule(string $kind, string $value): ?AssignmentRule
    {
        return ($this->rules[$kind] ?? collect())->first(fn (AssignmentRule $rule) => $rule->matches($value));
    }

    private function department(mixed $code): ?int
    {
        return is_string($code) ? ($this->departments[$code] ?? null) : null;
    }
}
