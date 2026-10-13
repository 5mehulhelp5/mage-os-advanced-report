<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Api;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Query\RawResult;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;

/**
 * A backend able to execute a resolved query against a dataset (e.g. MySQL, OpenSearch).
 *
 * Registered in the {@see \MageOS\AdvancedReport\Model\DataSource\DataSourcePool} and picked
 * per request by {@see \MageOS\AdvancedReport\Model\Query\SourceResolver}, which is designed
 * so a second source can be added later without changes to callers.
 */
interface DataSourceInterface
{
    /**
     * Unique source code (e.g. "mysql"), matched against the request's source override and a
     * preset's source restriction.
     */
    public function getCode(): string;

    /**
     * Whether this source can execute the given request against the given dataset: every
     * requested field, metric, dimension and filter must be mapped for this source.
     */
    public function supports(Dataset $dataset, ReportRequestInterface $request): bool;

    /**
     * Executes the resolved query and returns its raw, unprocessed result.
     */
    public function execute(ReportRequestInterface $request, ResolvedQuery $resolvedQuery): RawResult;
}
