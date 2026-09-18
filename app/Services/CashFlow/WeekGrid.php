<?php

namespace App\Services\CashFlow;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The columns of the report: consecutive Monday-to-Sunday weeks, the first
 * one being the week the report is built in (S+1 in the printed report).
 */
final class WeekGrid
{
    public readonly CarbonImmutable $start;

    public function __construct(CarbonInterface $start, public readonly int $weeks)
    {
        $this->start = CarbonImmutable::instance($start)->startOfDay()->startOfWeek(CarbonInterface::MONDAY);
    }

    public static function fromToday(?CarbonInterface $today = null, ?int $weeks = null): self
    {
        $today ??= CarbonImmutable::now((string) config('cashflow.timezone', 'Europe/Bucharest'));

        return new self($today, $weeks ?? (int) config('cashflow.weeks', 52));
    }

    /** First day after the horizon. */
    public function end(): CarbonImmutable
    {
        return $this->start->addWeeks($this->weeks);
    }

    public function monday(int $index): CarbonImmutable
    {
        return $this->start->addWeeks($index);
    }

    /** The same week one year earlier (52 weeks back keeps the weekday). */
    public function lastYearMonday(int $index): CarbonImmutable
    {
        return $this->monday($index)->subWeeks(52);
    }

    /**
     * @return list<string>
     */
    public function mondays(): array
    {
        return array_map(fn (int $i) => $this->monday($i)->toDateString(), range(0, $this->weeks - 1));
    }

    /**
     * Column of a date, or null when it falls outside the horizon.
     */
    public function index(CarbonInterface|string|null $date): ?int
    {
        if ($date === null) {
            return null;
        }

        $day = CarbonImmutable::parse(is_string($date) ? $date : $date->toDateTimeString())->startOfDay();

        if ($day->lt($this->start)) {
            return null;
        }

        $index = intdiv((int) $this->start->diffInDays($day), 7);

        return $index < $this->weeks ? $index : null;
    }

    /**
     * @return list<float>
     */
    public function zeros(): array
    {
        return array_fill(0, $this->weeks, 0.0);
    }

    /**
     * Add an amount to the column of a date; amounts before the horizon go
     * to the first column when $carryEarly is set, later ones are dropped
     * (and returned so the caller can report them).
     *
     * @param  list<float>  $series
     */
    public function add(array &$series, CarbonInterface|string|null $date, float $amount, bool $carryEarly = false): bool
    {
        $index = $this->column($date, $carryEarly);

        if ($index === null) {
            return false;
        }

        $series[$index] += $amount;

        return true;
    }

    /**
     * The column add() puts a date in: its week, or the first one for an
     * earlier date when $carryEarly is set; null outside the horizon.
     */
    public function column(CarbonInterface|string|null $date, bool $carryEarly = false): ?int
    {
        $index = $this->index($date);

        if ($index === null && $carryEarly && $date !== null && CarbonImmutable::parse(is_string($date) ? $date : $date->toDateTimeString())->lt($this->start)) {
            return 0;
        }

        return $index;
    }
}
