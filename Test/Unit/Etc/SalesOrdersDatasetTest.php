<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use PHPUnit\Framework\TestCase;

final class SalesOrdersDatasetTest extends TestCase
{
    use OrderCustomerDimensionsTestTrait;

    /**
     * @var string[]
     */
    private const array EXPECTED_METRIC_CODES = [
        'orders',
        'gross_sales',
        'discounts',
        'returns',
        'net_sales',
        'shipping',
        'taxes',
        'adjustments',
        'total_sales',
        'aov',
        'units',
        'new_customer_orders',
        'returning_customer_orders',
    ];

    public function testItDefinesAllThirteenSalesOrdersMetrics(): void
    {
        $dataset = $this->loadSalesOrdersDataset();

        self::assertCount(13, $dataset->metrics);
        self::assertSame(self::EXPECTED_METRIC_CODES, array_keys($dataset->metrics));
    }

    public function testItDefinesDerivedMetricsNetSalesTotalSalesAovReturningCustomerOrdersWithValidFormulas(): void
    {
        $dataset = $this->loadSalesOrdersDataset();

        $netSales = $dataset->getMetric('net_sales');
        self::assertTrue($netSales->isDerived());
        self::assertSame('gross_sales - discounts - returns', $netSales->formula);

        $totalSales = $dataset->getMetric('total_sales');
        self::assertTrue($totalSales->isDerived());
        self::assertSame('net_sales + shipping + taxes - adjustments', $totalSales->formula);

        $aov = $dataset->getMetric('aov');
        self::assertTrue($aov->isDerived());
        self::assertSame('net_sales / orders', $aov->formula);

        $returningCustomerOrders = $dataset->getMetric('returning_customer_orders');
        self::assertTrue($returningCustomerOrders->isDerived());
        self::assertSame('orders - new_customer_orders', $returningCustomerOrders->formula);
    }

    public function testItPassesDefinitionValidationWithoutErrors(): void
    {
        $dataset = $this->loadSalesOrdersDataset();

        self::assertCount(13, $dataset->metrics, 'No metric should have been dropped by the validator.');
    }

    public function testItGuardsTheDatasetWithTheSalesAclResource(): void
    {
        $dataset = $this->loadSalesOrdersDataset();

        self::assertSame('MageOS_AdvancedReport::sales', $dataset->aclResource);
    }

    public function testItOffersHourDayWeekMonthYearGranularitiesOnOrderDate(): void
    {
        $dataset = $this->loadSalesOrdersDataset();

        $orderDate = $dataset->getDimension('order_date');

        self::assertSame(
            [Granularity::HOUR, Granularity::DAY, Granularity::WEEK, Granularity::MONTH, Granularity::YEAR],
            $orderDate->getGranularities()
        );
    }

    public function testItGivesEveryMetricANonEmptyDescription(): void
    {
        $dataset = $this->loadSalesOrdersDataset();

        foreach ($dataset->metrics as $code => $metric) {
            self::assertNotEmpty($metric->description, \sprintf('Metric "%s" has no description.', $code));
        }
    }

    private function loadSalesOrdersDataset(): Dataset
    {
        return $this->loadReportDataset('sales_orders');
    }
}
