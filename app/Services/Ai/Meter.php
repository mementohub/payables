<?php

namespace App\Services\Ai;

use App\Models\AiUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Cântarul: cât a costat o întrebare și cât s-a strâns luna asta.
 *
 * Furnizorul nu trimite prețul, ci numărul de token-i citiți și scriși;
 * prețul pe milion stă în `config/ai_costs.php`. Dacă modelul lipsește de
 * acolo, se trece numărul de token-i și se spune cinstit că prețul nu e
 * știut — mai bine o lipsă văzută decât o cifră inventată.
 */
class Meter
{
    /**
     * Scrie ce a costat răspunsul și întoarce socoteala pentru ochiul omului.
     *
     * @return array{usd: ?float, lei: ?float, tokens_in: int, tokens_out: int, model: ?string, month_usd: float, month_lei: float}
     */
    public function record(string $agent, AgentResponse $response, ?int $contractId = null): array
    {
        $model = $response->meta->model;
        $usage = $response->usage;

        $cached = $usage->cacheReadInputTokens;
        $fresh = max(0, $usage->promptTokens - $cached);
        $usd = $this->cost($model, $fresh, $cached, $usage->completionTokens);

        AiUsage::query()->create([
            'user_id' => Auth::id(),
            'contract_id' => $contractId,
            'agent' => class_basename($agent),
            'model' => $model,
            'tokens_in' => $usage->promptTokens,
            'tokens_out' => $usage->completionTokens,
            'tokens_cached' => $cached,
            'cost_usd' => $usd,
        ]);

        $month = $this->month();

        return [
            'usd' => $usd,
            'lei' => $usd === null ? null : round($usd * $this->rate(), 4),
            'tokens_in' => $usage->promptTokens,
            'tokens_out' => $usage->completionTokens,
            'model' => $model,
            'month_usd' => $month,
            'month_lei' => round($month * $this->rate(), 2),
        ];
    }

    /** Cât s-a cheltuit de la întâi ale lunii, pe toți agenții. */
    public function month(): float
    {
        return round((float) AiUsage::query()
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('cost_usd'), 6);
    }

    /** Prețul unui răspuns, în dolari. Null dacă modelul nu e în listă. */
    private function cost(?string $model, int $fresh, int $cached, int $out): ?float
    {
        $price = $this->price($model);

        if ($price === null) {
            return null;
        }

        $cost = $fresh / 1_000_000 * $price['in']
            + $cached / 1_000_000 * ($price['cached_in'] ?? $price['in'])
            + $out / 1_000_000 * $price['out'];

        return round($cost, 6);
    }

    /**
     * Prețul modelului. Furnizorul întoarce numele cu data zilei în coadă
     * („gpt-5.4-2026-03-05”), iar în listă stă numele gol: se caută cea mai
     * lungă potrivire de la început, ca o versiune anume să-și poată avea
     * prețul ei dacă vrea cineva.
     *
     * @return array{in: float, out: float, cached_in?: float}|null
     */
    private function price(?string $model): ?array
    {
        if ($model === null) {
            return null;
        }

        $found = null;
        $length = 0;

        foreach ((array) config('ai_costs.prices', []) as $name => $price) {
            if (str_starts_with($model, (string) $name) && strlen((string) $name) > $length) {
                $found = $price;
                $length = strlen((string) $name);
            }
        }

        return $found;
    }

    private function rate(): float
    {
        return (float) config('ai_costs.usd_ron', 4.6);
    }
}
