<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\SavedReport;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AdvancedReport\Model\ResourceModel\SavedReport as SavedReportResource;
use MageOS\AdvancedReport\Model\SavedReport\SavedReport;
use PHPUnit\Framework\TestCase;

final class SavedReportTest extends TestCase
{
    public function testItSerializesAndUnserializesTheDefinitionAsJson(): void
    {
        $report = new SavedReport($this->createStub(Context::class), $this->createStub(Registry::class), new Json(), $this->createStub(SavedReportResource::class));
        $definition = ['dataset' => 'sales_orders', 'metrics' => ['revenue'], 'limit' => 10];

        $report->setDefinition($definition);

        self::assertSame('{"dataset":"sales_orders","metrics":["revenue"],"limit":10}', $report->getData('definition'));
        self::assertSame($definition, $report->getDefinition());

        $loaded = new SavedReport(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            new Json(),
            $this->createStub(SavedReportResource::class),
            null,
            ['definition' => '{"dataset":"x"}'],
        );
        self::assertSame(['dataset' => 'x'], $loaded->getDefinition());
    }
}
