<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Security;

use Magento\Framework\AuthorizationInterface;

/**
 * Decorates the core authorization for the report ACL consumers only (DatasetAccess, AclContext,
 * RequestValidator, wired in `etc/di.xml`; never a global preference): while the
 * {@see TrustedCliContext} is enabled every resource is allowed, otherwise the core service decides.
 *
 * The CLI is trusted (shell access is the trust boundary, like `advanced-report:explain`); there is no
 * backend session to authorize against. Not final: the decorated service may be intercepted.
 */
class TrustedCliAuthorization implements AuthorizationInterface
{
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly TrustedCliContext $trustedCliContext,
    ) {
    }

    public function isAllowed($resource, $privilege = null): bool
    {
        return $this->trustedCliContext->isEnabled() || $this->authorization->isAllowed($resource, $privilege);
    }
}
