<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use PHPUnit\Framework\TestCase;

final class BaseCurrencyFieldTest extends TestCase
{
    use ReportDatasetsFixtureTrait;

    public function testItMapsTheBaseCurrencyFieldOnTheSalesOrdersCustomerOrdersAndSalesItemsDatasets(): void
    {
        $datasets = $this->loadReportDatasets();

        foreach (['sales_orders', 'customer_orders', 'sales_items'] as $code) {
            self::assertTrue($datasets[$code]->hasField(Dataset::FIELD_BASE_CURRENCY), $code);
            self::assertSame([], array_filter(
                $datasets[$code]->dimensions,
                static fn (Dimension $dimension): bool => $dimension->fieldCode === Dataset::FIELD_BASE_CURRENCY
            ));
        }
        self::assertSame(
            'main.base_currency_code',
            $datasets['sales_orders']->getField('base_currency_code')->sourceMappings['mysql']->expression
        );
        self::assertSame(
            'order.base_currency_code',
            $datasets['sales_items']->getField('base_currency_code')->sourceMappings['mysql']->expression
        );
    }

    public function testItExposesTheBaseCurrencyFieldAsAStringFilterWithATranslatedLabel(): void
    {
        $field = $this->loadReportDataset('sales_orders')->getField(Dataset::FIELD_BASE_CURRENCY);

        self::assertSame(FieldType::STRING, $field->type);
        self::assertSame('Base currency', $field->label);
        foreach (['en_US' => 'Base currency', 'it_IT' => 'Valuta di base'] as $locale => $translation) {
            self::assertStringContainsString(
                '"Base currency","' . $translation . '"',
                (string) file_get_contents(__DIR__ . '/../../../i18n/' . $locale . '.csv')
            );
        }
    }
}
