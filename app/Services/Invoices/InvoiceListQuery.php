<?php

namespace App\Services\Invoices;

use App\Models\Builders\InvoiceBuilder;
use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoiceListQuery
{
    /**
     * @return array{search: ?string, company_id: ?int, payment: ?string, data_doc_from: ?string, data_doc_to: ?string, data_scadenta_from: ?string, data_scadenta_to: ?string, approval: ?string, department_id: ?int, partner_id: ?int}
     */
    public static function parseFilters(Request $request): array
    {
        $companyId = $request->exists('company_id')
            ? ($request->integer('company_id') ?: null)
            : ((int) session('active_company_id') ?: null);

        return [
            'search' => $request->string('search')->toString() ?: null,
            'company_id' => $companyId,
            'payment' => $request->string('payment')->toString() ?: null,
            'data_doc_from' => $request->string('data_doc_from')->toString() ?: null,
            'data_doc_to' => $request->string('data_doc_to')->toString() ?: null,
            'data_scadenta_from' => $request->string('data_scadenta_from')->toString() ?: null,
            'data_scadenta_to' => $request->string('data_scadenta_to')->toString() ?: null,
            'approval' => $request->string('approval')->toString() ?: null,
            'department_id' => $request->integer('department_id') ?: null,
            'partner_id' => $request->integer('partner_id') ?: null,
        ];
    }

    public static function build(Request $request, string $scope): InvoiceBuilder
    {
        $filters = self::parseFilters($request);

        return Invoice::query()
            ->withListRelations()
            ->forScope($scope)
            ->whereNull('omc_removed_at')
            ->forCompany($filters['company_id'])
            ->dataDocBetween($filters['data_doc_from'], $filters['data_doc_to'])
            ->scadentaBetween($filters['data_scadenta_from'], $filters['data_scadenta_to'])
            ->paymentStatus($filters['payment'])
            ->approvalStatus($filters['approval'])
            ->inDepartment($filters['department_id'])
            ->when($filters['partner_id'], fn ($q, $id) => $q->where('partner_id', $id))
            ->search($filters['search']);
    }
}
