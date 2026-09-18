<?php

namespace App\Services\CashFlow;

use App\Models\CashFlowDetail;
use App\Models\CashFlowSnapshot;
use Illuminate\Support\Str;

/**
 * Keeps what the cash-flow build lays on every line and week (the booking
 * tranches, supplier services, rotations, invoices and payments), written
 * in batches under the build's token while it runs and tied to the
 * snapshot once saved. A source that fails takes its pieces back with it.
 */
class CashFlowDetailRecorder
{
    private const BATCH = 1000;

    private ?string $token = null;

    private string $source = 'build';

    /** Whether the pieces recorded now are actual flows (past weeks). */
    private bool $actual = false;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private int $count = 0;

    public function start(): void
    {
        // Pieces of a build that never finished.
        CashFlowDetail::query()->whereNull('cash_flow_snapshot_id')->delete();

        $this->token = (string) Str::uuid();
        $this->buffer = [];
        $this->count = 0;
        $this->source = 'build';
        $this->actual = false;
    }

    public function recording(): bool
    {
        return $this->token !== null;
    }

    /**
     * The source the next pieces belong to.
     */
    public function source(string $source, bool $actual = false): void
    {
        $this->source = mb_substr($source, 0, 24);
        $this->actual = $actual;
    }

    /**
     * @param  array{group?: ?string, reference?: string|int|null, date?: ?string, currency?: ?string, amount?: ?float, meta?: array<string, mixed>}  $detail
     */
    public function record(string $line, string $week, string $kind, string $label, float $lei, array $detail = []): void
    {
        if ($this->token === null || abs($lei) < 0.005) {
            return;
        }

        $reference = $detail['reference'] ?? null;

        $this->buffer[] = [
            'build_token' => $this->token,
            'source' => $this->source,
            'line' => $line,
            'week' => $week,
            'actual' => $this->actual,
            'kind' => mb_substr($kind, 0, 24),
            'group' => isset($detail['group']) ? mb_substr((string) $detail['group'], 0, 60) : null,
            'label' => mb_substr(trim($label) !== '' ? trim($label) : '—', 0, 191),
            'reference' => $reference !== null && $reference !== '' ? mb_substr((string) $reference, 0, 191) : null,
            'date' => $detail['date'] ?? null,
            'currency' => isset($detail['currency']) ? mb_substr((string) $detail['currency'], 0, 5) : null,
            'amount' => isset($detail['amount']) ? round((float) $detail['amount'], 2) : null,
            'lei' => round($lei, 2),
            'meta' => isset($detail['meta']) && $detail['meta'] !== [] ? json_encode($detail['meta'], JSON_UNESCAPED_UNICODE) : null,
        ];
        $this->count++;

        if (count($this->buffer) >= self::BATCH) {
            $this->flush();
        }
    }

    /**
     * Drop the pieces of a source that failed half way.
     */
    public function forget(string $source): void
    {
        if ($this->token === null) {
            return;
        }

        $before = count($this->buffer);
        $this->buffer = array_values(array_filter($this->buffer, fn (array $row) => $row['source'] !== $source));
        $this->count -= $before - count($this->buffer);
        $this->count -= CashFlowDetail::query()->where('build_token', $this->token)->where('source', $source)->delete();
    }

    /**
     * Tie the build's pieces to its snapshot and let the older snapshots'
     * go beyond the ones kept.
     */
    public function attach(CashFlowSnapshot $snapshot): int
    {
        if ($this->token === null) {
            return 0;
        }

        $this->flush();
        CashFlowDetail::query()
            ->where('build_token', $this->token)
            ->update(['cash_flow_snapshot_id' => $snapshot->id, 'build_token' => null]);

        $count = $this->count;
        $this->token = null;
        $this->prune();

        return $count;
    }

    public function discard(): void
    {
        if ($this->token !== null) {
            CashFlowDetail::query()->where('build_token', $this->token)->delete();
        }

        $this->token = null;
        $this->buffer = [];
        $this->count = 0;
    }

    private function flush(): void
    {
        if ($this->buffer !== []) {
            CashFlowDetail::query()->insert($this->buffer);
            $this->buffer = [];
        }
    }

    private function prune(): void
    {
        $keep = CashFlowSnapshot::query()
            ->orderByDesc('built_at')
            ->orderByDesc('id')
            ->limit(max(1, (int) config('cashflow.keep_details', 10)))
            ->pluck('id');

        CashFlowDetail::query()
            ->whereNotNull('cash_flow_snapshot_id')
            ->whereNotIn('cash_flow_snapshot_id', $keep)
            ->distinct()
            ->pluck('cash_flow_snapshot_id')
            ->each(fn (int $id) => CashFlowDetail::query()->where('cash_flow_snapshot_id', $id)->delete());
    }
}
