<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Security;

use Magento\Framework\AuthorizationInterface;

/**
 * Fingerprint of the current admin's allow/deny state over every report-relevant ACL resource,
 * used to keep cached report results from being shared between admins with different rights.
 *
 * The fingerprint is deliberately NOT memoized: this is a shared instance and the current user
 * may change within a process.
 */
class AclContext
{
    private const string CONFIG_RESOURCE = 'MageOS_AdvancedReport::config';

    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly DatasetAccess $datasetAccess,
    ) {
    }

    public function getFingerprint(): string
    {
        $resources = array_unique([...$this->datasetAccess->getResources(), self::CONFIG_RESOURCE]);
        sort($resources);

        $state = [];
        foreach ($resources as $resource) {
            $state[$resource] = $this->authorization->isAllowed($resource);
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
