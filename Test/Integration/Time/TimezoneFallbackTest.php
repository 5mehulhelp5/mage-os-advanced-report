<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Time;

use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneSupportDetector;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use MageOS\AdvancedReport\Test\Integration\Helper\StoreProvisioner;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Timezone semantics with the UTC-hour fallback forced (database without timezone tables): the scenarios of
 * {@see TimezoneTest} must yield the same buckets and additive values.
 *
 * Three guest orders (product 10.00, one unit each) are placed by the class fixtures; every test moves their
 * `created_at` (UTC) with {@see SetOrderCreatedAt} and, when needed, order 3 to the second website store.
 * Store timezones are pinned per test method with `#[Config]` at default and website scope (the report reads
 * the website scope). Every test passes explicit store ids unless it targets a multi-timezone scope.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-tz-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-tz-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-tz-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-tz-3@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
]
final class TimezoneFallbackTest extends TestCase
{
    private ReportTestRunner $runner;

    public static function setUpBeforeClass(): void
    {
        (new StoreProvisioner())->ensureSecondWebsiteStore();
    }

    protected function setUp(): void
    {
        $this->forceStrategy(TimezoneSupportDetector::STRATEGY_UTC_HOUR_FALLBACK);
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
        $this->forceStrategy(null);
        Bootstrap::getObjectManager()->get(CacheType::class)->remove('mageos_advancedreport_timezone_strategy');
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Europe/Rome', 'website', 'base'),
    ]
    public function testItReportsTheFallbackTimezoneStrategyInTheResultMeta(): void
    {
        $this->setCreatedAt(1, '2026-06-10 10:00:00');

        $result = $this->runner->run(
            'sales_orders',
            ['orders'],
            [['code' => 'order_date', 'granularity' => 'day']],
            [],
            '2026-06-09',
            '2026-06-12',
            [$this->storeId()]
        );

        $this->assertSame(TimezoneSupportDetector::STRATEGY_UTC_HOUR_FALLBACK, $result->getMeta()['timezone_strategy']);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Asia/Kolkata', 'website', 'base'),
    ]
    public function testItReturnsTheSameDailyBucketsAsConvertTzForAsiaKolkataStores(): void
    {
        $this->setCreatedAt(1, '2026-06-10 18:20:00');
        $this->setCreatedAt(2, '2026-06-10 18:29:59');
        $this->setCreatedAt(3, '2026-06-10 18:30:00');

        $this->assertSame(['2026-06-10' => 2, '2026-06-11' => 1], $this->ordersBy('day', '2026-06-09', '2026-06-12'));
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Asia/Kathmandu', 'website', 'base'),
    ]
    public function testItReturnsTheSameDailyBucketsAsConvertTzForAsiaKathmanduStores(): void
    {
        $this->setCreatedAt(1, '2026-06-10 18:14:59');
        $this->setCreatedAt(2, '2026-06-10 18:15:00');
        $this->setCreatedAt(3, '2026-06-10 18:20:00');

        $this->assertSame(['2026-06-10' => 1, '2026-06-11' => 2], $this->ordersBy('day', '2026-06-09', '2026-06-12'));
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Europe/Rome', 'website', 'base'),
    ]
    public function testItReturnsTheSameHourlyBucketsAsConvertTzAcrossTheSpringForwardChangeInEuropeRome(): void
    {
        $this->setCreatedAt(1, '2026-03-28 23:30:00');
        $this->setCreatedAt(2, '2026-03-29 01:30:00');
        $this->setCreatedAt(3, '2026-03-29 21:30:00');

        $hours = $this->ordersBy('hour', '2026-03-29', '2026-03-29', true);

        $this->assertCount(23, $hours);
        $this->assertSame(1, $hours['2026-03-29 00:00']);
        $this->assertSame(1, $hours['2026-03-29 03:00']);
        $this->assertSame(1, $hours['2026-03-29 23:00']);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Europe/Rome', 'website', 'base'),
    ]
    public function testItReturnsTheSameHourlyBucketsAsConvertTzAcrossTheFallBackChangeInEuropeRome(): void
    {
        $this->setCreatedAt(1, '2026-10-25 00:30:00');
        $this->setCreatedAt(2, '2026-10-25 01:30:00');
        $this->setCreatedAt(3, '2026-10-25 02:30:00');

        $hours = $this->ordersBy('hour', '2026-10-25', '2026-10-25', true);

        $this->assertCount(24, $hours);
        $this->assertSame(2, $hours['2026-10-25 02:00']);
        $this->assertSame(1, $hours['2026-10-25 03:00']);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
        Config('general/locale/firstday', '1', 'store', 'default'),
    ]
    public function testItReturnsTheSameWeeklyBucketsAsConvertTzWithAStoreSpecificFirstDay(): void
    {
        $this->setCreatedAt(1, '2026-06-07 12:00:00');
        $this->setCreatedAt(2, '2026-06-08 12:00:00');
        $this->setCreatedAt(3, '2026-06-01 00:00:00');

        $this->assertSame(['2026-06-01' => 2, '2026-06-08' => 1], $this->ordersBy('week', '2026-06-01', '2026-06-14'));
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Europe/Rome', 'website', 'base'),
    ]
    public function testItReturnsNonMergeableMetricsAsNullWithAWarning(): void
    {
        $this->setCreatedAt(1, '2026-06-10 10:00:00');
        $this->setCreatedAt(2, '2026-06-10 11:00:00');
        $this->setCreatedAt(3, '2026-06-11 10:00:00');

        $result = $this->runner->run(
            'customer_orders',
            ['orders', 'unique_customers', 'avg_wait_days'],
            [['code' => 'order_date', 'granularity' => 'day']],
            [],
            '2026-06-09',
            '2026-06-12',
            [$this->storeId()]
        );

        $rows = $this->runner->rows($result);
        $this->assertNotEmpty($rows);
        $this->assertSame(3, array_sum(array_map(static fn (array $row): int => (int) $row['orders'], $rows)));
        // zero-filled buckets without orders legitimately report 0; buckets with data must not guess
        foreach (array_filter($rows, static fn (array $row): bool => (int) $row['orders'] > 0) as $row) {
            $this->assertNull($row['unique_customers']);
            $this->assertNull($row['avg_wait_days']);
        }
        $warnings = implode("\n", array_map(strval(...), $result->getWarnings()));
        $this->assertStringContainsString(
            'is not available because the database has no timezone tables',
            $warnings
        );
    }

    /**
     * @return array<string,int> bucket => orders; empty buckets are omitted unless $keepEmpty
     */
    private function ordersBy(string $granularity, string $from, string $to, bool $keepEmpty = false): array
    {
        $result = $this->runner->run(
            'sales_orders',
            ['orders'],
            [['code' => 'order_date', 'granularity' => $granularity]],
            [],
            $from,
            $to,
            [$this->storeId()]
        );
        $buckets = [];
        foreach ($this->runner->rows($result) as $row) {
            $buckets[(string) $row['order_date']] = (int) $row['orders'];
        }

        return $keepEmpty ? $buckets : array_filter($buckets);
    }

    private function forceStrategy(?string $strategy): void
    {
        $detector = Bootstrap::getObjectManager()->get(TimezoneSupportDetector::class);
        (new ReflectionProperty($detector, 'strategy'))->setValue($detector, $strategy);
    }

    private function setCreatedAt(int $n, string $createdAt): void
    {
        Bootstrap::getObjectManager()->get(SetOrderCreatedAt::class)
            ->apply(['order_id' => $this->orderId($n), 'created_at' => $createdAt]);
    }

    private function orderId(int $n): int
    {
        return (int) DataFixtureStorageManager::getStorage()->get('order' . $n)->getId();
    }

    private function storeId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getDefaultStoreView()->getId();
    }
}
