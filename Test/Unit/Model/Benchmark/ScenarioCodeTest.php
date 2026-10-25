<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Benchmark;

use MageOS\AdvancedReport\Model\Benchmark\ScenarioCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScenarioCode::class)]
final class ScenarioCodeTest extends TestCase
{
    public function testItFormatsTheStoreSegment(): void
    {
        self::assertSame('all_stores', ScenarioCode::storeSegment([]));
        self::assertSame('store_1', ScenarioCode::storeSegment([1]));
        self::assertSame('stores_1,2', ScenarioCode::storeSegment([1, 2]));
    }
}
