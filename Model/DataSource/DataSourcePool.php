<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource;

use MageOS\AdvancedReport\Api\DataSourceInterface;

/**
 * Ordered registry of the data sources declared in `di.xml`'s `sources` argument, each entry
 * keyed by an arbitrary declaration key with a `source` object and a `sortOrder`. Only MySQL
 * is registered in this plan; the pool lets extension modules (e.g. OpenSearch) add an entry
 * without altering existing ones.
 */
class DataSourcePool
{
    /**
     * @var array<string,DataSourceInterface>|null
     */
    private ?array $resolvedSources = null;

    /**
     * @param array<string,array{source: DataSourceInterface, sortOrder?: int}> $sources
     */
    public function __construct(private readonly array $sources = [])
    {
    }

    /**
     * All registered data sources, ordered by ascending `sortOrder`, keyed by source code
     * (as reported by {@see DataSourceInterface::getCode()}).
     *
     * @return array<string,DataSourceInterface>
     */
    public function getAll(): array
    {
        $this->build();

        return $this->resolvedSources;
    }

    /**
     * Looks up a registered data source by code, null when no source with that code is
     * registered.
     */
    public function get(string $code): ?DataSourceInterface
    {
        $this->build();

        return $this->resolvedSources[$code] ?? null;
    }

    /**
     * Sorts the declared entries by `sortOrder` and indexes them by source code, once per
     * instance.
     *
     * @phpstan-assert !null $this->resolvedSources
     */
    private function build(): void
    {
        if ($this->resolvedSources !== null) {
            return;
        }

        $entries = $this->sources;
        uasort(
            $entries,
            static fn (array $left, array $right): int => ($left['sortOrder'] ?? 0) <=> ($right['sortOrder'] ?? 0)
        );

        $resolved = [];
        foreach ($entries as $entry) {
            $source = $entry['source'];
            $resolved[$source->getCode()] = $source;
        }

        $this->resolvedSources = $resolved;
    }
}
