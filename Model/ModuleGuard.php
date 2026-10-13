<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model;

use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Phrase;

/**
 * Makes admin controllers answer 404 when the module is disabled in configuration.
 */
class ModuleGuard
{
    /**
     * @param Config $config
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Fail with a not found exception when `general/enabled` is off.
     *
     * @throws NotFoundException
     */
    public function assertEnabled(): void
    {
        if (!$this->config->isEnabled()) {
            throw new NotFoundException(new Phrase('Page not found.'));
        }
    }
}
