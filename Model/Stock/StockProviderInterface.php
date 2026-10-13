<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Stock;

use MageOS\AdvancedReport\Model\DataSource\Mysql\BaseSelectProviderInterface;

/**
 * Stock base select of one inventory system (legacy or MSI).
 */
interface StockProviderInterface extends BaseSelectProviderInterface
{
}
