<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use MageOS\AdvancedReport\Api\LabelResolverInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Label\DimensionLabelApplier;
use MageOS\AdvancedReport\Model\Label\LabelResolverPool;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DimensionLabelApplier::class)]
final class DimensionLabelApplierTest extends TestCase
{
    private LabelResolverInterface&MockObject $resolver;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(LabelResolverInterface::class);
    }

    public function testItReplacesDimensionValuesWithResolvedLabels(): void
    {
        $this->resolver->expects(self::once())->method('resolve')
            ->with(['IT', ''], ['kind' => 'x'])
            ->willReturn(['IT' => 'Italy', '' => '(none)']);

        $rows = $this->applier(['R' => $this->resolver])->apply($this->query(['country']), [
            $this->row(['country' => 'IT']),
            $this->row(['country' => null]),
        ]);

        self::assertSame(['country' => 'Italy'], $rows[0]['dims']);
        self::assertSame(['country' => '(none)'], $rows[1]['dims']);
    }

    public function testItKeepsRawValuesInDimsRaw(): void
    {
        $this->resolver->method('resolve')->willReturn(['IT' => 'Italy']);

        $rows = $this->applier(['R' => $this->resolver])->apply(
            $this->query(['country']),
            [$this->row(['country' => 'IT']), $this->row(['country' => null])]
        );

        self::assertSame(['country' => 'IT'], $rows[0]['dims_raw']);
        self::assertSame(['country' => null], $rows[1]['dims_raw']);
    }

    public function testItLeavesDimensionsWithoutLabelResolverUnchanged(): void
    {
        $rows = $this->applier(['R' => $this->resolver])->apply(
            $this->query(['plain']),
            [$this->row(['plain' => 'abc'])]
        );

        self::assertSame(['plain' => 'abc'], $rows[0]['dims']);
    }

    public function testItSkipsResolversMissingFromThePool(): void
    {
        $rows = $this->applier([])->apply($this->query(['country']), [$this->row(['country' => 'IT'])]);

        self::assertSame(['country' => 'IT'], $rows[0]['dims']);
    }

    public function testItDoesNotCallResolversForEmptyRows(): void
    {
        $this->resolver->expects(self::never())->method('resolve');

        self::assertSame([], $this->applier(['R' => $this->resolver])->apply($this->query(['country']), []));
    }

    public function testItCastsNullDimensionValuesToEmptyString(): void
    {
        $rows = $this->applier([])->apply($this->query(['plain']), [
            $this->row(['plain' => '5']),
            $this->row(['plain' => null]),
        ]);

        self::assertSame(['plain' => '5'], $rows[0]['dims']);
        self::assertSame(['plain' => ''], $rows[1]['dims']);
    }

    /**
     * @param array<string,LabelResolverInterface> $resolvers
     */
    private function applier(array $resolvers): DimensionLabelApplier
    {
        return new DimensionLabelApplier(new LabelResolverPool($resolvers));
    }

    /**
     * @param array<string,?string> $raw
     * @return array{dims: array<string,?string>, dims_raw: array<string,?string>, metrics: array<string,int|float|null>}
     */
    private function row(array $raw): array
    {
        return ['dims' => $raw, 'dims_raw' => $raw, 'metrics' => []];
    }

    /**
     * @param string[] $dimensions
     */
    private function query(array $dimensions): ResolvedQuery
    {
        $dataset = new Dataset(
            'sales',
            'Sales',
            'acl',
            'adminhtml',
            false,
            't',
            'main',
            null,
            [],
            [],
            [],
            [],
            [
                'country' => new Dimension('country', 'Country', 'country', [], 'R', ['kind' => 'x']),
                'plain' => new Dimension('plain', 'Plain', 'plain'),
            ]
        );

        return new ResolvedQuery(
            $dataset,
            [],
            array_map(static fn (string $code): DimensionSelection => new DimensionSelection($code), $dimensions),
            [],
            null,
            [],
            [],
            null,
            0
        );
    }
}
