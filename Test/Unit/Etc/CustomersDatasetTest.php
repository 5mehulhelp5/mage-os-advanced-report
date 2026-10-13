<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Label\CustomerGroupLabelResolver;
use MageOS\AdvancedReport\Model\Label\GenderLabelResolver;
use MageOS\AdvancedReport\Model\Label\MonthLabelResolver;
use MageOS\AdvancedReport\Model\Label\StoreLabelResolver;
use MageOS\AdvancedReport\Model\Label\WebsiteLabelResolver;
use MageOS\AdvancedReport\Model\Label\WeekdayLabelResolver;
use PHPUnit\Framework\TestCase;

final class CustomersDatasetTest extends TestCase
{
    use ReportDatasetsFixtureTrait;

    public function testItDefinesTheCustomersDatasetOnTheCustomerEntityTableInTheCustomersArea(): void
    {
        $dataset = $this->loadCustomersDataset();

        self::assertSame('customer_entity', $dataset->baseTable);
        self::assertSame('main', $dataset->baseAlias);
        self::assertSame('customers', $dataset->area);
        self::assertSame([], $dataset->implicitFilters);
        self::assertSame(['default_billing_address'], array_keys($dataset->joins));
    }

    public function testItGuardsTheCustomersDatasetWithTheCustomersAcl(): void
    {
        self::assertSame('MageOS_AdvancedReport::customers', $this->loadCustomersDataset()->aclResource);
    }

    public function testItCountsRegistrationsByCustomerEntityId(): void
    {
        $dataset = $this->loadCustomersDataset();
        $metric = $dataset->getMetric('registrations');

        self::assertSame(AggregateFunction::COUNT, $metric->aggregate);
        self::assertSame('customer_id', $metric->fieldCode);
        self::assertSame('integer', $metric->format);
        self::assertSame('main.entity_id', $dataset->getField('customer_id')->sourceMappings['mysql']->expression);
    }

    public function testItMapsTheReservedCreatedAtAndStoreIdFieldsToTheCustomerEntity(): void
    {
        $dataset = $this->loadCustomersDataset();

        self::assertSame('main.created_at', $dataset->getField('created_at')->sourceMappings['mysql']->expression);
        self::assertSame('main.store_id', $dataset->getField('store_id')->sourceMappings['mysql']->expression);
        self::assertSame('main.website_id', $dataset->getField('website_id')->sourceMappings['mysql']->expression);
        self::assertSame(
            'main.group_id',
            $dataset->getField('customer_group_id')->sourceMappings['mysql']->expression
        );
    }

    public function testItOffersRegistrationDateWebsiteStoreAndCustomerGroupDimensions(): void
    {
        $dataset = $this->loadCustomersDataset();

        self::assertSame(
            [Granularity::DAY, Granularity::WEEK, Granularity::MONTH, Granularity::YEAR],
            $dataset->getDimension('registration_date')->getGranularities()
        );
        self::assertSame('created_at', $dataset->getDimension('registration_date')->fieldCode);
        self::assertSame(WebsiteLabelResolver::class, $dataset->getDimension('website_id')->labelResolver);
        self::assertSame(StoreLabelResolver::class, $dataset->getDimension('store_id')->labelResolver);
        self::assertSame(
            CustomerGroupLabelResolver::class,
            $dataset->getDimension('customer_group_id')->labelResolver
        );
    }

    public function testItOffersBillingZipcodeYearMonthWeekdayOfBirthAndGenderDimensionsOnTheCustomersDataset(): void
    {
        $dataset = $this->loadCustomersDataset();

        foreach (['billing_postcode', 'birth_year', 'birth_month', 'birth_weekday', 'gender'] as $dimension) {
            self::assertSame($dimension, $dataset->getDimension($dimension)->fieldCode, $dimension);
            self::assertTrue($dataset->hasField($dimension), $dimension);
        }
        self::assertSame(FieldType::STRING, $dataset->getField('billing_postcode')->type);
        self::assertSame(FieldType::INTEGER, $dataset->getField('birth_year')->type);
        self::assertSame(FieldType::INTEGER, $dataset->getField('birth_month')->type);
        self::assertSame(FieldType::INTEGER, $dataset->getField('birth_weekday')->type);
        self::assertSame(FieldType::ID, $dataset->getField('gender')->type);
        self::assertSame(MonthLabelResolver::class, $dataset->getDimension('birth_month')->labelResolver);
        self::assertSame(WeekdayLabelResolver::class, $dataset->getDimension('birth_weekday')->labelResolver);
        self::assertSame(GenderLabelResolver::class, $dataset->getDimension('gender')->labelResolver);
    }

    public function testItJoinsTheDefaultBillingAddressOfTheCustomerAccount(): void
    {
        $join = $this->loadCustomersDataset()->joins['default_billing_address'];

        self::assertSame('customer_address_entity', $join->table);
        self::assertSame('default_billing_address', $join->alias);
        self::assertSame('default_billing_address.entity_id = main.default_billing', $join->condition);
    }

    public function testItGroupsBillingZipcodeByTheUppercasedTrimmedDefaultBillingPostcode(): void
    {
        $mapping = $this->loadCustomersDataset()->getField('billing_postcode')->sourceMappings['mysql'];

        self::assertSame('UPPER(TRIM(default_billing_address.postcode))', $mapping->expression);
        self::assertSame(['default_billing_address'], $mapping->joins);
    }

    public function testItDerivesYearMonthAndWeekdayOfBirthFromTheCustomerAccountDob(): void
    {
        $dataset = $this->loadCustomersDataset();

        self::assertSame('YEAR(main.dob)', $dataset->getField('birth_year')->sourceMappings['mysql']->expression);
        self::assertSame('MONTH(main.dob)', $dataset->getField('birth_month')->sourceMappings['mysql']->expression);
        self::assertSame(
            '(DAYOFWEEK(main.dob) - 1)',
            $dataset->getField('birth_weekday')->sourceMappings['mysql']->expression
        );
    }

    public function testItGroupsGenderByTheCustomerAccountGender(): void
    {
        $mapping = $this->loadCustomersDataset()->getField('gender')->sourceMappings['mysql'];

        self::assertSame('main.gender', $mapping->expression);
    }

    public function testItKeepsTheCustomersDatasetPassingDefinitionValidation(): void
    {
        $dataset = $this->loadCustomersDataset();

        foreach (['billing_postcode', 'birth_year', 'birth_month', 'birth_weekday', 'gender'] as $dimension) {
            self::assertTrue($dataset->getField($dimension)->isMappedForSource('mysql'), $dimension);
            self::assertArrayHasKey($dimension, $dataset->dimensions, $dimension);
        }
    }

    private function loadCustomersDataset(): Dataset
    {
        return $this->loadReportDataset('customers');
    }
}
