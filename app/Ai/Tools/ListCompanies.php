<?php

namespace App\Ai\Tools;

use App\Models\Company;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListCompanies implements Tool
{
    public function description(): Stringable|string
    {
        return 'Returnează companiile disponibile (id, nume, CUI, baza de date remote). '
            .'Folosește-l o singură dată la începutul conversației pentru a alege company_id-ul '
            .'potrivit cu întrebarea utilizatorului; transmite company_id la DescribeSchema și RunQuery.';
    }

    public function handle(Request $request): Stringable|string
    {
        $companies = Company::orderBy('name')
            ->get(['id', 'name', 'cui', 'db_database', 'last_synced_at'])
            ->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'cui' => $c->cui,
                'db' => $c->db_database,
                'last_synced_at' => $c->last_synced_at?->toIso8601String(),
            ])
            ->all();

        return json_encode([
            'companies' => $companies,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
