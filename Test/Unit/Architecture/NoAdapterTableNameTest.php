<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `AdapterInterface::getTableName()` never adds the `db/table_prefix`; table names must be resolved through
 * `ReportConnection` (adapters of the report connection) or `ResourceConnection`. Test/Unit is not scanned:
 * its mocks stand in for the adapter.
 */
final class NoAdapterTableNameTest extends TestCase
{
    private const string MODULE_ROOT = __DIR__ . '/../../..';

    private const array SKIPPED_DIRECTORIES = ['vendor', 'node_modules', '.git', '.phpunit.cache'];

    /** Last property or variable name of the receiver that may resolve table names. */
    private const array ALLOWED_RECEIVERS = ['resourceconnection', 'reportconnection', 'resource'];

    public function testItFindsNoAdapterGetTableNameCallInModuleSourceFiles(): void
    {
        $offenders = [];
        foreach ($this->phpFiles() as $file) {
            foreach ($this->adapterCalls((string) file_get_contents($file)) as $call) {
                $offenders[] = substr($file, \strlen((string) realpath(self::MODULE_ROOT)) + 1) . ': ' . $call;
            }
        }

        self::assertSame([], $offenders, 'Resolve table names through ReportConnection or ResourceConnection');
    }

    public function testItFailsWhenASourceFileResolvesATableNameThroughADatabaseAdapter(): void
    {
        $source = <<<'PHP'
            <?php
            $a = $this->reportConnection->getTableName('sales_order');
            $b = $this->resourceConnection->getTableName('sales_order');
            $c = $resource->getTableName('sales_order');
            $d = $connection->getTableName('sales_order');
            $e = $this->getConnection()->getTableName('sales_order');
            $f = $adapter
                ->getTableName('sales_order');
            PHP;

        self::assertSame(
            ['$connection->getTableName(', 'getConnection()->getTableName(', '$adapter->getTableName('],
            $this->adapterCalls($source)
        );
    }

    /**
     * @return string[]
     */
    private function adapterCalls(string $source): array
    {
        preg_match_all('/(\$?\w+(?:\(\))?)\s*->\s*getTableName\s*\(/', $source, $matches);
        $calls = [];
        foreach ($matches[1] as $receiver) {
            if (!\in_array(strtolower(trim($receiver, '$()')), self::ALLOWED_RECEIVERS, true)) {
                $calls[] = $receiver . '->getTableName(';
            }
        }

        return $calls;
    }

    /**
     * @return string[]
     */
    private function phpFiles(): array
    {
        $root = (string) realpath(self::MODULE_ROOT);
        $unitTests = $root . '/Test/Unit';
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            static fn (SplFileInfo $entry): bool => !$entry->isDir()
                || (!\in_array($entry->getFilename(), self::SKIPPED_DIRECTORIES, true)
                    && $entry->getPathname() !== $unitTests)
        ));

        $files = [];
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
