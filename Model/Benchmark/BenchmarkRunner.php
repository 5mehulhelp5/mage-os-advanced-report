<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Benchmark;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\ReportRunnerInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Query\PresetRequestBuilder;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;

/**
 * Runs a {@see Scenario} through the real report runner: `repeat` cold runs (cache bypassed), then
 * `repeat` warm runs (served from the cache the cold runs filled). Read-only: report runs and
 * `SHOW SESSION STATUS` only.
 *
 * Session counters are read before/after each run. Every reading is itself a statement counted by the
 * server, so the delta of two consecutive readings (the baseline, measured once) is subtracted. With
 * the `default` connection, the cache lock `GET_LOCK`/`RELEASE_LOCK` calls are counted too.
 * Variables the server does not expose (MariaDB differences) give null counters.
 */
class BenchmarkRunner
{
    private const string STATUS_SQL = "SHOW SESSION STATUS WHERE Variable_name IN ('Questions', 'Handler_read_first',"
        . " 'Handler_read_key', 'Handler_read_next', 'Handler_read_rnd', 'Handler_read_rnd_next',"
        . " 'Innodb_rows_read')";

    private const array HANDLER_VARIABLES = [
        'Handler_read_first',
        'Handler_read_key',
        'Handler_read_next',
        'Handler_read_rnd',
        'Handler_read_rnd_next',
    ];

    /**
     * @var array<string,int>|null
     */
    private ?array $baseline = null;

    public function __construct(
        private readonly ReportRunnerInterface $reportRunner,
        private readonly ReportConnection $reportConnection,
        private readonly PresetRequestBuilder $presetRequestBuilder,
        private readonly ReportRequestFactory $reportRequestFactory,
        private readonly DefinitionRegistry $definitionRegistry,
    ) {
    }

    public function run(Scenario $scenario, int $repeat = 1): ScenarioResult
    {
        $cold = [];
        $warm = [];
        for ($i = 0; $i < $repeat; $i++) {
            $cold[] = $this->measure($this->buildRequest($scenario, true));
        }
        for ($i = 0; $i < $repeat; $i++) {
            $warm[] = $this->measure($this->buildRequest($scenario, false));
        }

        return new ScenarioResult($scenario, $cold, $warm);
    }

    /**
     * One measurement of a scenario with the cache enabled: a hit when the cache is warm, a locked cold
     * execution when it is not (used by the concurrent benchmark workers).
     */
    public function runOnce(Scenario $scenario): Measurement
    {
        return $this->measure($this->buildRequest($scenario, false));
    }

    private function measure(ReportRequestInterface $request): Measurement
    {
        $baseline = $this->getBaseline();
        memory_reset_peak_usage();
        $memoryBefore = memory_get_usage(true);
        $before = $this->readStatus();
        $start = hrtime(true);
        $result = $this->reportRunner->run($request);
        $wallMs = (hrtime(true) - $start) / 1_000_000;
        $after = $this->readStatus();
        $peak = memory_get_peak_usage(true);

        $delta = static function (string $name) use ($before, $after, $baseline): ?int {
            if (!isset($before[$name], $after[$name])) {
                return null;
            }

            return max(0, $after[$name] - $before[$name] - ($baseline[$name] ?? 0));
        };
        $handlers = array_map($delta, self::HANDLER_VARIABLES);
        $meta = $result->getMeta();

        return new Measurement(
            $wallMs,
            (float) ($meta['sql_time_ms'] ?? 0.0),
            (int) ($meta['sql_query_count'] ?? 0),
            \in_array(null, $handlers, true) ? null : (int) array_sum($handlers),
            $delta('Questions'),
            $delta('Innodb_rows_read'),
            max(0, $peak - $memoryBefore),
            $result->isCached(),
            $result->getTotalRowCount(),
        );
    }

    /**
     * @return array<string,int>
     */
    private function getBaseline(): array
    {
        if ($this->baseline === null) {
            $first = $this->readStatus();
            $second = $this->readStatus();
            $this->baseline = [];
            foreach ($second as $name => $value) {
                if (isset($first[$name])) {
                    $this->baseline[$name] = $value - $first[$name];
                }
            }
        }

        return $this->baseline;
    }

    /**
     * @return array<string,int>
     */
    private function readStatus(): array
    {
        return array_map(
            intval(...),
            $this->reportConnection->get()->fetchPairs(self::STATUS_SQL),
        );
    }

    private function buildRequest(Scenario $scenario, bool $bypassCache): ReportRequestInterface
    {
        $overrides = [
            'dateRange' => [
                'type' => $scenario->dateRange->type->value,
                'from' => $scenario->dateRange->from,
                'to' => $scenario->dateRange->to,
            ],
            'storeIds' => $scenario->storeIds,
            'compareTo' => $scenario->compareTo->value,
            'bypassCache' => $bypassCache,
        ];

        if ($scenario->preset === null) {
            return $this->reportRequestFactory->fromArray(array_replace($scenario->request, $overrides));
        }

        return $this->presetRequestBuilder->build(
            $this->definitionRegistry->getPreset($scenario->preset),
            array_replace($scenario->request, $overrides),
        );
    }
}
