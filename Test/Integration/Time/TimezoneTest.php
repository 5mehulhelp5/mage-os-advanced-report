<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Time;

use DateTimeImmutable;
use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Framework\Stdlib\DateTime\DateTime;
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
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneSupportDetector;
use MageOS\AdvancedReport\Model\Query\Clock;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DateRangeResolver;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\ReportScopeResolver;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderStore;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use MageOS\AdvancedReport\Test\Integration\Helper\StoreProvisioner;
use PHPUnit\Framework\TestCase;

/**
 * Timezone semantics (CONVERT_TZ) on real orders.
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
final class TimezoneTest extends TestCase
{
    /** @var array{website_id:int, store_id:int} */
    private static array $secondWebsiteStore;

    private ReportTestRunner $runner;

    public static function setUpBeforeClass(): void
    {
        self::$secondWebsiteStore = (new StoreProvisioner())->ensureSecondWebsiteStore();
    }

    protected function setUp(): void
    {
        $detector = Bootstrap::getObjectManager()->get(TimezoneSupportDetector::class);
        if ($detector->detect() !== TimezoneSupportDetector::STRATEGY_CONVERT_TZ) {
            $this->markTestSkipped('MySQL timezone tables are not loaded; covered by the fallback tests.');
        }
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Europe/Rome', 'website', 'base'),
    ]
    public function testItIncludesAnOrderAtLocalMidnightOfTheFirstDayAndExcludesOneAtLocalMidnightAfterTheLastDayInEuropeRome(): void
    {
        // Rome is UTC+2 in June: local 2026-06-01..2026-06-30 is [2026-05-31 22:00, 2026-06-30 22:00) UTC
        $this->setCreatedAt(1, '2026-05-31 22:00:00');
        $this->setCreatedAt(2, '2026-06-30 22:00:00');
        $this->setCreatedAt(3, '2026-05-31 21:59:59');

        $result = $this->runner->run('sales_orders', ['orders'], [], [], '2026-06-01', '2026-06-30', [$this->storeId()]);

        // order 1 (first local midnight) in; order 2 (local midnight after the last day) and order 3 (1s before) out
        $this->assertEquals(1, $this->runner->totals($result)['orders']);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Europe/Rome', 'website', 'base'),
    ]
    public function testItIncludesTheLastSecondOfTheLastLocalDayInEuropeRome(): void
    {
        $this->setCreatedAt(1, '2026-06-30 21:59:59');
        $this->setCreatedAt(2, '2026-06-30 22:00:00');
        $this->setCreatedAt(3, '2026-05-31 21:59:59');

        $result = $this->runner->run('sales_orders', ['orders'], [], [], '2026-06-01', '2026-06-30', [$this->storeId()]);

        $this->assertEquals(1, $this->runner->totals($result)['orders']);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Asia/Kolkata', 'website', 'base'),
    ]
    public function testItPlacesOrdersInLocalDaysForUtcAsiaKolkataStores(): void
    {
        $this->setCreatedAt(1, '2026-06-10 18:20:00');
        $this->setCreatedAt(2, '2026-06-10 18:29:59');
        $this->setCreatedAt(3, '2026-06-10 18:30:00');

        $days = $this->ordersByDay('2026-06-09', '2026-06-12');

        // Kolkata is UTC+5:30: orders 1 and 2 are still on 06-10, order 3 is 06-11 00:00 local
        $this->assertSame(['2026-06-10' => 2, '2026-06-11' => 1], $days);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Asia/Kathmandu', 'website', 'base'),
    ]
    public function testItPlacesOrdersInLocalDaysForUtcAsiaKathmanduStores(): void
    {
        $this->setCreatedAt(1, '2026-06-10 18:14:59');
        $this->setCreatedAt(2, '2026-06-10 18:15:00');
        $this->setCreatedAt(3, '2026-06-10 18:20:00');

        $days = $this->ordersByDay('2026-06-09', '2026-06-12');

        // Kathmandu is UTC+5:45: order 1 is 06-10 23:59:59 local, orders 2 and 3 are on 06-11
        $this->assertSame(['2026-06-10' => 1, '2026-06-11' => 2], $days);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Europe/Rome', 'website', 'base'),
    ]
    public function testItReturns23HourlyBucketsOnTheSpringForwardDay(): void
    {
        $this->setCreatedAt(1, '2026-03-28 23:30:00');
        $this->setCreatedAt(2, '2026-03-29 01:30:00');
        $this->setCreatedAt(3, '2026-03-29 21:30:00');

        $hours = $this->ordersByHour('2026-03-29');

        // local day is [2026-03-28 23:00, 2026-03-29 22:00) UTC = 23 hours; 02:00 local does not exist
        $this->assertCount(23, $hours);
        $this->assertSame(1, $hours['2026-03-29 00:00']);
        $this->assertSame(1, $hours['2026-03-29 03:00']);
        $this->assertSame(1, $hours['2026-03-29 23:00']);
        $this->assertArrayNotHasKey('2026-03-29 02:00:00', $hours);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Europe/Rome', 'website', 'base'),
    ]
    public function testItMergesTheRepeatedHourOnTheFallBackDay(): void
    {
        $this->setCreatedAt(1, '2026-10-25 00:30:00');
        $this->setCreatedAt(2, '2026-10-25 01:30:00');
        $this->setCreatedAt(3, '2026-10-25 02:30:00');

        $hours = $this->ordersByHour('2026-10-25');

        // 02:30 CEST (00:30 UTC) and 02:30 CET (01:30 UTC) share the local 02:00 bucket; 25 real hours, 24 buckets
        $this->assertCount(24, $hours);
        $this->assertSame(2, $hours['2026-10-25 02:00']);
        $this->assertSame(1, $hours['2026-10-25 03:00']);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
        Config('general/locale/firstday', '1', 'store', 'default'),
    ]
    public function testItGroupsWeeksStartingOnTheStoreConfiguredFirstDay(): void
    {
        // 2026-06-07 is a Sunday: with Monday as first day it belongs to the week of 06-01
        $this->setCreatedAt(1, '2026-06-07 12:00:00');
        $this->setCreatedAt(2, '2026-06-08 12:00:00');
        $this->setCreatedAt(3, '2026-06-01 00:00:00');

        $weeks = $this->ordersByWeek('2026-06-01', '2026-06-14');

        $this->assertSame(['2026-06-01' => 2, '2026-06-08' => 1], $weeks);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
        Config('general/locale/firstday', '0', 'store', 'default'),
    ]
    public function testItStartsWeeksOnSundayWhenSundayIsTheConfiguredFirstDay(): void
    {
        $this->setCreatedAt(1, '2026-06-07 12:00:00');
        $this->setCreatedAt(2, '2026-06-08 12:00:00');
        $this->setCreatedAt(3, '2026-06-01 00:00:00');

        $weeks = $this->ordersByWeek('2026-06-01', '2026-06-14');

        // Sunday-based weeks: Sun 05-31 (order 3), Sun 06-07 (orders 1, 2)
        $this->assertSame(['2026-05-31' => 1, '2026-06-07' => 2], $weeks);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Europe/Rome', 'website', 'base'),
    ]
    public function testItGroupsAMonthBoundaryOrderIntoTheLocalMonth(): void
    {
        // 22:30 UTC on 06-30 is 00:30 on 07-01 in Rome (UTC+2)
        $this->setCreatedAt(1, '2026-06-30 22:30:00');
        $this->setCreatedAt(2, '2026-06-30 21:30:00');
        $this->setCreatedAt(3, '2026-06-15 10:00:00');

        $months = $this->ordersByMonth('2026-06-01', '2026-07-31');

        $this->assertSame(['2026-06' => 2, '2026-07' => 1], $months);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Europe/Rome', 'website', 'base'),
    ]
    public function testItResolvesTodayInTheReportScopeStoreTimezone(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        // 23:30 UTC on 06-10 is already 01:30 on 06-11 in Rome
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn((new DateTimeImmutable('2026-06-10 23:30:00 UTC'))->getTimestamp());
        $resolver = $objectManager->create(
            DateRangeResolver::class,
            ['clock' => $objectManager->create(Clock::class, ['dateTime' => $dateTime])]
        );
        $scope = $objectManager->get(ReportScopeResolver::class)->resolve([$this->storeId()]);

        $range = $resolver->resolve(new DateRange(DateRangeType::TODAY), $scope);

        $this->assertSame('Europe/Rome', $scope->timezone);
        $this->assertSame('2026-06-11', $range->from->format('Y-m-d'));
        $this->assertSame('2026-06-11', $range->to->format('Y-m-d'));
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'Europe/Rome', 'website', 'base'),
        Config('general/locale/timezone', 'UTC', 'website', 'ar_website_2'),
    ]
    public function testItBucketsEachStoreInItsOwnTimezoneWhenTheScopeSpansSeveralTimezones(): void
    {
        $this->setCreatedAt(1, '2026-06-10 22:30:00');
        $this->setCreatedAt(2, '2026-06-10 22:30:00');
        $this->setCreatedAt(3, '2026-06-10 22:30:00');
        Bootstrap::getObjectManager()->get(SetOrderStore::class)->apply([
            'order_id' => $this->orderId(3),
            'store_id' => self::$secondWebsiteStore['store_id'],
        ]);

        // all stores: default store (Rome, orders 1 and 2) and second website store (UTC, order 3)
        $days = $this->ordersByDay('2026-06-09', '2026-06-12', []);

        $this->assertSame(['2026-06-10' => 1, '2026-06-11' => 2], $days);
    }

    /**
     * @param int[]|null $storeIds Null means the default store view only.
     * @return array<string,int> day => orders, days without orders omitted
     */
    private function ordersByDay(string $from, string $to, ?array $storeIds = null): array
    {
        return array_filter($this->ordersBy('day', $from, $to, $storeIds));
    }

    /**
     * @return array<string,int> week start => orders, weeks without orders omitted
     */
    private function ordersByWeek(string $from, string $to): array
    {
        return array_filter($this->ordersBy('week', $from, $to));
    }

    /**
     * @return array<string,int> month start => orders, months without orders omitted
     */
    private function ordersByMonth(string $from, string $to): array
    {
        return array_filter($this->ordersBy('month', $from, $to));
    }

    /**
     * @return array<string,int> every hourly bucket of one local day => orders (empty buckets are zero-filled)
     */
    private function ordersByHour(string $day): array
    {
        return $this->ordersBy('hour', $day, $day);
    }

    /**
     * @param int[]|null $storeIds Null means the default store view only.
     * @return array<string,int>
     */
    private function ordersBy(string $granularity, string $from, string $to, ?array $storeIds = null): array
    {
        $result = $this->runner->run(
            'sales_orders',
            ['orders'],
            [['code' => 'order_date', 'granularity' => $granularity]],
            [],
            $from,
            $to,
            $storeIds ?? [$this->storeId()]
        );
        $buckets = [];
        foreach ($this->runner->rows($result) as $row) {
            $buckets[(string) $row['order_date']] = (int) $row['orders'];
        }

        return $buckets;
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
