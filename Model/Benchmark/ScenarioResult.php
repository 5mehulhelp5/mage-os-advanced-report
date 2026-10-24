<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Benchmark;

/**
 * Cold (cache bypassed) and warm (cache hit) measurements of a scenario.
 */
final readonly class ScenarioResult
{
    private const float P95 = 0.95;

    /**
     * @param Scenario $scenario
     * @param Measurement[] $cold
     * @param Measurement[] $warm
     */
    public function __construct(
        public Scenario $scenario,
        public array $cold,
        public array $warm,
    ) {
    }

    /**
     * @return array{min: float, median: float, p95: float, max: float}|null
     */
    public function coldStats(): ?array
    {
        return $this->stats($this->cold);
    }

    /**
     * @return array{min: float, median: float, p95: float, max: float}|null
     */
    public function warmStats(): ?array
    {
        return $this->stats($this->warm);
    }

    /**
     * @param Measurement[] $measurements
     * @return array{min: float, median: float, p95: float, max: float}|null
     */
    private function stats(array $measurements): ?array
    {
        if ($measurements === []) {
            return null;
        }

        $times = array_map(static fn (Measurement $measurement): float => $measurement->wallMs, $measurements);
        sort($times);
        $count = \count($times);
        $middle = intdiv($count, 2);

        return [
            'min' => $times[0],
            'median' => $count % 2 === 1 ? $times[$middle] : ($times[$middle - 1] + $times[$middle]) / 2,
            'p95' => $times[(int) ceil(self::P95 * $count) - 1],
            'max' => $times[$count - 1],
        ];
    }
}
