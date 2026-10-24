<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\DataSource\ReportConnectionUnavailableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportConnection::class)]
final class ReportConnectionTest extends TestCase
{
    /**
     * @return array<string,array{0:\Throwable}>
     */
    public static function connectErrorProvider(): array
    {
        return [
            'zend adapter exception' => [new \Zend_Db_Adapter_Exception('SQLSTATE[HY000] [2002] Connection refused')],
            'pdo exception' => [self::pdoException('SQLSTATE[HY000] [1045] Access denied', 1045)],
        ];
    }

    public function testItReturnsTheConnectionNamedByTheConfiguration(): void
    {
        $adapter = $this->createStub(AdapterInterface::class);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::once())->method('getConnectionByName')->with('reports')->willReturn($adapter);

        self::assertSame($adapter, (new ReportConnection($resource, $this->config('reports')))->get());
    }

    public function testItThrowsAConnectionUnavailableExceptionWhenTheConnectionIsNotDefined(): void
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willThrowException(new \DomainException('Connection "x" is not defined'));

        $this->expectException(ReportConnectionUnavailableException::class);
        $this->expectExceptionMessage('The report database connection "x" is not available.');

        (new ReportConnection($resource, $this->config('x')))->get();
    }

    #[DataProvider('connectErrorProvider')]
    public function testItMapsAConnectionRefusedErrorToAConnectionUnavailableException(\Throwable $error): void
    {
        $adapter = $this->createStub(Mysql::class);
        $adapter->method('getConnection')->willThrowException($error);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($adapter);

        $this->expectException(ReportConnectionUnavailableException::class);
        $this->expectExceptionMessage('The report database connection "reports" is not available.');

        (new ReportConnection($resource, $this->config('reports')))->get();
    }

    public function testItDoesNotMapNonConnectionErrors(): void
    {
        $adapter = $this->createStub(Mysql::class);
        $adapter->method('getConnection')->willThrowException(new \Zend_Db_Adapter_Exception('Syntax error'));
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($adapter);

        $this->expectException(\Zend_Db_Adapter_Exception::class);

        (new ReportConnection($resource, $this->config('reports')))->get();
    }

    public function testItConnectsEagerlyAndMemoizesTheAdapterPerConnectionName(): void
    {
        $first = $this->createMock(Mysql::class);
        $first->expects(self::once())->method('getConnection');
        $second = $this->createMock(Mysql::class);
        $second->expects(self::once())->method('getConnection');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::exactly(2))->method('getConnectionByName')
            ->willReturnMap([['a', $first], ['b', $second]]);
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturnOnConsecutiveCalls('a', 'a', 'b', 'a');

        $provider = new ReportConnection($resource, $config);

        self::assertSame($first, $provider->get());
        self::assertSame($first, $provider->get());
        self::assertSame($second, $provider->get());
        self::assertSame($first, $provider->get());
    }

    private function config(string $name): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn($name);

        return $config;
    }

    private static function pdoException(string $message, int $code): \PDOException
    {
        $e = new \PDOException($message);
        $e->errorInfo = ['HY000', $code, $message];

        return $e;
    }
}
