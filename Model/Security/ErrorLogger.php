<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Security;

use Magento\Framework\App\State;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logs report failures without leaking SQL or inlined filter values outside developer mode.
 */
class ErrorLogger
{
    private const int MAX_MESSAGE_LENGTH = 500;

    private const array SQL_MARKERS = ['query was:', 'SELECT ', 'SET STATEMENT'];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly State $state,
    ) {
    }

    public function log(Throwable $e, string $context): void
    {
        if ($this->state->getMode() === State::MODE_DEVELOPER) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);

            return;
        }

        $this->logger->error(
            \sprintf('%s: %s', $e::class, $this->sanitize($e->getMessage())),
            ['context' => $context, 'previous' => $this->previousClasses($e)]
        );
    }

    private function sanitize(string $message): string
    {
        foreach (self::SQL_MARKERS as $marker) {
            $position = stripos($message, $marker);
            if ($position !== false) {
                $message = substr($message, 0, $position);
            }
        }

        return substr($message, 0, self::MAX_MESSAGE_LENGTH);
    }

    /**
     * @return string[]
     */
    private function previousClasses(Throwable $e): array
    {
        $classes = [];
        for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            $classes[] = $previous::class;
        }

        return $classes;
    }
}
