<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use MageOS\AdvancedReport\Model\Query\ResolvedQuery;

/**
 * Fills `dims` with human-readable labels using each dimension's label resolver
 * (raw values stay in `dims_raw`).
 */
final readonly class DimensionLabelApplier
{
    public function __construct(private LabelResolverPool $labelResolverPool)
    {
    }

    /**
     * @param ResolvedQuery $query
     * @param array<int,array{dims: array<string,?string>, dims_raw: array<string,?string>, metrics: array<string,int|float|null>}> $rows
     * @return array<int,array{dims: array<string,string>, dims_raw: array<string,?string>, metrics: array<string,int|float|null>}>
     */
    public function apply(ResolvedQuery $query, array $rows): array
    {
        foreach ($query->dimensions as $selection) {
            $dimension = $query->dataset->getDimension($selection->code);
            $resolver = $dimension->labelResolver;
            if ($resolver === null || !$this->labelResolverPool->has($resolver)) {
                continue;
            }
            $values = array_values(array_unique(array_map(
                static fn (array $row): string => (string) ($row['dims_raw'][$selection->code] ?? ''),
                $rows
            )));
            $labels = $rows === []
                ? []
                : $this->labelResolverPool->get($resolver)->resolve($values, $dimension->labelContext);
            foreach ($rows as $index => $row) {
                $raw = (string) ($row['dims_raw'][$selection->code] ?? '');
                $rows[$index]['dims'][$selection->code] = $labels[$raw] ?? $raw;
            }
        }

        return array_map(
            static fn (array $row): array => [
                ...$row,
                'dims' => array_map(static fn (mixed $label): string => (string) $label, $row['dims']),
            ],
            $rows
        );
    }
}
