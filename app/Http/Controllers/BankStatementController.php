<?php

namespace App\Http\Controllers;

use App\Models\BankStatement;
use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankStatementController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->integer('company_id');
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $onlyUnallocated = $request->boolean('only_unallocated');

        $statements = BankStatement::query()
            ->with('company:id,name')
            ->when($companyId, fn ($q, $id) => $q->where('company_id', $id))
            ->when($from, fn ($q, $d) => $q->where('data_extras', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('data_extras', '<=', $d))
            ->when($onlyUnallocated, fn ($q) => $q->where('unallocated_count', '>', 0))
            ->orderByDesc('data_extras')
            ->orderBy('company_id')
            ->orderBy('iban')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (BankStatement $s) => [
                'id' => $s->id,
                'data_extras' => $s->data_extras?->toDateString(),
                'banca' => $s->banca,
                'iban' => $s->iban,
                'operator' => $s->operator,
                'moneda' => $s->moneda,
                'lines_count' => $s->lines_count,
                'unallocated_count' => $s->unallocated_count,
                'total_incoming' => (float) $s->total_incoming,
                'total_outgoing' => (float) $s->total_outgoing,
                'total_unallocated' => (float) $s->total_unallocated,
                'company' => ['id' => $s->company->id, 'name' => $s->company->name],
            ]);

        return Inertia::render('bank-statements/index', [
            'statements' => $statements,
            'filters' => [
                'company_id' => $companyId ?: null,
                'from' => $from ?: null,
                'to' => $to ?: null,
                'only_unallocated' => $onlyUnallocated,
            ],
            'companies' => Company::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, BankStatement $bankStatement): Response
    {
        $bankStatement->load('company:id,name');

        $onlyUnallocated = $request->boolean('only_unallocated');
        $direction = $request->string('direction')->toString();

        $lines = $bankStatement->lines()
            ->with([
                'partner:id,name',
                'allocations' => fn ($q) => $q->orderBy('data_doc_com')->orderBy('nr_doc_com'),
                'allocations.invoice:id,nr_doc,tip_doc,data_doc,partner_id,val_mon,val_mon_tva,val_mon_paid,moneda',
                'allocations.invoice.partner:id,name',
            ])
            ->when($direction === 'incoming' || $direction === 'outgoing', fn ($q) => $q->where('direction', $direction))
            ->orderBy('data_doc')
            ->orderBy('tip_doc')
            ->orderBy('nr_doc')
            ->get();

        $mappedLines = $lines
            ->map(fn ($line) => [
                'id' => $line->id,
                'data_doc' => $line->data_doc?->toDateString(),
                'tip_doc' => $line->tip_doc,
                'nr_doc' => $line->nr_doc,
                'direction' => $line->direction,
                'partener_name' => $line->partener_name,
                'partner' => $line->partner ? ['id' => $line->partner->id, 'name' => $line->partner->name] : null,
                'emitent' => $line->emitent,
                'cine_preda' => $line->cine_preda,
                'cine_primeste' => $line->cine_primeste,
                'obs_txt' => $line->obs_txt,
                'moneda' => $line->moneda,
                'val_mon' => (float) $line->val_mon,
                'val_allocated' => (float) $line->val_allocated,
                'unallocated' => (float) $line->unallocated,
                'is_unallocated' => $line->is_unallocated,
                'allocations' => $line->allocations->map(fn ($alloc) => [
                    'id' => $alloc->id,
                    'data_doc_com' => $alloc->data_doc_com?->toDateString(),
                    'tip_doc_com' => $alloc->tip_doc_com,
                    'nr_doc_com' => $alloc->nr_doc_com,
                    'val_fin' => (float) $alloc->val_fin,
                    'val_com' => (float) $alloc->val_com,
                    'invoice' => $alloc->invoice ? [
                        'id' => $alloc->invoice->id,
                        'nr_doc' => $alloc->invoice->nr_doc,
                        'tip_doc' => $alloc->invoice->tip_doc,
                        'data_doc' => $alloc->invoice->data_doc?->toDateString(),
                        'moneda' => $alloc->invoice->moneda,
                        'val_mon' => (float) $alloc->invoice->val_mon,
                        'val_mon_tva' => (float) $alloc->invoice->val_mon_tva,
                        'val_mon_paid' => (float) $alloc->invoice->val_mon_paid,
                        'partner' => $alloc->invoice->partner ? [
                            'id' => $alloc->invoice->partner->id,
                            'name' => $alloc->invoice->partner->name,
                        ] : null,
                    ] : null,
                ])->values(),
            ])
            ->when($onlyUnallocated, fn ($c) => $c->filter(fn ($l) => $l['is_unallocated']))
            ->values();

        return Inertia::render('bank-statements/show', [
            'statement' => [
                'id' => $bankStatement->id,
                'data_extras' => $bankStatement->data_extras?->toDateString(),
                'banca' => $bankStatement->banca,
                'iban' => $bankStatement->iban,
                'operator' => $bankStatement->operator,
                'moneda' => $bankStatement->moneda,
                'lines_count' => $bankStatement->lines_count,
                'unallocated_count' => $bankStatement->unallocated_count,
                'total_incoming' => (float) $bankStatement->total_incoming,
                'total_outgoing' => (float) $bankStatement->total_outgoing,
                'total_unallocated' => (float) $bankStatement->total_unallocated,
                'company' => ['id' => $bankStatement->company->id, 'name' => $bankStatement->company->name],
            ],
            'lines' => $mappedLines,
            'filters' => [
                'only_unallocated' => $onlyUnallocated,
                'direction' => $direction ?: null,
            ],
        ]);
    }
}
