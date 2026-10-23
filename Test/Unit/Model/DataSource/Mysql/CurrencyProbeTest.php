<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\CurrencyProbe;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\SelectBuilder;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use MageOS\AdvancedReport\Model\Query\ReportScope;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CurrencyProbe::class)]
final class CurrencyProbeTest extends TestCase
{
    public function testItReturnsTheDistinctCurrenciesSorted(): void
    {
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('fetchCol')->willReturn(['USD', null, '', 'EUR']);
        $query = $this->query(true);

        $probe = $this->probe($adapter, $query);

        self::assertSame(['EUR', 'USD'], $probe->getBaseCurrencies($query));
    }

    public function testItReturnsNoCurrenciesWithoutQueryingForDatasetsWithoutTheBaseCurrencyField(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::never())->method('fetchCol');
        $builder = $this->createMock(SelectBuilder::class);
        $builder->expects(self::never())->method('buildCurrencyProbe');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($adapter);

        $probe = new CurrencyProbe($resource, $this->createStub(Config::class), $builder, $this->createStub(Guardrails::class));

        self::assertSame([], $probe->getBaseCurrencies($this->query(false)));
    }

    public function testItAppliesTheQueryTimeoutAndMapsDriverErrorsWhenProbing(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::once())->method('fetchCol')
            ->with('SQL WITH TIMEOUT', [])
            ->willThrowException(new RuntimeException('SQLSTATE secret'));
        $query = $this->query(true);
        $select = $this->createStub(Select::class);
        $select->method('__toString')->willReturn('SQL');
        $select->method('getBind')->willReturn([]);
        $guardrails = $this->createMock(Guardrails::class);
        $guardrails->expects(self::once())->method('applyTimeout')->with('SQL')->willReturn('SQL WITH TIMEOUT');
        $guardrails->expects(self::once())->method('mapException')
            ->willReturn(new RuntimeException('mapped'));

        $probe = $this->probe($adapter, $query, $guardrails, $select);

        $this->expectExceptionMessage('mapped');
        $probe->getBaseCurrencies($query);
    }

    private function probe(
        AdapterInterface $adapter,
        ResolvedQuery $query,
        ?Guardrails $guardrails = null,
        ?Select $select = null,
    ): CurrencyProbe {
        $select ??= $this->createStub(Select::class);
        $builder = $this->createStub(SelectBuilder::class);
        $builder->method('buildCurrencyProbe')->willReturn($select);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($adapter);
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn('reports');
        if ($guardrails === null) {
            $guardrails = $this->createStub(Guardrails::class);
            $guardrails->method('applyTimeout')->willReturnArgument(0);
        }

        return new CurrencyProbe($resource, $config, $builder, $guardrails);
    }

    private function query(bool $withCurrency): ResolvedQuery
    {
        $fields = [
            'created_at' => new Field('created_at', 'c', FieldType::DATE, ['mysql' => new SourceMapping('main.created_at')]),
        ];
        if ($withCurrency) {
            $fields['base_currency_code'] = new Field(
                'base_currency_code',
                'b',
                FieldType::STRING,
                ['mysql' => new SourceMapping('main.base_currency_code')]
            );
        }
        $dataset = new Dataset('d', 'D', 'acl', 'sales', false, 'sales_order', 'main', null, [], $fields, [], [], []);

        return new ResolvedQuery($dataset, [], [], [], null, [], [], null, 0, new ReportScope(null, 'UTC', 0, ['UTC']));
    }
}
