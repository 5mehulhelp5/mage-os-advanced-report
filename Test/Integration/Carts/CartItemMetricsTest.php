<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Carts;

use Magento\Catalog\Test\Fixture\Category;
use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\ConfigurableProduct\Test\Fixture\AddProductToCart as AddConfigurableProductToCart;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttribute;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProduct;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Query\Clock;
use MageOS\AdvancedReport\Model\Query\ClockInterface;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetQuoteUpdatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\FixedClock;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Cart item metrics on fixture quotes. "Now" is pinned to 2026-03-15 12:00:00 UTC; the default abandoned and
 * recent thresholds are 24 hours. Products: ar-ci-a 10.00 (in category A), ar-ci-b 20.00, configurable ar-ci-conf
 * with child ar-ci-red 30.00. No tax, flat rate 5.00 per order.
 *
 *  | cart | kind                         | updated_at (UTC)    | items                                  |
 *  | 1    | guest, email, active         | 2026-03-10 05:00:00 | 2 x A (20) + 1 x B (20)  abandoned     |
 *  | 2    | guest, email, active         | 2026-03-15 08:00:00 | 1 x A (10) + 1 x conf/red (30) recent  |
 *  | 3    | guest, email, order placed   | 2026-03-12 12:00:00 | 1 x A (10)               converted     |
 *  | 4    | guest, email, items_count 0  | 2026-03-10 10:00:00 | 1 x A (10)               excluded      |
 *  | 5    | guest, email, active         | 2026-03-10 06:00:00 | 1 x A (10)               abandoned     |
 *
 * Expected values (hard-coded in the tests), range 2026-03-01..2026-03-31:
 *  | units_in_carts 7 | cart_items_value 100 | carts 4 | abandoned units 4 / value 50 / carts 2 |
 *  | converted_units 1 | item_conversion_rate 1 / 7 |
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    Config('carriers/flatrate/active', '1'),
    Config('carriers/flatrate/type', 'O'),
    Config('carriers/flatrate/price', '5'),
    Config('payment/checkmo/active', '1'),
    Config('general/locale/timezone', 'UTC'),
    Config('general/locale/timezone', 'UTC', 'website', 'base'),
    DataFixture(Category::class, as: 'catA'),
    DataFixture(Product::class, ['sku' => 'ar-ci-a', 'price' => 10, 'tax_class_id' => 0, 'category_ids' => ['$catA.id$']], 'a'),
    DataFixture(Product::class, ['sku' => 'ar-ci-b', 'price' => 20, 'tax_class_id' => 0], 'b'),
    DataFixture(ConfigurableAttribute::class, as: 'attr'),
    DataFixture(Product::class, ['sku' => 'ar-ci-red', 'price' => 30, 'tax_class_id' => 0], 'red'),
    DataFixture(
        ConfigurableProduct::class,
        ['sku' => 'ar-ci-conf', '_options' => ['$attr$'], '_links' => ['$red$'], 'tax_class_id' => 0],
        'conf'
    ),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$a.id$', 'qty' => 2]),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$b.id$', 'qty' => 1]),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-ci-1@example.com']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart1.id$', 'updated_at' => '2026-03-10 05:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$a.id$', 'qty' => 1]),
    DataFixture(
        AddConfigurableProductToCart::class,
        ['cart_id' => '$cart2.id$', 'product_id' => '$conf.id$', 'child_product_id' => '$red.id$', 'qty' => 1]
    ),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-ci-2@example.com']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart2.id$', 'updated_at' => '2026-03-15 08:00:00']),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$a.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-ci-3@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-03-12 12:00:00']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart3.id$', 'updated_at' => '2026-03-12 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart4'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart4.id$', 'product_id' => '$a.id$', 'qty' => 1]),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart4.id$', 'email' => 'ar-ci-4@example.com']),
    DataFixture(
        SetQuoteUpdatedAt::class,
        ['cart_id' => '$cart4.id$', 'updated_at' => '2026-03-10 10:00:00', 'items_count' => 0]
    ),
    DataFixture(GuestCart::class, as: 'cart5'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart5.id$', 'product_id' => '$a.id$', 'qty' => 1]),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart5.id$', 'email' => 'ar-ci-5@example.com']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart5.id$', 'updated_at' => '2026-03-10 06:00:00']),
]
final class CartItemMetricsTest extends TestCase
{
    private const string PINNED_NOW = '2026-03-15 12:00:00';

    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        $clock = new FixedClock(self::PINNED_NOW);
        $objectManager = Bootstrap::getObjectManager();
        // Before the runner exists: the cart providers are built when the runner is resolved.
        $objectManager->addSharedInstance($clock, Clock::class);
        $objectManager->addSharedInstance($clock, ClockInterface::class);
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    public function testItLoadsTheCartItemsDatasetWithoutValidationErrors(): void
    {
        $dataset = Bootstrap::getObjectManager()->get(DefinitionRegistry::class)->getDataset('cart_items');

        $this->assertSame('cart_items', $dataset->code);
        $this->assertTrue($dataset->hasField('is_abandoned'));
    }

    public function testItSumsUnitsAndRowValueOfTopLevelCartItemsOnly(): void
    {
        $totals = $this->march(['units_in_carts', 'cart_items_value', 'carts']);

        $this->assertEqualsWithDelta(7.0, (float) $totals['units_in_carts'], 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $totals['cart_items_value'], 0.0001);
        $this->assertEquals(4, $totals['carts']);
    }

    public function testItCountsAConfigurableProductInACartOnce(): void
    {
        $confId = (string) DataFixtureStorageManager::getStorage()->get('conf')->getId();
        $redId = (string) DataFixtureStorageManager::getStorage()->get('red')->getId();

        $rows = $this->rowsBy(['units_in_carts', 'cart_items_value'], 'product_id');

        $this->assertEqualsWithDelta(1.0, (float) $rows[$confId]['units_in_carts'], 0.0001);
        $this->assertEqualsWithDelta(30.0, (float) $rows[$confId]['cart_items_value'], 0.0001);
        $this->assertArrayNotHasKey($redId, $rows);
    }

    public function testItExcludesItemsOfEmptyOrMissingQuotes(): void
    {
        // cart 4 holds an item but has items_count 0: its unit and 10 value are not reported
        $totals = $this->march(['units_in_carts', 'carts']);

        $this->assertEqualsWithDelta(7.0, (float) $totals['units_in_carts'], 0.0001);
        $this->assertEquals(4, $totals['carts']);
    }

    public function testItSumsUnitsOfItemsInAbandonedCartsUsingTheAbandonedThreshold(): void
    {
        $totals = $this->march(['abandoned_units', 'abandoned_items_value']);

        $this->assertEqualsWithDelta(4.0, (float) $totals['abandoned_units'], 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $totals['abandoned_items_value'], 0.0001);
    }

    public function testItCountsDistinctAbandonedCartsPerProduct(): void
    {
        $aId = (string) DataFixtureStorageManager::getStorage()->get('a')->getId();
        $bId = (string) DataFixtureStorageManager::getStorage()->get('b')->getId();

        $rows = $this->rowsBy(['abandoned_carts'], 'product_id');

        $this->assertEquals(2, $rows[$aId]['abandoned_carts']);
        $this->assertEquals(1, $rows[$bId]['abandoned_carts']);
    }

    public function testItGroupsCartItemsByParentSkuAndCategory(): void
    {
        $bySku = $this->rowsBy(['units_in_carts'], 'parent_sku');
        $catId = (string) DataFixtureStorageManager::getStorage()->get('catA')->getId();
        $byCategory = $this->rowsBy(['units_in_carts'], 'category_id');

        $this->assertEqualsWithDelta(5.0, (float) $bySku['ar-ci-a']['units_in_carts'], 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $bySku['ar-ci-b']['units_in_carts'], 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $bySku['ar-ci-conf']['units_in_carts'], 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $byCategory[$catId]['units_in_carts'], 0.0001);
    }

    public function testItComputesTheItemConversionRateFromUnitsInConvertedCarts(): void
    {
        $totals = $this->march(['converted_units', 'item_conversion_rate']);

        $this->assertEqualsWithDelta(1.0, (float) $totals['converted_units'], 0.0001);
        $this->assertEqualsWithDelta(1 / 7, (float) $totals['item_conversion_rate'], 0.0001);
    }

    /**
     * @param string[] $metrics
     * @return array<string,mixed>
     */
    private function march(array $metrics): array
    {
        return $this->runner->totals($this->runner->run('cart_items', $metrics, [], [], '2026-03-01', '2026-03-31'));
    }

    /**
     * @param string[] $metrics
     * @return array<string,array<string,mixed>>
     */
    private function rowsBy(array $metrics, string $dimension): array
    {
        $result = $this->runner->run(
            'cart_items',
            $metrics,
            [['code' => $dimension, 'granularity' => null]],
            [],
            '2026-03-01',
            '2026-03-31'
        );
        $keyed = [];
        foreach ($this->runner->rows($result) as $row) {
            $keyed[(string) $row[$dimension]] = $row;
        }

        return $keyed;
    }
}
