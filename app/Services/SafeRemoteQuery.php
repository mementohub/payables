<?php

namespace App\Services;

use App\Models\AiQueryLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SafeRemoteQuery
{
    public const MAX_ROWS = 500;

    public const STATEMENT_TIMEOUT_MS = 10_000;

    private const FORBIDDEN = [
        'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
        'grant', 'revoke', 'copy', 'vacuum', 'analyze', 'reindex', 'cluster',
        'lock', 'comment', 'call', 'do', 'merge', 'execute',
    ];

    public function __construct(private RemoteConnection $connection) {}

    /**
     * @return array{rows: array<int, array<string, mixed>>, truncated: bool, row_count: int, duration_ms: int}
     */
    public function run(Company $company, string $sql, ?User $user = null, ?string $conversationId = null): array
    {
        $sql = trim($sql);
        $this->guardShape($sql);

        $bounded = $this->ensureLimit($sql);

        $start = microtime(true);
        $error = null;
        $rows = [];
        $truncated = false;

        try {
            $connection = $this->connection->connection($company);
            $connection->statement('SET statement_timeout = '.self::STATEMENT_TIMEOUT_MS);
            $rows = $connection->select($bounded);
            $rows = array_map(fn ($r) => (array) $r, $rows);

            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            Log::warning('AI SQL query failed', [
                'company_id' => $company->id,
                'sql' => $bounded,
                'error' => $error,
            ]);
            throw new RuntimeException("Eroare la interogare: {$error}", 0, $e);
        } finally {
            $duration = (int) ((microtime(true) - $start) * 1000);

            AiQueryLog::create([
                'user_id' => $user?->id,
                'company_id' => $company->id,
                'conversation_id' => $conversationId,
                'sql' => $bounded,
                'row_count' => count($rows),
                'duration_ms' => $duration,
                'truncated' => $truncated,
                'error' => $error,
            ]);
        }

        return [
            'rows' => $rows,
            'truncated' => $truncated,
            'row_count' => count($rows),
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
        ];
    }

    private function guardShape(string $sql): void
    {
        if ($sql === '') {
            throw new InvalidArgumentException('SQL vid.');
        }

        if (str_contains($sql, ';') && preg_match('/;\s*\S/', $sql)) {
            throw new InvalidArgumentException('Interogarea trebuie să fie o singură instrucțiune SELECT.');
        }

        $stripped = preg_replace('/--[^\n]*\n?|\/\*.*?\*\//s', ' ', $sql) ?? $sql;
        $normalized = strtolower($stripped);

        if (! preg_match('/^\s*(with|select)\b/i', $normalized)) {
            throw new InvalidArgumentException('Doar SELECT / WITH … SELECT sunt permise.');
        }

        foreach (self::FORBIDDEN as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $normalized)) {
                throw new InvalidArgumentException("Cuvânt interzis în interogare: {$keyword}.");
            }
        }
    }

    private function ensureLimit(string $sql): string
    {
        $trimmed = rtrim($sql, "; \t\n\r\0\x0B");

        if (preg_match('/\blimit\s+\d+/i', $trimmed)) {
            return $trimmed;
        }

        return $trimmed.' LIMIT '.self::MAX_ROWS;
    }
}
