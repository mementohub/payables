<?php

namespace App\Ai\Tools;

use App\Models\Company;
use App\Services\SafeRemoteQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class RunQuery implements Tool
{
    public function __construct(private SafeRemoteQuery $runner) {}

    public function description(): Stringable|string
    {
        return 'Execută un SELECT (sau WITH … SELECT) PostgreSQL read-only pe baza remote a unei companii Centrofin. '
            .'Regulile: o singură instrucțiune, doar SELECT, fără INSERT/UPDATE/DELETE/DDL, LIMIT maxim '
            .SafeRemoteQuery::MAX_ROWS.' (aplicat automat dacă lipsește), timeout '
            .(SafeRemoteQuery::STATEMENT_TIMEOUT_MS / 1000).'s. Răspunsul conține maxim '
            .SafeRemoteQuery::MAX_ROWS.' rânduri și un flag truncated dacă a fost limitat. '
            .'Obligatoriu: apelează ListCompanies întâi ca să obții company_id.';
    }

    public function handle(Request $request): Stringable|string
    {
        $companyId = (int) ($request['company_id'] ?? 0);
        $sql = (string) ($request['sql'] ?? '');

        $company = Company::find($companyId);
        if (! $company) {
            return json_encode(['error' => "Company id {$companyId} nu există."]);
        }

        try {
            $result = $this->runner->run(
                company: $company,
                sql: $sql,
                user: request()->user(),
                conversationId: null,
            );
        } catch (Throwable $e) {
            return json_encode([
                'error' => $e->getMessage(),
                'sql' => $sql,
            ], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'company' => ['id' => $company->id, 'name' => $company->name],
            'row_count' => $result['row_count'],
            'truncated' => $result['truncated'],
            'duration_ms' => $result['duration_ms'],
            'rows' => $result['rows'],
        ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->integer()->required()
                ->description('ID-ul companiei Centrofin (din ListCompanies).'),
            'sql' => $schema->string()->required()
                ->description('O singură instrucțiune SELECT PostgreSQL. Fără DML/DDL. Folosește coloane din DescribeSchema.'),
        ];
    }
}
