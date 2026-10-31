<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Helper;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Platform\Quote;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\ColumnsRenderer;
use Magento\Framework\DB\Select\DistinctRenderer;
use Magento\Framework\DB\Select\ForUpdateRenderer;
use Magento\Framework\DB\Select\FromRenderer;
use Magento\Framework\DB\Select\GroupRenderer;
use Magento\Framework\DB\Select\HavingRenderer;
use Magento\Framework\DB\Select\LimitRenderer;
use Magento\Framework\DB\Select\OrderRenderer;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\Select\UnionRenderer;
use Magento\Framework\DB\Select\WhereRenderer;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use PHPUnit\Framework\MockObject\Stub;

/**
 * Builds real {@see Select} objects on a mocked MySQL adapter so tests can assert the
 * rendered SQL (`(string) $select`) without a database.
 *
 * Requires the using class to extend {@see \PHPUnit\Framework\TestCase}.
 */
trait SelectTestTrait
{
    /**
     * Mocked adapter whose quoting methods behave like the real ones (single quotes, backticks).
     *
     * @return Stub&Mysql
     */
    protected function createSqlAdapter(): Stub
    {
        $adapter = $this->createStub(Mysql::class);
        $adapter->method('quote')->willReturnCallback(
            fn (mixed $value): string => $this->quoteValue($value)
        );
        $adapter->method('quoteInto')->willReturnCallback(
            function (string $text, mixed $value): string {
                if (\is_array($value) && $value === []) {
                    return str_replace('?', 'NULL', $text);
                }

                return str_replace('?', $this->quoteValue($value), $text);
            }
        );
        $adapter->method('quoteIdentifier')->willReturnCallback(
            static fn (mixed $ident): string => '`' . implode('`.`', (array) $ident) . '`'
        );
        $adapter->method('getTableName')->willReturnCallback(
            static fn (string $name): string => $name
        );
        $adapter->method('getQuoteIdentifierSymbol')->willReturn('`');
        $adapter->method('supportStraightJoin')->willReturn(false);
        $adapter->method('select')->willReturnCallback(
            fn (): Select => $this->createSelect($adapter)
        );

        return $adapter;
    }

    /**
     * Report connection resolving table names like the resource connection (global `db/table_prefix`).
     */
    protected function createReportConnection(string $tablePrefix = ''): ReportConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getTableName')->willReturnCallback(
            static fn (string $name): string => $tablePrefix . $name
        );
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn('default');

        return new ReportConnection($resource, $config);
    }

    /**
     * Real select with the 10 core part renderers, bound to the given adapter.
     *
     * @param Mysql $adapter
     */
    protected function createSelect(Mysql $adapter): Select
    {
        $quote = new Quote();
        $renderers = [];
        $sort = 100;
        // Renderer key => select part it renders (the limit renderer reads the "limitcount" part).
        foreach ([
            'distinct' => new DistinctRenderer(),
            'columns' => new ColumnsRenderer($quote),
            'union' => new UnionRenderer(),
            'from' => new FromRenderer($quote),
            'where' => new WhereRenderer(),
            'group' => new GroupRenderer($quote),
            'having' => new HavingRenderer(),
            'order' => new OrderRenderer($quote),
            'limitcount' => new LimitRenderer(),
            'forupdate' => new ForUpdateRenderer(),
        ] as $part => $renderer) {
            $renderers[$part] = ['renderer' => $renderer, 'sort' => $sort, 'part' => $part];
            $sort += 100;
        }

        // SelectRenderer's docblock says RendererInterface[], but the constructor expects renderer config arrays.
        // @phpstan-ignore argument.type
        return new Select($adapter, new SelectRenderer($renderers));
    }

    /**
     * Collapse whitespace and newlines so SQL can be compared on one line.
     *
     * @param Select|string $sql
     */
    protected function normalizeSql(Select|string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $sql));
    }

    private function quoteValue(mixed $value): string
    {
        if (\is_array($value)) {
            return implode(', ', array_map($this->quoteValue(...), $value));
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return "'" . addslashes((string) $value) . "'";
    }
}
