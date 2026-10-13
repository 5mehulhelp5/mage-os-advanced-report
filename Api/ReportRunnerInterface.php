<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Api;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;

/**
 * The single entry point to run a report request: plans it ({@see \MageOS\AdvancedReport\Model\Query\QueryPlanner}),
 * resolves the data source, uses the result cache, executes the main and comparison queries
 * (or a preset query), computes derived metrics, merges the comparison and returns the
 * assembled result.
 *
 * Implemented by `Model\Query\ReportRunner` (task 044). Injected as
 * `MageOS\AdvancedReport\Api\ReportRunnerInterface\Proxy` by preset queries that would
 * otherwise create a circular dependency with the {@see \MageOS\AdvancedReport\Model\Preset\PresetQueryPool}
 * (e.g. the low-stock coverage preset query).
 */
interface ReportRunnerInterface
{
    /**
     * Runs the given request end to end and returns its result.
     */
    public function run(ReportRequestInterface $request): ReportResultInterface;
}
