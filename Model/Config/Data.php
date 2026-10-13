<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Config;

use Magento\Framework\Config\CacheInterface;
use Magento\Framework\Config\Data as FrameworkConfigData;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Cached, merged `report_datasets.xml` configuration (see {@see Reader}).
 *
 * Consumed by {@see \MageOS\AdvancedReport\Model\Definition\DefinitionRegistry}, which
 * builds the immutable dataset/preset value objects from the array this class exposes.
 */
class Data extends FrameworkConfigData
{
    private const string CACHE_ID = 'advanced_report_datasets';

    /**
     * @param Reader $reader
     * @param CacheInterface $cache
     * @param string $cacheId
     * @param SerializerInterface|null $serializer
     */
    public function __construct(
        Reader $reader,
        CacheInterface $cache,
        string $cacheId = self::CACHE_ID,
        ?SerializerInterface $serializer = null,
    ) {
        parent::__construct($reader, $cache, $cacheId, $serializer);
    }
}
