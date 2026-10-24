<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Benchmark;

/**
 * One timed report run. Counters the server does not expose are null.
 */
final readonly class Measurement
{
    /**
     * @param float $wallMs
     * @param float $sqlMs
     * @param int $sqlQueryCount Statements run by the report (0 when served from cache).
     * @param int|null $rowsExamined Sum of the Handler_read_* deltas.
     * @param int|null $questions Statements the server counted for the session.
     * @param int|null $innodbRowsRead
     * @param int $peakMemoryBytes
     * @param bool $cached
     * @param int $rowCount
     */
    public function __construct(
        public float $wallMs,
        public float $sqlMs,
        public int $sqlQueryCount,
        public ?int $rowsExamined,
        public ?int $questions,
        public ?int $innodbRowsRead,
        public int $peakMemoryBytes,
        public bool $cached,
        public int $rowCount,
    ) {
    }
}
