<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\AggregateExpressionBuilder;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionResolver;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Metric;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AggregateExpressionBuilder::class)]
final class AggregateExpressionBuilderTest extends TestCase
{
    public function testItWrapsSumAggregatesInCoalesceToZero(): void
    {
        self::assertSame('COALESCE(SUM(main.amount), 0)', $this->build(AggregateFunction::SUM));
        self::assertSame(
            'COALESCE(SUM(CASE WHEN main.flag THEN main.amount ELSE 0 END), 0)',
            $this->build(AggregateFunction::SUM, 'flag')
        );
    }

    public function testItLeavesNonSumAggregatesUntouched(): void
    {
        self::assertSame('COUNT(main.amount)', $this->build(AggregateFunction::COUNT));
        self::assertSame('AVG(main.amount)', $this->build(AggregateFunction::AVG));
        self::assertSame('MIN(main.amount)', $this->build(AggregateFunction::MIN));
        self::assertSame('MAX(main.amount)', $this->build(AggregateFunction::MAX));
        self::assertSame('COUNT(DISTINCT main.amount)', $this->build(AggregateFunction::COUNT_DISTINCT));
    }

    private function build(AggregateFunction $function, ?string $condition = null): string
    {
        $resolver = $this->createStub(FieldExpressionResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            static fn (Dataset $d, string $code): string => 'main.' . $code
        );
        $metric = new Metric('m', 'M', null, 'number', false, false, $function, 'amount', $condition);

        return (new AggregateExpressionBuilder($resolver))->build(
            new Dataset(
                code: 'sales_orders',
                label: 'Sales Orders',
                aclResource: 'MageOS_AdvancedReport::sales',
                area: 'sales',
                dateless: false,
                baseTable: 'sales_order',
                baseAlias: 'main',
                baseProvider: null,
                implicitFilters: [],
                fields: [],
                joins: [],
                metrics: [],
                dimensions: [],
            ),
            $metric,
            $this->createStub(AdapterInterface::class)
        );
    }
}
