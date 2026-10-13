<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavour;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavourDetector;
use MageOS\AdvancedReport\Model\DataSource\QueryTimeoutException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Guardrails::class)]
final class GuardrailsTest extends TestCase
{
    public function testItAddsAMaxExecutionTimeHintForMysql(): void
    {
        $guardrails = $this->buildGuardrails(ServerFlavour::Mysql, 30000, 100);

        self::assertSame(
            'SELECT /*+ MAX_EXECUTION_TIME(30000) */ a FROM t',
            $guardrails->applyTimeout('SELECT a FROM t'),
        );
    }

    public function testItPrefixesSetStatementMaxStatementTimeForMariaDb(): void
    {
        $guardrails = $this->buildGuardrails(ServerFlavour::MariaDb, 30500, 100);

        self::assertSame(
            'SET STATEMENT max_statement_time=31 FOR SELECT a FROM t',
            $guardrails->applyTimeout('SELECT a FROM t'),
        );
    }

    /**
     * @return array<string,array{0:\Throwable}>
     */
    public static function timeoutProvider(): array
    {
        $pdo = new \PDOException('Query execution was interrupted');
        $pdo->errorInfo = ['70100', 3024, 'Query execution was interrupted'];
        $maria = new \PDOException('Query execution was interrupted (max_statement_time exceeded)');
        $maria->errorInfo = ['70100', 1969, 'max_statement_time exceeded'];

        return [
            'mysql pdo' => [$pdo],
            'mariadb pdo' => [$maria],
            'wrapped' => [new \RuntimeException('wrapped', 0, $pdo)],
        ];
    }

    #[DataProvider('timeoutProvider')]
    public function testItMapsTimeoutErrorsToQueryTimeoutException(\Throwable $error): void
    {
        $mapped = $this->buildGuardrails(ServerFlavour::Mysql, 1000, 100)->mapException($error);

        self::assertInstanceOf(QueryTimeoutException::class, $mapped);
        self::assertStringContainsString('shorter', (string) $mapped->getMessage());
    }

    public function testItLeavesOtherErrorsUnmapped(): void
    {
        $error = new \RuntimeException('boom');

        self::assertSame($error, $this->buildGuardrails(ServerFlavour::Mysql, 1000, 100)->mapException($error));
    }

    public function testItCapsLimitsAtTheConfiguredMaxRowsAndReportsTruncation(): void
    {
        $guardrails = $this->buildGuardrails(ServerFlavour::Mysql, 1000, 100);
        $select = $this->createMock(Select::class);
        $select->expects(self::exactly(2))->method('limit')
            ->willReturnCallback(static fn (int $count): Select => $select)
            ->with(self::callback(static fn (int $count): bool => \in_array($count, [100, 50], true)));

        $guardrails->limitRows($select, 500);
        $guardrails->limitRows($select, 50);
        self::assertSame(100, $guardrails->getMaxRows());
        self::assertNull($guardrails->getTruncationWarning(100));
        self::assertNotNull($guardrails->getTruncationWarning(101));
        self::assertTrue($guardrails->isTruncated(101));
        self::assertFalse($guardrails->isTruncated(100));
    }

    private function buildGuardrails(ServerFlavour $flavour, int $timeoutMs, int $maxRows): Guardrails
    {
        $detector = $this->createStub(ServerFlavourDetector::class);
        $detector->method('detect')->willReturn($flavour);
        $config = $this->createStub(Config::class);
        $config->method('getQueryTimeoutMs')->willReturn($timeoutMs);
        $config->method('getMaxRows')->willReturn($maxRows);

        return new Guardrails($detector, $config);
    }
}
