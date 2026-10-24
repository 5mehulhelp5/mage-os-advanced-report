<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Export;

use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Api\ReportRunnerInterface;
use MageOS\AdvancedReport\Model\Export\CsvExporter;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * CSV export written to a file in `var/` with a single report execution.
 *
 * Inputs: one guest order created 2026-06-10 12:00:00 with one unit each of ar-ex-a, ar-ex-b, ar-ex-c.
 *
 * Expected file (report `sales_items` by sku, metric units_sold, sorted by sku, totals row included):
 *  | line   | content                                  |
 *  | header | SKU,Units sold                           |
 *  | rows   | ar-ex-a,1 / ar-ex-b,1 / ar-ex-c,1        |
 *  | totals | Total,3                                  |
 * The runner is decorated with a counter: the exporter must call it exactly once.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-ex-a', 'price' => 10, 'tax_class_id' => 0], 'productA'),
    DataFixture(Product::class, ['sku' => 'ar-ex-b', 'price' => 20, 'tax_class_id' => 0], 'productB'),
    DataFixture(Product::class, ['sku' => 'ar-ex-c', 'price' => 30, 'tax_class_id' => 0], 'productC'),
    DataFixture(GuestCart::class, as: 'cart'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$productB.id$', 'qty' => 1]),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$productC.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'ar-ex@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart.id$'], 'order'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order.id$', 'created_at' => '2026-06-10 12:00:00']),
]
final class CsvExportTest extends TestCase
{
    private ReportTestRunner $runner;
    private string $exportedPath = '';

    protected function setUp(): void
    {
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        if ($this->exportedPath !== '') {
            Bootstrap::getObjectManager()->get(Filesystem::class)
                ->getDirectoryWrite(DirectoryList::VAR_DIR)
                ->delete($this->exportedPath);
        }
        $this->runner->logout();
    }

    public function testItExportsTheReportRowsToACsvFileWithOneReportExecution(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $countingRunner = new class($objectManager->get(ReportRunnerInterface::class)) implements ReportRunnerInterface {
            public int $runs = 0;

            public function __construct(private readonly ReportRunnerInterface $inner)
            {
            }

            public function run(ReportRequestInterface $request): ReportResultInterface
            {
                $this->runs++;

                return $this->inner->run($request);
            }
        };
        $exporter = $objectManager->create(CsvExporter::class, ['reportRunner' => $countingRunner]);
        $payload = [
            'dataset' => 'sales_items',
            'metrics' => ['units_sold'],
            'dimensions' => [['code' => 'sku', 'granularity' => null]],
            'filters' => [['field' => 'sku', 'operator' => 'in', 'value' => ['ar-ex-a', 'ar-ex-b', 'ar-ex-c']]],
            'sort' => [['by' => 'sku', 'direction' => 'asc']],
            'dateRange' => ['type' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-30'],
            'storeIds' => [],
            'bypassCache' => true,
        ];

        $this->exportedPath = $exporter->exportToFile(
            $objectManager->get(ReportRequestFactory::class)->fromArray($payload),
            true
        );

        $this->assertSame(1, $countingRunner->runs);
        $directory = $objectManager->get(Filesystem::class)->getDirectoryRead(DirectoryList::VAR_DIR);
        $this->assertTrue($directory->isFile($this->exportedPath), 'The CSV file exists in var/');
        $lines = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            explode("\n", trim($directory->readFile($this->exportedPath)))
        );
        $this->assertSame(
            [['SKU', 'Units sold'], ['ar-ex-a', '1'], ['ar-ex-b', '1'], ['ar-ex-c', '1'], ['Total', '3']],
            $lines
        );

        $rows = $this->runner->rows($this->runner->runWith(
            'sales_items',
            ['units_sold'],
            [['code' => 'sku', 'granularity' => null]],
            [['by' => 'sku', 'direction' => 'asc']],
            true,
            array_intersect_key($payload, array_flip(['filters', 'dateRange']))
        ));
        $this->assertSame(
            array_map(static fn (array $row): array => [$row['sku'], (string) (int) $row['units_sold']], $rows),
            \array_slice($lines, 1, 3),
            'The file rows equal the runner rows'
        );
    }
}
