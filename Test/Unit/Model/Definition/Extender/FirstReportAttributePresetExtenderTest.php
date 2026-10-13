<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Definition\Extender;

use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Definition\Extender\FirstReportAttributePresetExtender;
use MageOS\AdvancedReport\Model\Definition\Preset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FirstReportAttributePresetExtender::class)]
final class FirstReportAttributePresetExtenderTest extends TestCase
{
    public function testItHidesTheAttributePresetWhenNoReportAttributeIsConfigured(): void
    {
        $extender = new FirstReportAttributePresetExtender($this->configWithAttributes([]));

        $result = $extender->extend([
            'sales_by_attribute' => $this->preset('sales_by_attribute', '{first_report_attribute}'),
            'stock_value' => $this->preset('stock_value', 'sku'),
        ]);

        self::assertSame(['stock_value'], array_keys($result));
    }

    public function testItResolvesTheAttributePresetPlaceholderToTheFirstConfiguredAttributeDimension(): void
    {
        $extender = new FirstReportAttributePresetExtender($this->configWithAttributes(['color', 'size']));

        $result = $extender->extend([
            'sales_by_attribute' => $this->preset('sales_by_attribute', '{first_report_attribute}'),
            'stock_value' => $this->preset('stock_value', 'sku'),
        ]);

        self::assertSame('attr_color', $result['sales_by_attribute']->dimensions[0]['code']);
        self::assertSame('sku', $result['stock_value']->dimensions[0]['code']);
    }

    public function testItPreservesPresetDescriptionWhenResolvingFirstReportAttributePlaceholder(): void
    {
        $extender = new FirstReportAttributePresetExtender($this->configWithAttributes(['color']));

        $result = $extender->extend([
            'sales_by_attribute' => $this->preset('sales_by_attribute', '{first_report_attribute}'),
        ]);

        self::assertSame('A description', $result['sales_by_attribute']->description);
    }

    /**
     * @param string[] $codes
     */
    private function configWithAttributes(array $codes): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getReportAttributes')->willReturn($codes);

        return $config;
    }

    private function preset(string $code, string $dimension): Preset
    {
        return new Preset(
            $code,
            'catalog',
            'Label',
            'sales_items',
            ['units_sold'],
            [['code' => $dimension, 'granularity' => null]],
            [],
            [],
            'none',
            null,
            ['mysql'],
            description: 'A description',
        );
    }
}
