<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use MageOS\AdvancedReport\Api\LabelResolverInterface;

/**
 * Shared fallback rules: empty values are "(none)", values the lookup cannot label stay raw.
 */
abstract class AbstractLabelResolver implements LabelResolverInterface
{
    /**
     * @inheritDoc
     */
    final public function resolve(array $values, array $context = []): array
    {
        $values = array_map(strval(...), $values);
        $present = array_values(array_unique(array_filter($values, static fn (string $v): bool => $v !== '')));
        $known = $present === [] ? [] : $this->loadLabels($present, $context);

        $labels = [];
        foreach ($values as $value) {
            $labels[$value] = $value === '' ? (string)__('(none)') : (string)($known[$value] ?? $value);
        }

        return $labels;
    }

    /**
     * Looks up labels for the non-empty, unique values in one go; unknown values may be omitted.
     *
     * @param string[] $values
     * @param array<string, mixed> $context
     * @return array<int|string, string>
     */
    abstract protected function loadLabels(array $values, array $context): array;
}
