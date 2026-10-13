<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Supplies the base select of datasets that are not a plain table (e.g. stock providers).
 */
interface BaseSelectProviderInterface
{
    /**
     * Base select the select builder applies joins, filters and aggregates on.
     *
     * @param AdapterInterface $connection
     * @param Dataset $dataset
     */
    public function getSelect(AdapterInterface $connection, Dataset $dataset): Select;
}
