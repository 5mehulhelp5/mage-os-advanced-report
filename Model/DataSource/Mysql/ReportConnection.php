<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\ReportConnectionUnavailableException;

/**
 * Single place resolving the report database connection (no fallback to the default connection).
 *
 * Depends only on the resource connection and the config (never on Guardrails: that would create a DI cycle).
 */
class ReportConnection
{
    private const array CONNECT_ERROR_CODES = [1045, 2002, 2003, 2005, 2006, 2013];

    /**
     * @var array<string,AdapterInterface>
     */
    private array $adapters = [];

    /**
     * @param ResourceConnection $resourceConnection
     * @param Config $config
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config,
    ) {
    }

    /**
     * Connected adapter for the configured report connection name.
     *
     * @throws ReportConnectionUnavailableException
     */
    public function get(): AdapterInterface
    {
        $name = $this->config->getConnectionName();
        if (isset($this->adapters[$name])) {
            return $this->adapters[$name];
        }

        try {
            $adapter = $this->resourceConnection->getConnectionByName($name);
            if (method_exists($adapter, 'getConnection')) {
                $adapter->getConnection();
            }
        } catch (\DomainException $error) {
            throw $this->unavailable($name, $error);
        } catch (\Zend_Db_Adapter_Exception | \PDOException $error) {
            if (!$this->isConnectError($error)) {
                throw $error;
            }

            throw $this->unavailable($name, $error);
        }

        return $this->adapters[$name] = $adapter;
    }

    private function unavailable(string $name, \Throwable $previous): ReportConnectionUnavailableException
    {
        return new ReportConnectionUnavailableException(
            __('The report database connection "%1" is not available.', $name),
            $previous instanceof \Exception ? $previous : null,
        );
    }

    private function isConnectError(\Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $info = $current instanceof \PDOException ? $current->errorInfo : null;
            if (\is_array($info) && isset($info[1]) && \in_array((int) $info[1], self::CONNECT_ERROR_CODES, true)) {
                return true;
            }
            if (preg_match('/SQLSTATE\[(08\w{3}|HY000)\].*?\[(\d+)\]/', $current->getMessage(), $matches) === 1) {
                return $matches[1] !== 'HY000' || \in_array((int) $matches[2], self::CONNECT_ERROR_CODES, true);
            }
        }

        return false;
    }
}
