<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Benchmark;

use InvalidArgumentException;
use JsonException;
use MageOS\AdvancedReport\Model\Cache\Type;
use RuntimeException;

/**
 * Runs one benchmark scenario in N parallel `bin/magento` worker processes to show whether the cache lock
 * serializes cold misses (one worker executes SQL, the others are served from the cache).
 *
 * The scenario cache key is private to the report runner, so the whole `advanced_report` cache type is
 * cleaned first (this includes server-flavour detector entries). A common start instant makes every worker
 * measure at the same time regardless of its uneven bootstrap time.
 */
class ConcurrentRunner
{
    public const int MIN_CONCURRENCY = 1;
    public const int MAX_CONCURRENCY = 32;
    private const int START_DELAY_MS = 5000;
    private const int LATE_TOLERANCE_MS = 1000;

    public function __construct(
        private readonly WorkerProcessFactory $processFactory,
        private readonly Type $cache,
        private readonly BarrierClock $clock,
    ) {
    }

    /**
     * @param string[] $scenarioArguments Options selecting exactly one scenario (e.g. `--preset=...`).
     * @param int $concurrency
     * @throws InvalidArgumentException When the concurrency is outside 1 to 32.
     * @throws RuntimeException When a worker fails or prints no result.
     */
    public function run(array $scenarioArguments, int $concurrency): ConcurrencySummary
    {
        if ($concurrency < self::MIN_CONCURRENCY || $concurrency > self::MAX_CONCURRENCY) {
            throw new InvalidArgumentException(
                \sprintf('The concurrency must be between %d and %d.', self::MIN_CONCURRENCY, self::MAX_CONCURRENCY)
            );
        }

        $this->cache->clean(\Zend_Cache::CLEANING_MODE_MATCHING_TAG, [Type::CACHE_TAG]);

        $startAt = $this->clock->nowMs() + self::START_DELAY_MS;
        $arguments = [
            'advanced-report:benchmark',
            '--worker',
            '--format=json',
            '--start-at=' . $startAt,
            ...$scenarioArguments,
        ];
        $processes = [];
        for ($i = 0; $i < $concurrency; $i++) {
            $processes[$i] = $this->processFactory->create($arguments);
            $processes[$i]->start();
        }

        $workers = [];
        foreach ($processes as $index => $process) {
            $process->wait();
            if (!$process->isSuccessful()) {
                throw new RuntimeException(\sprintf(
                    'Worker %d failed (exit code %s): %s',
                    $index + 1,
                    (string) $process->getExitCode(),
                    trim($process->getErrorOutput() . ' ' . $process->getOutput())
                ));
            }
            $workers[] = $this->parse($index + 1, $process->getOutput(), $startAt);
        }

        return new ConcurrencySummary($workers, $startAt);
    }

    /**
     * @param int $number
     * @param string $output
     * @param int $startAt
     * @return array{wall_ms: float, cached: bool, sql_statements: int, measured_at_ms: int, late: bool}
     */
    private function parse(int $number, string $output, int $startAt): array
    {
        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $output)),
            static fn (string $line): bool => $line !== ''
        ));
        try {
            $data = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        if (!\is_array($data) || !isset($data['wall_ms'], $data['cached'], $data['sql_statements'], $data['measured_at_ms'])) {
            throw new RuntimeException(\sprintf('Worker %d printed no valid result: %s', $number, trim($output)));
        }

        return [
            'wall_ms' => (float) $data['wall_ms'],
            'cached' => (bool) $data['cached'],
            'sql_statements' => (int) $data['sql_statements'],
            'measured_at_ms' => (int) $data['measured_at_ms'],
            'late' => (int) $data['measured_at_ms'] > $startAt + self::LATE_TOLERANCE_MS,
        ];
    }
}
