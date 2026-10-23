<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Security;

use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Security\DatasetAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatasetAccess::class)]
final class DatasetAccessTest extends TestCase
{
    private const array TREE = [
        ['id' => 'Magento_Backend::admin', 'children' => [
            ['id' => 'Magento_Sales::sales_order', 'children' => []],
            ['id' => 'Magento_Customer::manage', 'children' => []],
        ]],
    ];

    public function testItAllowsADatasetWhenBothTheModuleAndTheCoreResourceAreGranted(): void
    {
        $access = $this->access(['MageOS_AdvancedReport::orders', 'Magento_Sales::sales_order']);

        self::assertTrue($access->isAllowed($this->dataset('sales_orders')));
    }

    public function testItDeniesADatasetWhenTheModuleResourceIsDenied(): void
    {
        $access = $this->access(['Magento_Sales::sales_order']);

        self::assertFalse($access->isAllowed($this->dataset('sales_orders')));
    }

    public function testItDeniesADatasetWhenTheCoreResourceIsDenied(): void
    {
        $access = $this->access(['MageOS_AdvancedReport::orders']);

        self::assertFalse($access->isAllowed($this->dataset('sales_orders')));
    }

    public function testItIgnoresACoreResourceThatIsNotDeclaredInTheAclTree(): void
    {
        $access = $this->access(['MageOS_AdvancedReport::orders'], ['sales_orders' => 'Magento_Missing::x']);

        self::assertNull($access->getCoreResource('sales_orders'));
        self::assertTrue($access->isAllowed($this->dataset('sales_orders')));
    }

    public function testItAllowsAnUnmappedDatasetWithOnlyTheModuleResource(): void
    {
        $access = $this->access(['MageOS_AdvancedReport::orders']);

        self::assertNull($access->getCoreResource('other'));
        self::assertTrue($access->isAllowed($this->dataset('other')));
    }

    public function testItThrowsAnAuthorizationExceptionNamingTheDeniedResource(): void
    {
        $access = $this->access(['MageOS_AdvancedReport::orders']);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessageIsOrContains('You are not allowed to query the resource "Magento_Sales::sales_order".');

        $access->assertAllowed($this->dataset('sales_orders'));
    }

    public function testItListsModuleAndDeclaredCoreResourcesOnceAndSorted(): void
    {
        $registry = $this->createStub(DefinitionRegistry::class);
        $registry->method('getDatasets')->willReturn([
            'a' => $this->dataset('sales_orders'),
            'b' => $this->dataset('customer_orders'),
        ]);
        $access = $this->access([], [
            'sales_orders' => 'Magento_Sales::sales_order',
            'customer_orders' => 'Magento_Sales::sales_order',
            'customers' => 'Magento_Customer::manage',
            'x' => 'Magento_Missing::x',
        ], $registry);

        self::assertSame(
            ['MageOS_AdvancedReport::orders', 'Magento_Customer::manage', 'Magento_Sales::sales_order'],
            $access->getResources()
        );
    }

    public function testItReadsTheAclResourceTreeOnlyOnce(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->expects(self::once())->method('getAclResources')->willReturn(self::TREE);
        $access = new DatasetAccess(
            $this->createStub(AuthorizationInterface::class),
            $provider,
            $this->createStub(DefinitionRegistry::class),
            ['sales_orders' => 'Magento_Sales::sales_order', 'customers' => 'Magento_Customer::manage']
        );

        $access->getCoreResource('sales_orders');
        $access->getCoreResource('customers');
        $access->getResources();
    }

    /**
     * @param string[] $granted
     * @param array<string,string>|null $core
     */
    private function access(array $granted, ?array $core = null, ?DefinitionRegistry $registry = null): DatasetAccess
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $id): bool => in_array($id, $granted, true)
        );
        $provider = $this->createStub(ProviderInterface::class);
        $provider->method('getAclResources')->willReturn(self::TREE);

        return new DatasetAccess(
            $authorization,
            $provider,
            $registry ?? $this->createStub(DefinitionRegistry::class),
            $core ?? ['sales_orders' => 'Magento_Sales::sales_order']
        );
    }

    private function dataset(string $code): Dataset
    {
        return new Dataset($code, $code, 'MageOS_AdvancedReport::orders', 'sales', false, null, null, null, [], [], [], [], []);
    }
}
