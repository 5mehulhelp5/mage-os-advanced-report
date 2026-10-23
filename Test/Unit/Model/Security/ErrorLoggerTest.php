<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Security;

use LogicException;
use Magento\Framework\App\State;
use MageOS\AdvancedReport\Model\Security\ErrorLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ErrorLoggerTest extends TestCase
{
    public function testItStripsTheQueryPartFromADatabaseExceptionMessageInProductionMode(): void
    {
        $message = $this->logProduction(
            new RuntimeException('SQLSTATE[42S22]: Column not found, query was: x = \'john@example.com\'')
        )['message'];

        self::assertSame('RuntimeException: SQLSTATE[42S22]: Column not found, ', $message);
        self::assertStringNotContainsString('john', $message);
    }

    public function testItStripsAnEmbeddedSelectStatementInProductionMode(): void
    {
        $message = $this->logProduction(new RuntimeException('Failed: SELECT email FROM customer'))['message'];

        self::assertSame('RuntimeException: Failed: ', $message);
    }

    public function testItStripsALowercaseSelectStatementInProductionMode(): void
    {
        $message = $this->logProduction(new RuntimeException('Failed: select email from customer'))['message'];

        self::assertSame('RuntimeException: Failed: ', $message);
    }

    public function testItDoesNotPassTheExceptionObjectInProductionMode(): void
    {
        $context = $this->logProduction(new RuntimeException('boom'))['context'];

        self::assertArrayNotHasKey('exception', $context);
        self::assertSame('ctx', $context['context']);
    }

    public function testItCapsTheLoggedMessageLength(): void
    {
        $message = $this->logProduction(new RuntimeException(str_repeat('a', 900)))['message'];

        self::assertSame(\strlen('RuntimeException: ') + 500, \strlen($message));
    }

    public function testItLogsTheFullExceptionInDeveloperMode(): void
    {
        $e = new RuntimeException('boom SELECT x');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('boom SELECT x', ['exception' => $e]);

        (new ErrorLogger($logger, $this->state(State::MODE_DEVELOPER)))->log($e, 'ctx');
    }

    public function testItListsPreviousExceptionClassesWithoutTheirMessages(): void
    {
        $e = new RuntimeException('outer', 0, new LogicException('secret value', 0, new \Exception('deep')));

        $context = $this->logProduction($e)['context'];

        self::assertSame([LogicException::class, \Exception::class], $context['previous']);
    }

    /**
     * @return array{message: string, context: array<string,mixed>}
     */
    private function logProduction(\Throwable $e): array
    {
        $captured = ['message' => '', 'context' => []];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            /** @param array<string,mixed> $context */
            function (string $message, array $context) use (&$captured): void {
                $captured = ['message' => $message, 'context' => $context];
            }
        );

        (new ErrorLogger($logger, $this->state(State::MODE_PRODUCTION)))->log($e, 'ctx');

        return $captured;
    }

    private function state(string $mode): State
    {
        $state = $this->createStub(State::class);
        $state->method('getMode')->willReturn($mode);

        return $state;
    }
}
