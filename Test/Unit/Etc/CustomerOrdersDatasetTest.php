<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\Formula\Formula;
use MageOS\AdvancedReport\Model\Label\CustomerLabelResolver;
use PHPUnit\Framework\TestCase;

final class CustomerOrdersDatasetTest extends TestCase
{
    use OrderCustomerDimensionsTestTrait;

    /**
     * @var string[]
     */
    private const array EXPECTED_METRIC_CODES = [
        'orders',
        'unique_customers',
        'gross_sales',
        'discounts',
        'returns',
        'shipping',
        'taxes',
        'adjustments',
        'total_spent',
        'avg_spent',
        'avg_shipping_cost',
        'days_since_last_order',
        'avg_wait_days',
    ];

    public function testItDefinesTheCustomerOrdersDatasetInTheCustomersAreaGuardedByTheCustomersAcl(): void
    {
        $dataset = $this->loadCustomerOrdersDataset();

        self::assertSame('customers', $dataset->area);
        self::assertSame('MageOS_AdvancedReport::customers', $dataset->aclResource);
    }

    public function testItDefinesTheCustomerOrdersMetricsInOrder(): void
    {
        self::assertSame(self::EXPECTED_METRIC_CODES, array_keys($this->loadCustomerOrdersDataset()->metrics));
    }

    public function testItHidesTheHelperSumsUsedByTotalSpent(): void
    {
        $dataset = $this->loadCustomerOrdersDataset();

        foreach (['gross_sales', 'discounts', 'returns', 'shipping', 'taxes', 'adjustments'] as $code) {
            self::assertTrue($dataset->getMetric($code)->hidden, $code);
            self::assertSame(AggregateFunction::SUM, $dataset->getMetric($code)->aggregate, $code);
        }
    }

    public function testItComputesTotalSpentAsNetSalesPlusShippingPlusTaxesMinusAdjustments(): void
    {
        $metric = $this->loadCustomerOrdersDataset()->getMetric('total_spent');

        self::assertSame('gross_sales - discounts - returns + shipping + taxes - adjustments', $metric->formula);
        self::assertSame('money', $metric->format);
    }

    public function testItComputesAverageSpentAndAverageShippingCostPerOrder(): void
    {
        $dataset = $this->loadCustomerOrdersDataset();

        self::assertSame(
            '(gross_sales - discounts - returns + shipping + taxes - adjustments) / orders',
            $dataset->getMetric('avg_spent')->formula
        );
        self::assertSame('shipping / orders', $dataset->getMetric('avg_shipping_cost')->formula);
        self::assertSame('money', $dataset->getMetric('avg_spent')->format);
        self::assertSame('money', $dataset->getMetric('avg_shipping_cost')->format);
    }

    public function testItDefinesEveryDerivedCustomerOrdersMetricOverAggregateMetricsOnlySoItCanBeSortedInSql(): void
    {
        $dataset = $this->loadCustomerOrdersDataset();

        foreach (['total_spent', 'avg_spent', 'avg_shipping_cost'] as $code) {
            $references = Formula::fromString((string) $dataset->getMetric($code)->formula)->getReferences();
            self::assertNotEmpty($references, $code);
            foreach ($references as $reference) {
                self::assertTrue($dataset->getMetric($reference)->isAggregate(), $code . ' -> ' . $reference);
            }
        }
    }

    public function testItTakesDaysSinceLastOrderAsTheMinimumOrderAgeAndMarksItLowerIsBetter(): void
    {
        $metric = $this->loadCustomerOrdersDataset()->getMetric('days_since_last_order');

        self::assertSame(AggregateFunction::MIN, $metric->aggregate);
        self::assertSame('days_since_order', $metric->fieldCode);
        self::assertSame('integer', $metric->format);
        self::assertTrue($metric->lowerIsBetter);
    }

    public function testItAveragesTheFirstShipmentWaitAndMarksItLowerIsBetter(): void
    {
        $metric = $this->loadCustomerOrdersDataset()->getMetric('avg_wait_days');

        self::assertSame(AggregateFunction::AVG, $metric->aggregate);
        self::assertSame('wait_days', $metric->fieldCode);
        self::assertSame('number', $metric->format);
        self::assertTrue($metric->lowerIsBetter);
    }

    public function testItGroupsCustomersByLowercasedTrimmedOrderEmailWithTheCustomerLabelResolver(): void
    {
        $dataset = $this->loadCustomerOrdersDataset();

        $dimension = $dataset->getDimension('customer');
        self::assertSame('customer_key', $dimension->fieldCode);
        self::assertSame(CustomerLabelResolver::class, $dimension->labelResolver);
        self::assertTrue($dataset->hasField('customer_key'));
    }

    public function testItLabelsTheCustomerKeyFieldAsCustomerEmail(): void
    {
        self::assertSame('Customer email', $this->loadCustomerOrdersDataset()->getField('customer_key')->label);
    }

    public function testItExposesTheOrderCustomerIdAsAnIdField(): void
    {
        $field = $this->loadCustomerOrdersDataset()->getField('customer_id');

        self::assertSame('Customer ID', $field->label);
        self::assertSame(FieldType::ID, $field->type);
        self::assertSame('main.customer_id', $field->sourceMappings['mysql']->expression);
    }

    public function testItCountsUniqueCustomersAsDistinctCustomerKeys(): void
    {
        $metric = $this->loadCustomerOrdersDataset()->getMetric('unique_customers');

        self::assertSame(AggregateFunction::COUNT_DISTINCT, $metric->aggregate);
        self::assertSame('customer_key', $metric->fieldCode);
    }

    private function loadCustomerOrdersDataset(): Dataset
    {
        return $this->loadReportDataset('customer_orders');
    }
}
