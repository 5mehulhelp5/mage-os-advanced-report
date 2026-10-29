<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Security;

use Magento\Authorization\Test\Fixture\Role;
use Magento\Framework\Exception\AuthorizationException;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\Cache;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\User\Test\Fixture\User;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use MageOS\AdvancedReport\Model\Security\AclContext;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runner-level authorization (module ACL plus core ACL per dataset), request hardening and cache isolation
 * with restricted admin roles.
 *
 * Roles are built from BASE (admin, reports, module reports) plus the listed resources. Restricted users are
 * logged in inside the test method (fixtures exist by then) through {@see ReportTestRunner::loginAs()}, which
 * resets the runtime ACL; every successful run in a scenario is the positive control of the denied one.
 */
#[DbIsolation(true)]
#[AppArea('adminhtml')]
final class RunnerAuthorizationTest extends TestCase
{
    private const array BASE = ['Magento_Backend::admin', 'Magento_Reports::report', 'MageOS_AdvancedReport::reports'];
    private const array MODULE_ONLY = [
        ...self::BASE,
        'MageOS_AdvancedReport::sales',
        'MageOS_AdvancedReport::customers',
        'MageOS_AdvancedReport::catalog',
    ];
    private const array SALES_CORE = [...self::MODULE_ONLY, 'Magento_Sales::sales_order'];
    private const array CUSTOMERS_CORE = [...self::MODULE_ONLY, 'Magento_Customer::manage'];
    private const array STOCK_CORE = [...self::MODULE_ONLY, 'Magento_Catalog::products'];
    private const array SALABLE_CORE = [...self::MODULE_ONLY, 'Magento_InventoryApi::stock'];
    private const array ALL_CORE = [
        ...self::MODULE_ONLY,
        'Magento_Sales::sales_order',
        'Magento_Customer::manage',
        'Magento_Catalog::products',
        'Magento_InventoryApi::stock',
    ];
    private const array CARTS_MODULE = [...self::BASE, 'MageOS_AdvancedReport::carts'];
    private const array CARTS_FULL = [
        ...self::CARTS_MODULE,
        'Magento_Reports::abandoned',
        'Magento_Sales::sales_order',
    ];
    private const array CARTS_NO_SALES = [...self::CARTS_MODULE, 'Magento_Reports::abandoned'];
    private const array CARTS_NO_ABANDONED = [...self::CARTS_MODULE, 'Magento_Sales::sales_order'];
    private const array CARTS_NO_MODULE = [...self::BASE, 'Magento_Reports::abandoned', 'Magento_Sales::sales_order'];
    private const array CACHE_ALLOWED = [...self::SALES_CORE, 'Magento_Customer::manage'];

    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ReportTestRunner();
        Bootstrap::getObjectManager()->get(CacheType::class)->clean();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_runner_a_%uniqid%', 'resources' => [
        ...self::BASE, 'MageOS_AdvancedReport::customers', 'Magento_Customer::manage',
    ]], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_b_%uniqid%', 'resources' => self::SALES_CORE], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItRejectsADatasetTheRoleIsNotAllowedToQuery(): void
    {
        $this->runner->loginAs($this->userName('user2'));
        $this->runner->runWith('sales_orders', ['orders']);

        $this->runner->loginAs($this->userName('user'));
        $this->expectException(AuthorizationException::class);
        $this->runner->runWith('sales_orders', ['orders']);
    }

    /**
     * PHPUnit 9 (Magento 2.4.7) ignores attributes, hence the annotation next to #[DataProvider].
     *
     * @dataProvider salesDatasetProvider
     * @param string[] $metrics
     */
    #[DataProvider('salesDatasetProvider')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_c_%uniqid%', 'resources' => self::MODULE_ONLY], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_d_%uniqid%', 'resources' => self::SALES_CORE], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItRejectsSalesDatasetsWithoutSalesOrderPermission(string $dataset, array $metrics): void
    {
        $this->runner->loginAs($this->userName('user2'));
        $this->runner->runWith($dataset, $metrics);

        $this->runner->loginAs($this->userName('user'));
        $this->expectException(AuthorizationException::class);
        $this->runner->runWith($dataset, $metrics);
    }

    /**
     * @return array<string,array{string, string[]}>
     */
    public static function salesDatasetProvider(): array
    {
        return [
            'sales_orders' => ['sales_orders', ['orders']],
            'customer_orders' => ['customer_orders', ['orders']],
            'sales_items' => ['sales_items', ['units_sold']],
        ];
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_runner_e_%uniqid%', 'resources' => self::MODULE_ONLY], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_f_%uniqid%', 'resources' => self::CUSTOMERS_CORE], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItRejectsTheCustomersDatasetWithoutCustomerManagePermission(): void
    {
        $this->runner->loginAs($this->userName('user2'));
        $this->runner->runWith('customers', ['registrations']);

        $this->runner->loginAs($this->userName('user'));
        $this->expectException(AuthorizationException::class);
        $this->runner->runWith('customers', ['registrations']);
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_runner_g_%uniqid%', 'resources' => self::MODULE_ONLY], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_h_%uniqid%', 'resources' => self::STOCK_CORE], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItRejectsTheStockDatasetWithoutCatalogProductsPermission(): void
    {
        $this->runner->loginAs($this->userName('user2'));
        $this->runner->runWith('catalog_stock', ['qty_on_hand']);

        $this->runner->loginAs($this->userName('user'));
        $this->expectException(AuthorizationException::class);
        $this->runner->runWith('catalog_stock', ['qty_on_hand']);
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_runner_i_%uniqid%', 'resources' => self::MODULE_ONLY], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_j_%uniqid%', 'resources' => self::SALABLE_CORE], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItRejectsTheSalableDatasetWithoutInventoryStockPermission(): void
    {
        $this->skipWithoutDataset('catalog_salable');

        $this->runner->loginAs($this->userName('user2'));
        $this->runner->runWith('catalog_salable', ['salable_qty']);

        $this->runner->loginAs($this->userName('user'));
        $this->expectException(AuthorizationException::class);
        $this->runner->runWith('catalog_salable', ['salable_qty']);
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_runner_k_%uniqid%', 'resources' => self::ALL_CORE], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    public function testItAllowsADatasetWhenModuleAndCorePermissionsAreGranted(): void
    {
        $this->runner->loginAs($this->userName('user'));

        $queries = [
            'sales_orders' => ['orders'],
            'customer_orders' => ['orders'],
            'sales_items' => ['units_sold'],
            'customers' => ['registrations'],
            'catalog_stock' => ['qty_on_hand'],
        ];
        if ($this->hasDataset('catalog_salable')) {
            $queries['catalog_salable'] = ['salable_qty'];
        }

        foreach ($queries as $dataset => $metrics) {
            $result = $this->runner->runWith($dataset, $metrics);
            $this->assertSame($metrics, array_keys($result->getTotals()['metrics']), $dataset);
        }
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_runner_l_%uniqid%', 'resources' => [
        ...self::BASE, 'MageOS_AdvancedReport::catalog', 'Magento_Catalog::products',
    ]], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_m_%uniqid%', 'resources' => self::ALL_CORE], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItRunsLowStockCoverageWithoutSalesDataWhenSalesOrderPermissionIsMissing(): void
    {
        $this->runner->loginAs($this->userName('user2'));
        $full = $this->runner->runPreset('low_stock_coverage', '2026-01-01', '2026-01-31');
        $this->assertStringNotContainsString('Sales data is not available', json_encode($full->getWarnings()));

        $this->runner->loginAs($this->userName('user'));
        $result = $this->runner->runPreset('low_stock_coverage', '2026-01-01', '2026-01-31');

        $this->assertStringContainsString('Sales data is not available', json_encode($result->getWarnings()));
    }

    public function testItRejectsAPresetBelongingToAnotherDataset(): void
    {
        $this->runner->login();

        $this->expectException(InvalidRequestException::class);
        $this->runner->runWith('sales_orders', ['orders'], extra: ['preset' => 'dead_stock']);
    }

    #[Cache('advanced_report', true)]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_n_%uniqid%', 'resources' => self::CACHE_ALLOWED], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_o_%uniqid%', 'resources' => self::CACHE_ALLOWED], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItServesACachedResultToTheSameUserAndToAUserWithIdenticalPermissions(): void
    {
        $this->runner->loginAs($this->userName('user'));
        $first = $this->runner->runWith('sales_orders', ['orders'], bypassCache: false);
        $second = $this->runner->runWith('sales_orders', ['orders'], bypassCache: false);

        $this->runner->loginAs($this->userName('user2'));
        $other = $this->runner->runWith('sales_orders', ['orders'], bypassCache: false);

        $this->assertFalse($first->isCached());
        $this->assertTrue($second->isCached(), 'Same user, second run');
        $this->assertTrue($other->isCached(), 'Different user with identical permissions');
    }

    #[Cache('advanced_report', true)]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_p_%uniqid%', 'resources' => self::CACHE_ALLOWED], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_q_%uniqid%', 'resources' => self::SALES_CORE], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItDoesNotServeACachedResultToARoleWithDifferentPermissions(): void
    {
        $this->runner->loginAs($this->userName('user'));
        $this->runner->runWith('sales_orders', ['orders'], bypassCache: false);
        $cached = $this->runner->runWith('sales_orders', ['orders'], bypassCache: false);
        $this->assertTrue($cached->isCached(), 'Positive control');

        $this->runner->loginAs($this->userName('user2'));
        $different = $this->runner->runWith('sales_orders', ['orders'], bypassCache: false);

        $this->assertFalse($different->isCached());
    }

    #[Cache('advanced_report', true)]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_r_%uniqid%', 'resources' => self::CACHE_ALLOWED], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_s_%uniqid%', 'resources' => self::MODULE_ONLY], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItRejectsADeniedRequestEvenWhenACachedResultExists(): void
    {
        $this->runner->loginAs($this->userName('user'));
        $this->runner->runWith('sales_orders', ['orders'], bypassCache: false);
        $cached = $this->runner->runWith('sales_orders', ['orders'], bypassCache: false);
        $this->assertTrue($cached->isCached(), 'Positive control');

        $this->runner->loginAs($this->userName('user2'));
        $this->expectException(AuthorizationException::class);
        $this->runner->runWith('sales_orders', ['orders'], bypassCache: false);
    }

    public function testItRejectsAnInvalidSortTargetStoreIdAndOffsetWithInvalidRequestErrors(): void
    {
        $this->runner->login();
        $this->runner->runWith('sales_orders', ['orders']);

        $invalid = [
            'sort target' => ['sort' => [['by' => 'gross_sales', 'direction' => 'asc']], 'extra' => []],
            'store id' => ['sort' => [], 'extra' => ['storeIds' => [987654]]],
            'offset' => ['sort' => [], 'extra' => ['offset' => -1]],
        ];
        $rejected = [];
        foreach ($invalid as $label => $case) {
            try {
                $this->runner->runWith('sales_orders', ['orders'], sort: $case['sort'], extra: $case['extra']);
                $this->fail('Expected an invalid request error for ' . $label);
            } catch (InvalidRequestException) {
                $rejected[] = $label;
            }
        }

        $this->assertSame(array_keys($invalid), $rejected);
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_runner_cs1_%uniqid%', 'resources' => self::CARTS_NO_SALES], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_cs2_%uniqid%', 'resources' => self::CARTS_FULL], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItDeniesRunningACartPresetWithoutTheMagentoSalesSalesOrderResource(): void
    {
        $this->assertCartPresetDeniedWithoutPermissions();
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_runner_cm1_%uniqid%', 'resources' => self::CARTS_NO_MODULE], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_cm2_%uniqid%', 'resources' => self::CARTS_FULL], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItDeniesRunningACartPresetWithoutTheMageOSAdvancedReportCartsResource(): void
    {
        $this->assertCartPresetDeniedWithoutPermissions();
    }

    #[DataFixture(Role::class, ['role_name' => 'ar_runner_ca1_%uniqid%', 'resources' => self::CARTS_NO_ABANDONED], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_ca2_%uniqid%', 'resources' => self::CARTS_FULL], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    public function testItDeniesRunningACartPresetWithoutTheMagentoReportsAbandonedResource(): void
    {
        $this->assertCartPresetDeniedWithoutPermissions();
    }

    #[Cache('advanced_report', true)]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_cc1_%uniqid%', 'resources' => self::CARTS_FULL], 'role')]
    #[DataFixture(User::class, ['role_id' => '$role.id$'], 'user')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_cc2_%uniqid%', 'resources' => self::CARTS_FULL], 'role2')]
    #[DataFixture(User::class, ['role_id' => '$role2.id$'], 'user2')]
    #[DataFixture(Role::class, ['role_name' => 'ar_runner_cc3_%uniqid%', 'resources' => self::CARTS_NO_SALES], 'role3')]
    #[DataFixture(User::class, ['role_id' => '$role3.id$'], 'user3')]
    public function testItGivesAdminsWithDifferentCartPermissionsDifferentCacheEntries(): void
    {
        $fingerprints = [];
        foreach (['user', 'user2', 'user3'] as $alias) {
            $this->runner->loginAs($this->userName($alias));
            $fingerprints[$alias] = Bootstrap::getObjectManager()->create(AclContext::class)->getFingerprint();
        }

        $this->assertSame($fingerprints['user'], $fingerprints['user2'], 'Identical cart permissions');
        $this->assertNotSame($fingerprints['user'], $fingerprints['user3'], 'Missing sales_order');
    }

    /**
     * Runs the carts preset as the full role (positive control) and as "user" (must be denied).
     */
    private function assertCartPresetDeniedWithoutPermissions(): void
    {
        $this->runner->loginAs($this->userName('user2'));
        $this->runner->runPreset('recent_carts', '2026-01-01', '2026-01-31');

        $this->runner->loginAs($this->userName('user'));
        $this->expectException(AuthorizationException::class);
        $this->runner->runPreset('recent_carts', '2026-01-01', '2026-01-31');
    }

    private function userName(string $fixtureAlias): string
    {
        return (string) DataFixtureStorageManager::getStorage()->get($fixtureAlias)->getUserName();
    }

    private function hasDataset(string $code): bool
    {
        try {
            Bootstrap::getObjectManager()->get(DefinitionRegistry::class)->getDataset($code);

            return true;
        } catch (NoSuchDefinitionException) {
            return false;
        }
    }

    private function skipWithoutDataset(string $code): void
    {
        if (!$this->hasDataset($code)) {
            $this->markTestSkipped($code . ' is not available (MSI disabled).');
        }
    }
}
