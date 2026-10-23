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
        return new ReportRequest(
            dataset: $this->requireDataset($data),
            preset: $this->optionalString($data, 'preset'),
            metrics: $this->requireMetrics($data),
            dimensions: $this->parseDimensions($data['dimensions'] ?? []),
            filters: $this->parseFilters($data['filters'] ?? []),
            dateRange: $this->resolveDateRange($data['dateRange'] ?? []),
            compareTo: $this->resolveCompareMode($data['compareTo'] ?? self::DEFAULT_COMPARE_TO),
            sort: $this->parseSort($data['sort'] ?? []),
            limit: $this->optionalInt($data, 'limit'),
            offset: $this->requireInt($data, 'offset', 0),
            storeIds: $this->parseStoreIds($data['storeIds'] ?? []),
            source: $this->optionalString($data, 'source'),
            bypassCache: $this->requireBool($data, 'bypassCache'),
        );
    }

    /**
     * @throws InvalidRequestException
     * @return DimensionSelection[]
     */
    private function parseDimensions(mixed $dimensions): array
    {
        $result = [];
        foreach ($this->requireListOfArrays($dimensions, 'dimensions') as $dimension) {
            $granularity = $dimension['granularity'] ?? null;
            if ($granularity !== null && (!\is_string($granularity) || Granularity::tryFrom($granularity) === null)) {
                throw new InvalidRequestException(new Phrase('Unknown dimension granularity.'));
            }
            $result[] = new DimensionSelection(
                code: $this->requireNonEmptyString($dimension, 'code', 'dimensions'),
                granularity: $granularity !== null ? Granularity::from($granularity) : null,
            );
        }

        return $result;
    }

    /**
     * @throws InvalidRequestException
     * @return FilterCondition[]
     */
    private function parseFilters(mixed $filters): array
    {
        $result = [];
        foreach ($this->requireListOfArrays($filters, 'filters') as $filter) {
            $operator = $filter['operator'] ?? null;
            if (!\is_string($operator) || FilterOperator::tryFrom($operator) === null) {
                throw new InvalidRequestException(new Phrase('Unknown filter operator.'));
            }
            $result[] = new FilterCondition(
                field: $this->requireNonEmptyString($filter, 'field', 'filters'),
                operator: FilterOperator::from($operator),
                value: $filter['value'] ?? null,
            );
        }

        return $result;
    }

    /**
     * @throws InvalidRequestException
     * @return SortOrder[]
     */
    private function parseSort(mixed $sort): array
    {
        $result = [];
        foreach ($this->requireListOfArrays($sort, 'sort') as $entry) {
            $direction = $entry['direction'] ?? self::DEFAULT_SORT_DIRECTION;
            if (!\is_string($direction)) {
                throw new InvalidRequestException(new Phrase('The sort direction must be a string.'));
            }
            $result[] = new SortOrder(
                by: $this->requireNonEmptyString($entry, 'by', 'sort'),
                direction: $direction,
            );
        }

        return $result;
    }

    /**
     * @throws InvalidRequestException
     * @return int[]
     */
    private function parseStoreIds(mixed $storeIds): array
    {
        if (!\is_array($storeIds) || !array_is_list($storeIds)) {
            throw new InvalidRequestException(new Phrase('The store ids must be a list of integers.'));
        }
        foreach ($storeIds as $storeId) {
            if (!\is_int($storeId)) {
                throw new InvalidRequestException(new Phrase('The store ids must be a list of integers.'));
            }
        }

        return $storeIds;
    }

    /**
     * @throws InvalidRequestException
     * @return array<int,array<string,mixed>>
     */
    private function requireListOfArrays(mixed $value, string $key): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new InvalidRequestException(new Phrase('The "%1" entry must be a list.', [$key]));
        }
        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                throw new InvalidRequestException(
                    new Phrase('Every "%1" entry must be an object.', [$key])
                );
            }
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $entry
     * @throws InvalidRequestException
     */
    private function requireNonEmptyString(array $entry, string $key, string $group): string
    {
        $value = $entry[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new InvalidRequestException(
                new Phrase('Every "%1" entry requires a non-empty "%2".', [$group, $key])
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $data
     * @throws InvalidRequestException
     */
    private function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw new InvalidRequestException(new Phrase('The "%1" value must be a string.', [$key]));
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $data
     * @throws InvalidRequestException
     */
    private function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !\is_int($value)) {
            throw new InvalidRequestException(new Phrase('The "%1" value must be an integer.', [$key]));
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $data
     * @throws InvalidRequestException
     */
    private function requireInt(array $data, string $key, int $default): int
    {
        if (!\array_key_exists($key, $data)) {
            return $default;
        }
        if (!\is_int($data[$key])) {
            throw new InvalidRequestException(new Phrase('The "%1" value must be an integer.', [$key]));
        }

        return $data[$key];
    }

    /**
     * @param array<string,mixed> $data
     * @throws InvalidRequestException
     */
    private function requireBool(array $data, string $key): bool
    {
        $value = $data[$key] ?? false;
        if (!\is_bool($value)) {
            throw new InvalidRequestException(new Phrase('The "%1" value must be a boolean.', [$key]));
        }

        return $value;
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
