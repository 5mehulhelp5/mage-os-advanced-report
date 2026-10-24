<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Benchmark;

/**
 * Aggregated timings of the concurrent workers: how many executed SQL (cold) and how many were served
 * from the cache while waiting on the cache lock.
 */
final readonly class ConcurrencySummary
{
    /**
     * @param array<int,array{wall_ms: float, cached: bool, sql_statements: int, measured_at_ms: int, late: bool}> $workers
     * @param int $startAtMs
     */
    public function __construct(public array $workers, public int $startAtMs)
    {
    }

    public function executedSql(): int
    {
        return \count(array_filter($this->workers, static fn (array $worker): bool => !$worker['cached']));
    }

    public function servedFromCache(): int
    {
        return \count($this->workers) - $this->executedSql();
    }

    public function lateWorkers(): int
    {
        return \count(array_filter($this->workers, static fn (array $worker): bool => $worker['late']));
    }

    public function maxWallMs(): float
    {
        return max([0.0, ...$this->wallTimes()]);
    }

    public function medianWallMs(): float
    {
        $times = $this->wallTimes();
        sort($times);
        $count = \count($times);
        if ($count === 0) {
            return 0.0;
        }
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $times[$middle] : ($times[$middle - 1] + $times[$middle]) / 2;
    }

    /**
     * @return array{workers: array<int,array<string,mixed>>, summary: array<string,int|float>}
     */
    public function toArray(): array
    {
        return [
            'workers' => $this->workers,
            'summary' => [
                'workers' => \count($this->workers),
                'executed_sql' => $this->executedSql(),
                'served_from_cache' => $this->servedFromCache(),
                'late_workers' => $this->lateWorkers(),
                'max_wall_ms' => $this->maxWallMs(),
                'median_wall_ms' => $this->medianWallMs(),
            ],
        ];
    }

    /**
     * @return float[]
     */
    private function wallTimes(): array
    {
        return array_map(static fn (array $worker): float => $worker['wall_ms'], $this->workers);
    }
}
