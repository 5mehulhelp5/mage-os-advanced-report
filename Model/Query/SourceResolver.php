<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\DataSourceInterface;
use MageOS\AdvancedReport\Model\DataSource\DataSourcePool;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;

/**
 * Picks the data source that executes a report request: an explicit source override when
 * present, otherwise the first source in pool order that supports the request. Designed so a
 * second source (e.g. OpenSearch) can be registered later without changes to callers.
 */
class SourceResolver
{
    public function __construct(
        private readonly DataSourcePool $sourcePool,
        private readonly DefinitionRegistry $definitionRegistry,
    ) {
    }

    /**
     * @throws NoSourceAvailableException No source supports the request.
     */
    public function resolve(Dataset $dataset, ReportRequestInterface $request): DataSourceInterface
    {
        $overrideCode = $request->getSource();
        if ($overrideCode !== null) {
            return $this->resolveOverride($overrideCode, $dataset, $request);
        }

        $allowedCodes = $this->allowedSourceCodes($request);
        foreach ($this->sourcePool->getAll() as $code => $source) {
            if ($allowedCodes !== null && !\in_array($code, $allowedCodes, true)) {
                continue;
            }
            if ($source->supports($dataset, $request)) {
                return $source;
            }
        }

        throw $this->noSourceAvailable($dataset);
    }

    /**
     * @return string[]|null Allowed source codes, null when the request's preset does not
     *                       restrict sources (or the request is not tied to a preset).
     */
    private function allowedSourceCodes(ReportRequestInterface $request): ?array
    {
        $presetCode = $request->getPreset();
        if ($presetCode === null) {
            return null;
        }

        $preset = $this->definitionRegistry->getPreset($presetCode);

        return $preset->sources === [] ? null : $preset->sources;
    }

    private function resolveOverride(
        string $code,
        Dataset $dataset,
        ReportRequestInterface $request
    ): DataSourceInterface {
        $source = $this->sourcePool->get($code);
        if ($source !== null && $source->supports($dataset, $request)) {
            return $source;
        }

        throw $this->noSourceAvailable($dataset);
    }

    private function noSourceAvailable(Dataset $dataset): NoSourceAvailableException
    {
        return new NoSourceAvailableException(
            new Phrase('No data source is available to execute the request for dataset "%1".', [$dataset->code])
        );
    }
}
