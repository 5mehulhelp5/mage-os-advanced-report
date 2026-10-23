<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Model\Config;

/**
 * Caches report results on the advanced_report cache type under a deterministic
 * key, with a TTL depending on whether the reported period is still open.
 */
class ResultCache
{
    /**
     * @param FrontendInterface $cache
     * @param Json $serializer
     * @param Config $config
     * @param ReportRequestFactory $requestFactory
     */
    public function __construct(
        private readonly FrontendInterface $cache,
        private readonly Json $serializer,
        private readonly Config $config,
        private readonly ReportRequestFactory $requestFactory,
    ) {
    }

    /**
     * Builds a deterministic cache key for a resolved report request.
     *
     * Normalizes the request array (sorted keys, `bypassCache` excluded), the
     * store scope and the counted statuses so equivalent requests with values
     * or keys in a different order produce the same key. The resolved date
     * ranges and the admin interface locale are supplied by the caller (the
     * report runner) rather than derived here, so this class stays
     * independent from date resolution and translation concerns.
     *
     * @param ReportRequestInterface $request
     * @param string $source Resolved data source code (the request's own
     *                       source may be null when auto-resolved).
     * @param string $configVersion {@see \MageOS\AdvancedReport\Model\Definition\DefinitionRegistry::getConfigVersion()}
     * @param string[] $countedStatuses Configured statuses counted as sales.
     * @param array<string,mixed> $resolvedDateRanges Concrete `from`/`to`
     *                                                dates for the main range and, when applicable, the comparison range.
     * @param string $locale Admin interface locale.
     * @param string $aclContext Fingerprint of the admin's allowed ACL resources.
     */
    public function buildKey(
        ReportRequestInterface $request,
        string $source,
        string $configVersion,
        array $countedStatuses,
        array $resolvedDateRanges,
        string $locale,
        string $aclContext = '',
    ): string {
        $normalizedRequest = $this->requestFactory->toArray($request);
        unset($normalizedRequest['bypassCache'], $normalizedRequest['source'], $normalizedRequest['storeIds']);

        $payload = $this->sortRecursively([
            'request' => $normalizedRequest,
            'source' => $source,
            'storeIds' => $this->sortedList($request->getStoreIds()),
            'configVersion' => $configVersion,
            'countedStatuses' => $this->sortedList($countedStatuses),
            'resolvedDateRanges' => $resolvedDateRanges,
            'locale' => $locale,
            'aclContext' => $aclContext,
        ]);

        return hash('sha256', (string) $this->serializer->serialize($payload));
    }

    /**
     * Loads a previously cached report result, `null` on a cache miss.
     *
     * @return array<string,mixed>|null
     */
    public function load(string $key): ?array
    {
        $cached = $this->cache->load($key);
        if (!\is_string($cached)) {
            return null;
        }

        $result = $this->serializer->unserialize($cached);

        return \is_array($result) ? $result : null;
    }

    /**
     * Saves a report result under `$key`.
     *
     * @param string $key
     * @param array<string,mixed> $payload
     * @param bool $openPeriod Whether the reported period (main or comparison
     *                         range) includes today; selects the TTL to use.
     */
    public function save(string $key, array $payload, bool $openPeriod): void
    {
        $ttl = $openPeriod ? $this->config->getCacheTtlOpenPeriod() : $this->config->getCacheTtlClosedPeriod();

        $this->cache->save((string) $this->serializer->serialize($payload), $key, [CacheType::CACHE_TAG], $ttl);
    }

    /**
     * Sorts an array's keys recursively so structurally equivalent arrays with
     * keys in a different order serialize identically.
     *
     * @param array<array-key,mixed> $data
     * @return array<array-key,mixed>
     */
    private function sortRecursively(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = $this->sortRecursively($value);
            }
        }

        return $data;
    }

    /**
     * Sorts a list's values so the same values in a different order produce
     * the same key.
     *
     * @param array<int,mixed> $values
     * @return array<int,mixed>
     */
    private function sortedList(array $values): array
    {
        sort($values);

        return $values;
    }
}
