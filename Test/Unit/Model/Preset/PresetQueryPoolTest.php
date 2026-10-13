<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Preset;

use MageOS\AdvancedReport\Api\PresetQueryInterface;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Preset\LowStockCoverageQuery;
use MageOS\AdvancedReport\Model\Preset\PresetQueryPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresetQueryPool::class)]
final class PresetQueryPoolTest extends TestCase
{
    public function testItResolvesPresetQueryClassesFromThePoolAndThrowsForUnknownClasses(): void
    {
        $lowStockCoverageQuery = $this->createStub(PresetQueryInterface::class);
        $pool = new PresetQueryPool([
            LowStockCoverageQuery::class => $lowStockCoverageQuery,
        ]);

        self::assertSame(
            $lowStockCoverageQuery,
            $pool->get(LowStockCoverageQuery::class)
        );

        $this->expectException(NoSuchDefinitionException::class);

        $pool->get('MageOS\AdvancedReport\Model\Preset\UnknownQuery');
    }
}
