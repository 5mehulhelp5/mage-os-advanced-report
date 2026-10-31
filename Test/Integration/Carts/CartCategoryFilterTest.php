<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Carts;

use Magento\Catalog\Test\Fixture\Category;
use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetQuoteUpdatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Category filter on `cart_items` (EXISTS on the one-to-many category join). One active guest cart per product
 * (one unit, updated 2026-03-10): ar-cf-1 in categories A and B, ar-cf-2 in A only, ar-cf-3 without category.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    Config('general/locale/timezone', 'UTC'),
    Config('general/locale/timezone', 'UTC', 'website', 'base'),
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
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-cf-1@example.com']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart1.id$', 'updated_at' => '2026-03-10 10:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$p2.id$', 'qty' => 1]),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-cf-2@example.com']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart2.id$', 'updated_at' => '2026-03-10 10:00:00']),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$p3.id$', 'qty' => 1]),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-cf-3@example.com']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart3.id$', 'updated_at' => '2026-03-10 10:00:00']),
]
final class CartCategoryFilterTest extends TestCase
{
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

    public function testItAppliesTheExistsFilterToCartItems(): void
    {
        $catA = $this->id('catA');
        $catB = $this->id('catB');

        // ar-cf-1 (A and B) counts once, ar-cf-2 (A): 2 units
        $this->assertEqualsWithDelta(2.0, $this->units('in', [$catA, $catB]), 0.0001);
        // not B: ar-cf-2 and the category-less ar-cf-3
        $this->assertEqualsWithDelta(2.0, $this->units('neq', $catB), 0.0001);
        // not in [A, B]: only the category-less ar-cf-3
        $this->assertEqualsWithDelta(1.0, $this->units('nin', [$catA, $catB]), 0.0001);
    }

    /**
     * @param string $operator
     * @param int|int[] $value
     */
    private function units(string $operator, int|array $value): float
    {
        $result = $this->runner->run(
            'cart_items',
            ['units_in_carts'],
            [],
            [['field' => 'category_id', 'operator' => $operator, 'value' => $value]],
            '2026-03-01',
            '2026-03-31'
        );

        return (float) $this->runner->totals($result)['units_in_carts'];
    }

    private function id(string $fixture): int
    {
        return (int) DataFixtureStorageManager::getStorage()->get($fixture)->getId();
    }
}
