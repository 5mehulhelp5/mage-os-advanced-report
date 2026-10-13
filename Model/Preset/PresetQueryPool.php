<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Preset;

use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Api\PresetQueryInterface;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;

/**
 * Registry of {@see PresetQueryInterface} implementations, keyed by the class name used in
 * `report_datasets.xml`'s `presetQuery` attribute. Configured entirely via `di.xml` (tasks
 * 031/032 append their own entries with a targeted merge) so callers never resort to
 * `ObjectManager` to instantiate a preset query by class name.
 */
class PresetQueryPool
{
    /**
     * @param array<string,PresetQueryInterface> $presetQueries
     */
    public function __construct(private readonly array $presetQueries = [])
    {
    }

    /**
     * @throws NoSuchDefinitionException When no preset query is registered under `$className`.
     */
    public function get(string $className): PresetQueryInterface
    {
        return $this->presetQueries[$className]
            ?? throw new NoSuchDefinitionException(
                new Phrase('No preset query registered for class "%1".', [$className])
            );
    }
}
