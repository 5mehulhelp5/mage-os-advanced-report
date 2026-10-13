<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Api;

/**
 * Turns raw dimension values (ids/codes) into human labels in bulk.
 */
interface LabelResolverInterface
{
    /**
     * Resolves labels with a single lookup per call.
     *
     * Empty values are labelled "(none)", unknown values fall back to the raw value.
     *
     * @param string[] $values raw dimension values
     * @param array<string, mixed> $context the dimension's label context (e.g. attribute_code)
     * @return array<string, string> raw value => label
     */
    public function resolve(array $values, array $context = []): array;
}
