<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\FilterOperator;
use MageOS\AdvancedReport\Model\Definition\Granularity;

/**
 * Builds a {@see ReportRequestInterface} from the plain array payload sent by the
 * admin UI or stored with a saved report, and serializes it back for storage.
 *
 * Only structural parsing (types, required keys, fixed vocabularies) happens here;
 * semantic validation (ACL, dataset/metric/dimension existence, guardrails) is the
 * request validator's responsibility.
 */
class ReportRequestFactory
{
    private const string DEFAULT_DATE_RANGE_TYPE = 'last_30_days';
    private const string DEFAULT_COMPARE_TO = 'none';
    private const string DEFAULT_SORT_DIRECTION = 'asc';

    /**
     * @param array<string,mixed> $data
     * @throws InvalidRequestException
     */
    public function fromArray(array $data): ReportRequestInterface
    {
        $dimensions = array_map(
            fn (array $dimension): DimensionSelection => new DimensionSelection(
                code: $dimension['code'],
                granularity: $dimension['granularity'] !== null
                    ? Granularity::from($dimension['granularity'])
                    : null,
            ),
            $data['dimensions'] ?? [],
        );

        $filters = array_map(
            fn (array $filter): FilterCondition => new FilterCondition(
                field: $filter['field'],
                operator: FilterOperator::from($filter['operator']),
                value: $filter['value'],
            ),
            $data['filters'] ?? [],
        );

        $sort = array_map(
            fn (array $sort): SortOrder => new SortOrder(
                by: $sort['by'],
                direction: $sort['direction'] ?? self::DEFAULT_SORT_DIRECTION,
            ),
            $data['sort'] ?? [],
        );

        return new ReportRequest(
            dataset: $this->requireDataset($data),
            preset: $data['preset'] ?? null,
            metrics: $this->requireMetrics($data),
            dimensions: $dimensions,
            filters: $filters,
            dateRange: $this->resolveDateRange($data['dateRange'] ?? []),
            compareTo: $this->resolveCompareMode($data['compareTo'] ?? self::DEFAULT_COMPARE_TO),
            sort: $sort,
            limit: $data['limit'] ?? null,
            offset: $data['offset'] ?? 0,
            storeIds: $data['storeIds'] ?? [],
            source: $data['source'] ?? null,
            bypassCache: $data['bypassCache'] ?? false,
        );
    }

    /**
     * Serializes a report request back to the plain array shape {@see fromArray()}
     * accepts, so it can be persisted with a saved report.
     *
     * @return array<string,mixed>
     */
    public function toArray(ReportRequestInterface $request): array
    {
        return [
            'dataset' => $request->getDataset(),
            'preset' => $request->getPreset(),
            'metrics' => $request->getMetrics(),
            'dimensions' => array_map(
                static fn (DimensionSelection $dimension): array => [
                    'code' => $dimension->code,
                    'granularity' => $dimension->granularity?->value,
                ],
                $request->getDimensions(),
            ),
            'filters' => array_map(
                static fn (FilterCondition $filter): array => [
                    'field' => $filter->field,
                    'operator' => $filter->operator->value,
                    'value' => $filter->value,
                ],
                $request->getFilters(),
            ),
            'dateRange' => [
                'type' => $request->getDateRange()->type->value,
                'from' => $request->getDateRange()->from,
                'to' => $request->getDateRange()->to,
            ],
            'compareTo' => $request->getCompareTo()->value,
            'sort' => array_map(
                static fn (SortOrder $sort): array => [
                    'by' => $sort->by,
                    'direction' => $sort->direction,
                ],
                $request->getSort(),
            ),
            'limit' => $request->getLimit(),
            'offset' => $request->getOffset(),
            'storeIds' => $request->getStoreIds(),
            'source' => $request->getSource(),
            'bypassCache' => $request->isBypassCache(),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @throws InvalidRequestException
     */
    private function requireDataset(array $data): string
    {
        if (!isset($data['dataset']) || !\is_string($data['dataset']) || $data['dataset'] === '') {
            throw new InvalidRequestException(new Phrase('The report request is missing a "dataset" code.'));
        }

        return $data['dataset'];
    }

    /**
     * @param array<string,mixed> $data
     * @throws InvalidRequestException
     * @return string[]
     */
    private function requireMetrics(array $data): array
    {
        if (!isset($data['metrics']) || !\is_array($data['metrics']) || $data['metrics'] === []) {
            throw new InvalidRequestException(
                new Phrase('The report request must select at least one metric.')
            );
        }

        foreach ($data['metrics'] as $metric) {
            if (!\is_string($metric)) {
                throw new InvalidRequestException(new Phrase('Report request metric codes must be strings.'));
            }
        }

        return $data['metrics'];
    }

    /**
     * @param array<string,mixed> $dateRange
     * @throws InvalidRequestException
     */
    private function resolveDateRange(array $dateRange): DateRange
    {
        $type = $dateRange['type'] ?? self::DEFAULT_DATE_RANGE_TYPE;
        if (!\is_string($type) || DateRangeType::tryFrom($type) === null) {
            throw new InvalidRequestException(new Phrase('Unknown date range type "%1".', [$type]));
        }

        $type = DateRangeType::from($type);
        if ($type !== DateRangeType::CUSTOM) {
            return new DateRange($type);
        }

        $from = $dateRange['from'] ?? null;
        $to = $dateRange['to'] ?? null;
        if (!$this->isValidDate($from) || !$this->isValidDate($to)) {
            throw new InvalidRequestException(
                new Phrase('A custom date range requires valid "from" and "to" dates in Y-m-d format.')
            );
        }

        return new DateRange($type, $from, $to);
    }

    /**
     * Whether the given value is a valid `Y-m-d` calendar date.
     */
    private function isValidDate(mixed $value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * @throws InvalidRequestException
     */
    private function resolveCompareMode(mixed $compareTo): CompareMode
    {
        if (!\is_string($compareTo) || CompareMode::tryFrom($compareTo) === null) {
            throw new InvalidRequestException(new Phrase('Unknown compare mode "%1".', [$compareTo]));
        }

        return CompareMode::from($compareTo);
    }
}
