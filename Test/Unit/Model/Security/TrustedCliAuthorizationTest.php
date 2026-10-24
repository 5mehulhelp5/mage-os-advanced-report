<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Security;

use Magento\Framework\AuthorizationInterface;
use MageOS\AdvancedReport\Model\Security\TrustedCliAuthorization;
use MageOS\AdvancedReport\Model\Security\TrustedCliContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrustedCliAuthorization::class)]
#[CoversClass(TrustedCliContext::class)]
final class TrustedCliAuthorizationTest extends TestCase
{
    public function testItDelegatesAuthorizationChecksUnchangedWhenTheTrustedCliContextIsDisabled(): void
    {
        $core = $this->createMock(AuthorizationInterface::class);
        $core->expects(self::exactly(2))
            ->method('isAllowed')
            ->willReturnCallback(static fn (string $resource): bool => $resource === 'allowed');
        $authorization = new TrustedCliAuthorization($core, new TrustedCliContext());

        self::assertTrue($authorization->isAllowed('allowed'));
        self::assertFalse($authorization->isAllowed('denied'));
    }

    public function testItAllowsEveryResourceWhenTheTrustedCliContextIsEnabled(): void
    {
        $core = $this->createMock(AuthorizationInterface::class);
        $core->expects(self::never())->method('isAllowed');
        $context = new TrustedCliContext();
        $context->enable();
        $authorization = new TrustedCliAuthorization($core, $context);

        self::assertTrue($context->isEnabled());
        self::assertTrue($authorization->isAllowed('denied'));
    }
}
