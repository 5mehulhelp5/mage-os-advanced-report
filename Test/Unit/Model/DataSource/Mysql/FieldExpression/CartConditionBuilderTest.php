<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql\FieldExpression;

use DateTimeImmutable;
use DateTimeZone;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression\CartAbandonedExpression;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression\CartConditionBuilder;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression\CartConvertedExpression;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression\CartCustomerEmailExpression;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression\CartRecentExpression;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression\CartStateExpression;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionProviderInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Query\ClockInterface;
use MageOS\AdvancedReport\Test\Unit\Helper\SelectTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CartConditionBuilder::class)]
#[CoversClass(CartConvertedExpression::class)]
#[CoversClass(CartAbandonedExpression::class)]
#[CoversClass(CartRecentExpression::class)]
#[CoversClass(CartStateExpression::class)]
#[CoversClass(CartCustomerEmailExpression::class)]
final class CartConditionBuilderTest extends TestCase
{
    use SelectTestTrait;

    private const string CONVERTED = '(EXISTS (SELECT 1 FROM sales_order AS so WHERE so.quote_id = main.entity_id))';
    private const string EMAIL = "COALESCE(NULLIF(TRIM(main.customer_email), ''), (SELECT NULLIF(TRIM(qa.email), '')"
        . " FROM quote_address AS qa WHERE qa.quote_id = main.entity_id AND qa.address_type = 'billing' LIMIT 1))";

    public function testItBuildsAnExistsSubSelectOnSalesOrderQuoteIdForTheConvertedExpression(): void
    {
        self::assertSame(
            '(EXISTS (SELECT 1 FROM sales_order AS so WHERE so.quote_id = main.entity_id))',
            $this->sql(CartConvertedExpression::class)
        );
    }

    public function testItRendersTheCartConvertedConditionAgainstThePrefixedSalesOrderTable(): void
    {
        self::assertSame(
            '(EXISTS (SELECT 1 FROM pre_sales_order AS so WHERE so.quote_id = main.entity_id))',
            $this->sql(CartConvertedExpression::class, 'main', 'pre_')
        );
        self::assertStringContainsString(
            'FROM pre_sales_order AS so',
            $this->sql(CartStateExpression::class, 'main', 'pre_')
        );
    }

    public function testItUsesTheAbandonedAfterHoursSettingToComputeTheAbandonedThresholdFromTheClockInUtc(): void
    {
        // now = 2026-03-16 01:00:00 UTC, 48h earlier
        self::assertStringContainsString(
            "main.updated_at < '2026-03-14 01:00:00'",
            $this->sql(CartAbandonedExpression::class)
        );
    }

    public function testItRequiresAnActiveNonEmptyCartWithAnEmailAndNoOrderForTheAbandonedExpression(): void
    {
        self::assertSame(
            '(main.is_active = 1 AND main.items_count > 0 AND ' . self::EMAIL . ' IS NOT NULL'
            . " AND main.updated_at < '2026-03-14 01:00:00' AND NOT " . self::CONVERTED . ')',
            $this->sql(CartAbandonedExpression::class)
        );
    }

    public function testItLowercasesTheQuoteEmailWithBillingFallbackForTheCustomerEmailExpression(): void
    {
        self::assertSame('LOWER(' . self::EMAIL . ')', $this->sql(CartCustomerEmailExpression::class));
    }

    public function testItFallsBackToTheBillingAddressEmailThroughThePrefixedQuoteAddressTable(): void
    {
        $sql = $this->sql(CartAbandonedExpression::class, 'main', 'pre_');

        self::assertStringContainsString(
            "COALESCE(NULLIF(TRIM(main.customer_email), ''), (SELECT NULLIF(TRIM(qa.email), '')"
            . " FROM pre_quote_address AS qa WHERE qa.quote_id = main.entity_id AND qa.address_type = 'billing'"
            . ' LIMIT 1))',
            $sql
        );
    }

    public function testItExcludesQuotesWithAnOrderFromTheRecentExpression(): void
    {
        // 6h before now
        self::assertSame(
            "(main.is_active = 1 AND main.items_count > 0 AND main.updated_at >= '2026-03-15 19:00:00'"
            . ' AND NOT ' . self::CONVERTED . ')',
            $this->sql(CartRecentExpression::class)
        );
    }

    public function testItMapsQuotesWithAnOrderToConvertedBeforeAnyActiveOrAbandonedState(): void
    {
        $sql = $this->sql(CartStateExpression::class);

        self::assertStringStartsWith('(CASE WHEN ' . self::CONVERTED . " THEN 'converted' WHEN main.is_active = 1", $sql);
        self::assertStringEndsWith("WHEN main.is_active = 1 THEN 'unrecoverable' ELSE 'inactive' END)", $sql);
    }

    public function testItMapsIdleActiveCartsToAbandonedOnlyWhenTheyHaveAnEmail(): void
    {
        $sql = $this->sql(CartStateExpression::class);

        self::assertStringContainsString(
            "WHEN main.is_active = 1 AND main.updated_at >= '2026-03-14 01:00:00' THEN 'active'",
            $sql
        );
        self::assertStringContainsString(
            'WHEN main.is_active = 1 AND ' . self::EMAIL . " IS NOT NULL THEN 'abandoned'",
            $sql
        );
    }

    public function testItPrefixesQuoteColumnsWithTheConfiguredAlias(): void
    {
        foreach ($this->providerClasses() as $class) {
            $sql = $this->sql($class, 'quote');

            self::assertStringContainsString('quote.', $sql, $class);
            self::assertStringNotContainsString('main.', $sql, $class);
        }
    }

    public function testItWrapsEveryBooleanCartExpressionInParentheses(): void
    {
        foreach ([CartConvertedExpression::class, CartAbandonedExpression::class, CartRecentExpression::class] as $class) {
            $sql = $this->sql($class);

            self::assertStringStartsWith('(', $sql, $class);
            self::assertStringEndsWith(')', $sql, $class);
        }
        $state = $this->sql(CartStateExpression::class);
        self::assertStringStartsWith('(CASE', $state);
        self::assertStringEndsWith('END)', $state);
    }

    public function testItNeverUsesDatabaseClockFunctions(): void
    {
        foreach ($this->providerClasses() as $class) {
            self::assertDoesNotMatchRegularExpression(
                '/NOW\(|CURRENT_TIMESTAMP|UTC_TIMESTAMP/i',
                $this->sql($class)
            );
        }
    }

    /**
     * @return array<class-string<FieldExpressionProviderInterface>>
     */
    private function providerClasses(): array
    {
        return [
            CartConvertedExpression::class,
            CartAbandonedExpression::class,
            CartRecentExpression::class,
            CartStateExpression::class,
        ];
    }

    /**
     * @param class-string<FieldExpressionProviderInterface> $class
     */
    private function sql(string $class, string $alias = 'main', string $prefix = ''): string
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-03-16 01:00:00', new DateTimeZone('UTC')));
        $config = $this->createStub(Config::class);
        $config->method('getAbandonedAfterHours')->willReturn(48);
        $config->method('getRecentCartHours')->willReturn(6);

        $dataset = new Dataset('d', 'D', 'acl', 'adminhtml', false, 'quote', $alias, null, [], [], [], [], []);

        return $this->normalizeSql(
            (new $class(new CartConditionBuilder($clock, $config, $this->createReportConnection($prefix)), $alias))
                ->getExpression($dataset, $this->createSqlAdapter())
        );
    }
}
