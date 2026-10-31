<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Sales;

use Magento\Catalog\Test\Fixture\Category;
use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Category filter on `sales_items` (one-to-many category join rendered as EXISTS / NOT EXISTS).
 * Products (10.00, one unit each, one guest order each, created 2026-03-05): ar-cf-1 in categories A and B,
 * ar-cf-2 in category A only, ar-cf-3 without category.
 *
 *  | filter category_id | units_sold                          |
 *  | in [A, B]          | 2 (ar-cf-1 once, ar-cf-2)           |
 *  | neq B              | 2 (ar-cf-2, ar-cf-3)                |
 *  | neq A              | 1 (ar-cf-3)                         |
 *  | nin [A]            | 1 (ar-cf-3)                         |
 *  | in [A, B] by category | A: 2, B: 1 (join kept)           |
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Category::class, as: 'catA'),
    DataFixture(Category::class, as: 'catB'),
    DataFixture(
        Product::class,
        ['sku' => 'ar-cf-1', 'price' => 10, 'tax_class_id' => 0, 'category_ids' => ['$catA.id$', '$catB.id$']],
        'p1'
    ),
    DataFixture(Product::class, ['sku' => 'ar-cf-2', 'price' => 10, 'tax_class_id' => 0, 'category_ids' => ['$catA.id$']], 'p2'),
    DataFixture(Product::class, ['sku' => 'ar-cf-3', 'price' => 10, 'tax_class_id' => 0], 'p3'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$p1.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-cf-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-03-05 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$p2.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-cf-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-03-05 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$p3.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-cf-3@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-03-05 12:00:00']),
]
final class CategoryFilterTest extends TestCase
{
    private const string FROM = '2026-03-01';
    private const string TO = '2026-03-31';

    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItCountsAProductInCategoriesAAndBOnceWhenFilteringCategoryInAAndB(): void
    {
        $this->assertEqualsWithDelta(2.0, $this->units('in', [$this->id('catA'), $this->id('catB')]), 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItCountsAProductOnceWhenFilteringCategoryNotEqualToOneOfItsCategories(): void
    {
        // ar-cf-1 is in B (excluded although it also has the row of A); ar-cf-2 and ar-cf-3 remain
        $this->assertEqualsWithDelta(2.0, $this->units('neq', $this->id('catB')), 0.0001);
        // ar-cf-1 and ar-cf-2 are in A (their only or one of their categories)
        $this->assertEqualsWithDelta(1.0, $this->units('neq', $this->id('catA')), 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItKeepsProductsWithoutACategoryWhenFilteringCategoryNotEqual(): void
    {
        $result = $this->runner->run(
            'sales_items',
            ['units_sold'],
            [['code' => 'sku', 'granularity' => null]],
            [['field' => 'category_id', 'operator' => 'neq', 'value' => $this->id('catA')]],
            self::FROM,
            self::TO
        );

        $skus = array_column($this->runner->rows($result), 'sku');
        $this->assertSame(['ar-cf-3'], $skus);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItKeepsProductsWithoutACategoryWhenFilteringCategoryNotInAList(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->units('nin', [$this->id('catA')]), 0.0001);
        $this->assertEqualsWithDelta(1.0, $this->units('nin', [$this->id('catA'), $this->id('catB')]), 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItKeepsTheJoinAndWhereClauseWhenCategoryIsAlsoASelectedDimension(): void
    {
        $result = $this->runner->run(
            'sales_items',
            ['units_sold'],
            [['code' => 'category_id', 'granularity' => null]],
            [['field' => 'category_id', 'operator' => 'in', 'value' => [$this->id('catA'), $this->id('catB')]]],
            self::FROM,
            self::TO
        );

        $byCategory = [];
        foreach ($this->runner->rows($result) as $row) {
            $byCategory[(string) $row['category_id']] = (float) $row['units_sold'];
        }
        $this->assertEqualsWithDelta(2.0, $byCategory[(string) $this->id('catA')], 0.0001);
        $this->assertEqualsWithDelta(1.0, $byCategory[(string) $this->id('catB')], 0.0001);
    }

    /**
     * @param string $operator
     * @param int|int[] $value
     */
    private function units(string $operator, int|array $value): float
    {
        $result = $this->runner->run(
            'sales_items',
            ['units_sold'],
            [],
            [['field' => 'category_id', 'operator' => $operator, 'value' => $value]],
            self::FROM,
            self::TO
        );

        return (float) $this->runner->totals($result)['units_sold'];
    }

    private function id(string $fixture): int
    {
        return (int) DataFixtureStorageManager::getStorage()->get($fixture)->getId();
    }
}
