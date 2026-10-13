<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

final class CustomerPresetsTest extends TestCase
{
    use ReportDatasetsFixtureTrait;

    public function testItDefinesTheCustomerValuePresetPerCustomerSortedByTotalSpentDescending(): void
    {
        $preset = $this->loadReportPresets()['customer_value'];

        self::assertSame('customer_orders', $preset->dataset);
        self::assertSame('customer', $preset->dimensions[0]['code']);
        self::assertCount(1, $preset->sort);
        self::assertSame('total_spent', $preset->sort[0]['metric']);
        self::assertSame('desc', $preset->sort[0]['direction']);
    }

    public function testItIncludesOrdersTotalSpentAverageSpentAverageShippingCostAverageWaitAndDaysSinceLastOrderInCustomerValue(): void
    {
        $preset = $this->loadReportPresets()['customer_value'];

        self::assertSame(
            ['orders', 'total_spent', 'avg_spent', 'avg_shipping_cost', 'avg_wait_days', 'days_since_last_order'],
            $preset->metrics
        );
    }

    public function testItDefaultsCustomerValueToTheYearToDatePeriod(): void
    {
        self::assertSame('year_to_date', $this->loadReportPresets()['customer_value']->dateRange);
    }

    public function testItDefinesTheNewCustomerRegistrationsPresetByDayOverTheLast30Days(): void
    {
        $preset = $this->loadReportPresets()['new_customer_registrations'];

        self::assertSame('customers', $preset->dataset);
        self::assertSame(['registrations'], $preset->metrics);
        self::assertSame('registration_date', $preset->dimensions[0]['code']);
        self::assertSame('day', $preset->dimensions[0]['granularity']?->value);
        self::assertSame('last_30_days', $preset->dateRange);
    }

    public function testItComparesNewCustomerRegistrationsWithThePreviousPeriod(): void
    {
        self::assertSame('previous_period', $this->loadReportPresets()['new_customer_registrations']->compareTo);
    }

    public function testItPlacesBothCustomerPresetsInTheCustomersArea(): void
    {
        $presets = $this->loadReportPresets();

        self::assertSame('customers', $presets['customer_value']->area);
        self::assertSame('customers', $presets['new_customer_registrations']->area);
    }
}
