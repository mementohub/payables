<?php

namespace App\Services;

use App\Models\Company;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Probes every database the application is configured to talk to and reports
 * whether it can be reached, along with the driver error when it cannot.
 */
class DatabaseStatusService
{
    public const GROUP_APPLICATION = 'Aplicație';

    public const GROUP_EXTERNAL = 'Baze de date externe';

    public const GROUP_COMPANIES = 'Companii';

    /**
     * Throwaway connection name the probe registers, connects and purges.
     */
    private const PROBE = 'database_status_probe';

    /**
     * @return array<int, array{
     *   name: string, label: string, group: string,
     *   driver: string|null, host: string|null, port: string|null,
     *   database: string|null, username: string|null, schema: string|null,
     *   connected: bool, latency_ms: float|null,
     *   error: string|null, error_class: string|null
     * }>
     */
    public function statuses(): array
    {
        return [
            $this->applicationStatus(),
            ...$this->externalStatuses(),
            ...$this->companyStatuses(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationStatus(): array
    {
        $name = (string) config('database.default');

        return $this->status($name, 'Baza de date a aplicației', self::GROUP_APPLICATION, $this->namedConfig($name));
    }

    /**
     * The named PostgreSQL connections listed in `database.status.connections`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function externalStatuses(): array
    {
        $connections = (array) config('database.status.connections', []);

        return collect($connections)
            ->map(fn (string $label, string $name) => $this->status($name, $label, self::GROUP_EXTERNAL, $this->namedConfig($name)))
            ->values()
            ->all();
    }

    /**
     * Per-company ERP connections: the named connection a company is linked
     * to, otherwise the credentials stored in the companies table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function companyStatuses(): array
    {
        return Company::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company) => $this->status(
                "company_{$company->getKey()}",
                $company->erpConnection()
                    ? "{$company->name} · ".config('omc.connections.'.$company->erpConnection())
                    : $company->name,
                self::GROUP_COMPANIES,
                $company->erpConnection()
                    ? $this->namedConfig($company->erpConnection())
                    : fn () => $company->remoteConnectionConfig(),
            ))
            ->all();
    }

    /**
     * @return Closure(): array<string, mixed>
     */
    private function namedConfig(string $name): Closure
    {
        return fn () => config("database.connections.{$name}")
            ?? throw new RuntimeException("Conexiunea „{$name}” nu este definită în config/database.php.");
    }

    /**
     * Resolve the connection config, probe it, and describe the outcome.
     *
     * @param  Closure(): array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    private function status(string $name, string $label, string $group, Closure $resolver): array
    {
        $config = [];
        $startedAt = microtime(true);

        try {
            $config = $resolver();
            $this->connect($config);

            $outcome = [
                'connected' => true,
                'latency_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                'error' => null,
                'error_class' => null,
            ];
        } catch (Throwable $e) {
            $cause = $this->cause($e);

            $outcome = [
                'connected' => false,
                'latency_ms' => null,
                'error' => trim($cause->getMessage()),
                'error_class' => $cause::class,
            ];
        } finally {
            $this->forgetProbe();
        }

        return [...$this->describe($name, $label, $group, $config), ...$outcome];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function connect(array $config): void
    {
        Config::set('database.connections.'.self::PROBE, $this->withTimeout($config));
        DB::purge(self::PROBE);

        DB::connection(self::PROBE)->select('select 1');
    }

    /**
     * Cap the connection attempt so an unreachable host cannot stall the page.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withTimeout(array $config): array
    {
        $config['options'] = ((array) ($config['options'] ?? [])) + [
            PDO::ATTR_TIMEOUT => max(1, (int) config('database.status.timeout', 5)),
        ];

        return $config;
    }

    private function forgetProbe(): void
    {
        DB::purge(self::PROBE);

        $connections = (array) config('database.connections', []);
        unset($connections[self::PROBE]);

        Config::set('database.connections', $connections);
    }

    /**
     * Laravel wraps driver failures in a QueryException that mentions the
     * throwaway probe connection, so report the underlying driver exception.
     */
    private function cause(Throwable $e): Throwable
    {
        return $e->getPrevious() ?? $e;
    }

    /**
     * Connection details safe to show in the UI. The password is never included.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function describe(string $name, string $label, string $group, array $config): array
    {
        return [
            'name' => $name,
            'label' => $label,
            'group' => $group,
            'driver' => $this->stringOrNull($config['driver'] ?? null),
            'host' => $this->stringOrNull($config['host'] ?? null),
            'port' => $this->stringOrNull($config['port'] ?? null),
            'database' => $this->stringOrNull($config['database'] ?? null),
            'username' => $this->stringOrNull($config['username'] ?? null),
            'schema' => $this->stringOrNull($config['search_path'] ?? $config['schema'] ?? null),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return blank($value) ? null : (string) $value;
    }
}
