<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Definition\Formula;

use MageOS\AdvancedReport\Model\Definition\Formula\Formula;
use MageOS\AdvancedReport\Model\Definition\Formula\FormulaException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Formula::class)]
final class FormulaTest extends TestCase
{
    public function testItEvaluatesAdditionAndSubtractionLeftToRight(): void
    {
        $formula = Formula::fromString('net_sales + shipping - adjustments');

        $result = $formula->evaluate([
            'net_sales' => 100.0,
            'shipping' => 10.0,
            'adjustments' => 5.0,
        ]);

        self::assertSame(105.0, $result);
    }

    public function testItRespectsMultiplicationAndDivisionPrecedenceAndParentheses(): void
    {
        $formula = Formula::fromString('(net_sales + shipping) / 2 - discount * 3');

        $result = $formula->evaluate([
            'net_sales' => 10.0,
            'shipping' => 4.0,
            'discount' => 2.0,
        ]);

        self::assertSame(1.0, $result);
    }

    public function testItSupportsUnaryMinusAndDecimalLiterals(): void
    {
        $formula = Formula::fromString('-net_sales + 1.5 * -2.25');

        $result = $formula->evaluate(['net_sales' => 10.0]);

        self::assertSame(-13.375, $result);
    }

    public function testItListsReferencedMetricCodesWithoutDuplicates(): void
    {
        $formula = Formula::fromString('net_sales + net_sales - shipping / net_sales');

        self::assertSame(['net_sales', 'shipping'], $formula->getReferences());
    }

    public function testItReturnsNullWhenDividingByZero(): void
    {
        $formula = Formula::fromString('gross_margin / net_sales');

        $result = $formula->evaluate(['gross_margin' => 10.0, 'net_sales' => 0.0]);

        self::assertNull($result);
    }

    public function testItReturnsNullWhenAReferencedValueIsNull(): void
    {
        $formula = Formula::fromString('net_sales + shipping');

        $result = $formula->evaluate(['net_sales' => 10.0, 'shipping' => null]);

        self::assertNull($result);
    }

    #[DataProvider('invalidFormulaProvider')]
    public function testItThrowsFormulaExceptionOnUnbalancedParenthesesOrUnknownCharacters(string $expression): void
    {
        $this->expectException(FormulaException::class);

        Formula::fromString($expression);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFormulaProvider(): array
    {
        return [
            'missing closing parenthesis' => ['(net_sales + shipping'],
            'unexpected closing parenthesis' => ['net_sales + shipping)'],
            'unknown character' => ['net_sales $ shipping'],
        ];
    }

    public function testItRendersTheFormulaWithALeafRendererAndNullifGuardedDivisions(): void
    {
        $formula = Formula::fromString('gross_margin / net_sales + 2 * -adjustments');

        $rendered = $formula->render(static fn (string $metricCode): string => \sprintf('SUM(%s)', $metricCode));

        self::assertSame(
            '((SUM(gross_margin) / NULLIF(SUM(net_sales), 0)) + (2 * (-SUM(adjustments))))',
            $rendered
        );
    }
}
