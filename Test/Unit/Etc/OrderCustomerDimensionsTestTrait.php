<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Label\GenderLabelResolver;
use MageOS\AdvancedReport\Model\Label\MonthLabelResolver;
use MageOS\AdvancedReport\Model\Label\WeekdayLabelResolver;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Customer dimension assertions shared by the order based datasets (sales_orders, customer_orders).
 */
trait OrderCustomerDimensionsTestTrait
{
    use ReportDatasetsFixtureTrait;

    /**
     * @return array<string,array{string}>
     */
    public static function orderDatasetCodeProvider(): array
    {
        return ['sales_orders' => ['sales_orders'], 'customer_orders' => ['customer_orders']];
    }

    #[DataProvider('orderDatasetCodeProvider')]
    public function testItOffersBillingZipcodeYearMonthWeekdayOfBirthAndGenderDimensionsOnOrderDatasets(
        string $code
    ): void {
        $dataset = $this->loadReportDataset($code);

        foreach (['billing_postcode', 'birth_year', 'birth_month', 'birth_weekday', 'gender'] as $dimension) {
            self::assertSame($dimension, $dataset->getDimension($dimension)->fieldCode, $dimension);
            self::assertTrue($dataset->hasField($dimension), $dimension);
        }
        self::assertSame(FieldType::STRING, $dataset->getField('billing_postcode')->type);
        self::assertSame(FieldType::INTEGER, $dataset->getField('birth_year')->type);
        self::assertSame(FieldType::INTEGER, $dataset->getField('birth_month')->type);
        self::assertSame(FieldType::INTEGER, $dataset->getField('birth_weekday')->type);
        self::assertSame(FieldType::ID, $dataset->getField('gender')->type);
    }

    #[DataProvider('orderDatasetCodeProvider')]
    public function testItGroupsBillingZipcodeByTheUppercasedTrimmedOrderBillingPostcode(string $code): void
    {
        $mapping = $this->loadReportDataset($code)->getField('billing_postcode')->sourceMappings['mysql'];

        self::assertSame('UPPER(TRIM(billing_address.postcode))', $mapping->expression);
        self::assertSame(['billing_address'], $mapping->joins);
    }

    #[DataProvider('orderDatasetCodeProvider')]
    public function testItDerivesYearMonthAndWeekdayOfBirthFromTheOrderCustomerDob(string $code): void
    {
        $dataset = $this->loadReportDataset($code);

        self::assertSame(
            'YEAR(main.customer_dob)',
            $dataset->getField('birth_year')->sourceMappings['mysql']->expression
        );
        self::assertSame(
            'MONTH(main.customer_dob)',
            $dataset->getField('birth_month')->sourceMappings['mysql']->expression
        );
        self::assertSame(
            '(DAYOFWEEK(main.customer_dob) - 1)',
            $dataset->getField('birth_weekday')->sourceMappings['mysql']->expression
        );
    }

    #[DataProvider('orderDatasetCodeProvider')]
    public function testItGroupsGenderByTheOrderCustomerGender(string $code): void
    {
        $mapping = $this->loadReportDataset($code)->getField('gender')->sourceMappings['mysql'];

        self::assertSame('main.customer_gender', $mapping->expression);
    }

    #[DataProvider('orderDatasetCodeProvider')]
    public function testItLabelsBirthMonthWeekdayAndGenderDimensionsWithTheirLabelResolvers(string $code): void
    {
        $dataset = $this->loadReportDataset($code);

        self::assertSame(MonthLabelResolver::class, $dataset->getDimension('birth_month')->labelResolver);
        self::assertSame(WeekdayLabelResolver::class, $dataset->getDimension('birth_weekday')->labelResolver);
        self::assertSame(GenderLabelResolver::class, $dataset->getDimension('gender')->labelResolver);
        self::assertNull($dataset->getDimension('billing_postcode')->labelResolver);
        self::assertNull($dataset->getDimension('birth_year')->labelResolver);
    }

    #[DataProvider('orderDatasetCodeProvider')]
    public function testItKeepsTheOrderDatasetsPassingDefinitionValidation(string $code): void
    {
        $dataset = $this->loadReportDataset($code);

        foreach (['billing_postcode', 'birth_year', 'birth_month', 'birth_weekday', 'gender'] as $dimension) {
            self::assertTrue($dataset->getField($dimension)->isMappedForSource('mysql'), $dimension);
            self::assertArrayHasKey($dimension, $dataset->dimensions, $dimension);
        }
    }
}
