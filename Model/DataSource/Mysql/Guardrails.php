<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\DB\Select;
use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\QueryTimeoutException;

/**
 * Per-query execution time limit and row cap for report queries.
 */
class Guardrails
{
    private const int MYSQL_TIMEOUT_ERROR = 3024;
    private const int MARIADB_TIMEOUT_ERROR = 1969;

    /**
     * @param ServerFlavourDetector $flavourDetector
     * @param Config $config
     */
    public function __construct(
        private readonly ServerFlavourDetector $flavourDetector,
        private readonly Config $config,
    ) {
    }

    /**
     * Adds the flavour-specific execution time limit to a SELECT statement.
     *
     * @param string $sql
     */
    public function applyTimeout(string $sql): string
    {
        $timeoutMs = $this->config->getQueryTimeoutMs();

        if ($this->flavourDetector->detect() === ServerFlavour::MariaDb) {
            return \sprintf('SET STATEMENT max_statement_time=%d FOR %s', (int) ceil($timeoutMs / 1000), $sql);
        }

        return (string) preg_replace(
            '/^(\s*SELECT)\b/i',
            \sprintf('$1 /*+ MAX_EXECUTION_TIME(%d) */', $timeoutMs),
            $sql,
            1,
        );
    }

    /**
     * Converts driver timeout errors into a {@see QueryTimeoutException}; other errors are returned unchanged.
     *
     * @param \Throwable $error
     */
    public function mapException(\Throwable $error): \Throwable
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($this->isTimeout($current)) {
                return new QueryTimeoutException(
                    __('The report query took too long. Try a shorter date range or fewer dimensions.'),
                    $error instanceof \Exception ? $error : null,
                );
            }
        }

        return $error;
    }

    /**
     * Applies the smaller of the requested limit and the configured maximum to the select.
     *
     * @param Select $select
     * @param int $requested
     */
    public function limitRows(Select $select, int $requested): Select
    {
        $max = $this->getMaxRows();
        $limit = $requested > 0 ? min($requested, $max) : $max;

        return $select->limit($limit);
    }

    /**
     * Configured hard cap on returned rows.
     */
    public function getMaxRows(): int
    {
        return $this->config->getMaxRows();
    }

    /**
     * Whether the total row count exceeds the cap.
     *
     * @param int $totalRowCount
     */
    public function isTruncated(int $totalRowCount): bool
    {
        return $totalRowCount > $this->getMaxRows();
    }

    /**
     * Warning text when the result was truncated, null otherwise.
     *
     * @param int $totalRowCount
     */
    public function getTruncationWarning(int $totalRowCount): ?Phrase
    {
        if (!$this->isTruncated($totalRowCount)) {
            return null;
        }

        return __(
            'Results were truncated to the first %1 rows. Narrow the date range or add filters.',
            $this->getMaxRows(),
        );
    }

    /**
     * @param \Throwable $error
     */
    private function isTimeout(\Throwable $error): bool
    {
        $errorInfo = $error instanceof \PDOException ? ($error->errorInfo[1] ?? null) : null;
        $codes = [$errorInfo, $error->getCode()];

        return array_intersect($codes, [self::MYSQL_TIMEOUT_ERROR, self::MARIADB_TIMEOUT_ERROR]) !== [];
    }
}
