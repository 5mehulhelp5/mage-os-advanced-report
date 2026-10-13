<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\Preset;

/**
 * Builds a {@see ReportRequestInterface} from a {@see Preset}'s defaults, optionally overridden
 * (e.g. with a custom date range). Shared by the `advanced-report:explain` CLI and the admin
 * exploration page.
 */
class PresetRequestBuilder
{
    public function __construct(private readonly ReportRequestFactory $reportRequestFactory)
    {
    }

    /**
     * @param array<string,mixed>|null $overrides Request array keys replacing the preset defaults.
     * @throws InvalidRequestException
     */
    public function build(Preset $preset, ?array $overrides = null): ReportRequestInterface
    {
        $data = [
            'dataset' => $preset->dataset,
            'preset' => $preset->code,
            'metrics' => $preset->metrics,
            'dimensions' => array_map(
                static fn (array $dimension): array => [
                    'code' => $dimension['code'],
                    'granularity' => $dimension['granularity']?->value,
                ],
                $preset->dimensions,
            ),
            'filters' => array_map(
                static fn (array $filter): array => [
                    'field' => $filter['field'],
                    'operator' => $filter['operator']->value,
                    'value' => $filter['value'],
                ],
                $preset->filters,
            ),
            'sort' => array_map(
                static fn (array $sort): array => ['by' => $sort['metric'], 'direction' => $sort['direction']],
                $preset->sort,
            ),
            'compareTo' => $preset->compareTo,
        ];

        if ($preset->dateRange !== null) {
            $data['dateRange'] = ['type' => $preset->dateRange];
        }

        return $this->reportRequestFactory->fromArray(array_replace($data, $overrides ?? []));
    }
}
