<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Api;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Query\RawResult;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;

/**
 * Executes a preset's own query in place of a {@see DataSourceInterface} (e.g. to compute
 * virtual metrics such as `coverage_days`). A preset declaring a `presetQuery` class is
 * dispatched here instead of a data source; the class is looked up by name in the
 * {@see \MageOS\AdvancedReport\Model\Preset\PresetQueryPool}, itself registered via `di.xml`
 * (never `ObjectManager`).
 */
interface PresetQueryInterface
{
    /**
     * Executes the resolved query and returns its raw, unprocessed result.
     */
    public function execute(ReportRequestInterface $request, ResolvedQuery $resolvedQuery): RawResult;
}
