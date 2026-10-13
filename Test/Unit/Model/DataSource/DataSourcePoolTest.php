<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource;

use MageOS\AdvancedReport\Api\DataSourceInterface;
use MageOS\AdvancedReport\Model\DataSource\DataSourcePool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DataSourcePool::class)]
final class DataSourcePoolTest extends TestCase
{
    public function testItOrdersRegisteredSourcesBySortOrderAndIndexesThemByCode(): void
    {
        $mysql = $this->buildSource('mysql');
        $opensearch = $this->buildSource('opensearch');
        $pool = new DataSourcePool([
            'opensearch_entry' => ['source' => $opensearch, 'sortOrder' => 20],
            'mysql_entry' => ['source' => $mysql, 'sortOrder' => 10],
        ]);

        self::assertSame(['mysql' => $mysql, 'opensearch' => $opensearch], $pool->getAll());
    }

    public function testItReturnsNullForAnUnregisteredSourceCode(): void
    {
        $pool = new DataSourcePool([]);

        self::assertNull($pool->get('mysql'));
    }

    private function buildSource(string $code): DataSourceInterface
    {
        $source = $this->createStub(DataSourceInterface::class);
        $source->method('getCode')->willReturn($code);

        return $source;
    }
}
