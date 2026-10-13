<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use InvalidArgumentException;
use MageOS\AdvancedReport\Api\LabelResolverInterface;

/**
 * Label resolvers keyed by the class name declared in `report_datasets.xml` (DI-configured).
 */
class LabelResolverPool
{
    /**
     * @param array<string, LabelResolverInterface> $resolvers
     */
    public function __construct(private readonly array $resolvers = [])
    {
    }

    public function get(string $name): LabelResolverInterface
    {
        return $this->resolvers[$name]
            ?? throw new InvalidArgumentException(\sprintf('Unknown label resolver "%s".', $name));
    }

    public function has(string $name): bool
    {
        return isset($this->resolvers[$name]);
    }
}
