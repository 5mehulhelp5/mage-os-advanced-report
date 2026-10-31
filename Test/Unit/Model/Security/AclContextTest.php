<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Security;

use Magento\Framework\AuthorizationInterface;
use MageOS\AdvancedReport\Model\Security\AclContext;
use MageOS\AdvancedReport\Model\Security\DatasetAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AclContext::class)]
final class AclContextTest extends TestCase
{
    private const array RESOURCES = ['MageOS_AdvancedReport::orders', 'Magento_Sales::sales_order', 'Magento_Customer::manage'];

    public function testItProducesTheSameFingerprintForIdenticalPermissions(): void
    {
        $granted = ['MageOS_AdvancedReport::orders', 'MageOS_AdvancedReport::config'];

        self::assertSame(
            $this->context($granted)->getFingerprint(),
            $this->context($granted)->getFingerprint(),
        );
    }

    public function testItProducesADifferentFingerprintWhenADatasetAclDiffers(): void
    {
        self::assertNotSame(
            $this->context(['MageOS_AdvancedReport::orders'])->getFingerprint(),
            $this->context([])->getFingerprint(),
        );
    }

    public function testItProducesADifferentFingerprintWhenADatasetCoreAclDiffers(): void
    {
        self::assertNotSame(
            $this->context(['Magento_Sales::sales_order'])->getFingerprint(),
            $this->context([])->getFingerprint(),
        );
    }

    public function testItProducesDifferentAclFingerprintsForAdminsDifferingOnlyByAFieldAcl(): void
    {
        $base = ['MageOS_AdvancedReport::orders', 'Magento_Sales::sales_order'];

        self::assertNotSame(
            $this->context([...$base, 'Magento_Customer::manage'])->getFingerprint(),
            $this->context($base)->getFingerprint(),
        );
    }

    public function testItProducesADifferentFingerprintWhenTheConfigAclDiffers(): void
    {
        self::assertNotSame(
            $this->context(['MageOS_AdvancedReport::config'])->getFingerprint(),
            $this->context([])->getFingerprint(),
        );
    }

    public function testItRecomputesTheFingerprintWhenTheAuthorizationAnswersChangeBetweenCalls(): void
    {
        /** @var \ArrayObject<string,bool> $granted */
        $granted = new \ArrayObject();
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => isset($granted[$resource]),
        );
        $context = new AclContext($authorization, $this->access());

        $first = $context->getFingerprint();
        $granted['MageOS_AdvancedReport::orders'] = true;

        self::assertNotSame($first, $context->getFingerprint());
    }

    /**
     * @param string[] $granted
     */
    private function context(array $granted): AclContext
    {
        $lookup = array_flip($granted);
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => isset($lookup[$resource]),
        );

        return new AclContext($authorization, $this->access());
    }

    private function access(): DatasetAccess
    {
        $access = $this->createStub(DatasetAccess::class);
        $access->method('getResources')->willReturn(self::RESOURCES);

        return $access;
    }
}
