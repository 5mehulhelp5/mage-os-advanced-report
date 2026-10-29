<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Schema;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Declaration\Schema\Diff\SchemaDiff;
use Magento\Framework\Setup\Declaration\Schema\Dto\TableElementInterface;
use Magento\Framework\Setup\Declaration\Schema\SchemaConfigInterface;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Test\Integration\Helper\ServerFlavourAssertions;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Installation and upgrade must be safe and repeatable: module indexes exist as declared and a further
 * `setup:upgrade` would have nothing to change for module-owned schema elements.
 */
#[AppIsolation(true)]
final class InstallationTest extends TestCase
{
    private const string MODULE_ROOT = __DIR__ . '/../../..';

    public function testItCreatesEveryModuleIndexWithTheDeclaredColumnsInOrder(): void
    {
        $connection = $this->resource()->getConnection();
        foreach ($this->declaredIndexes() as $table => $indexes) {
            $physicalTable = $this->resource()->getTableName($table);
            $actual = [];
            foreach ($connection->fetchAll('SHOW INDEX FROM ' . $connection->quoteIdentifier($physicalTable)) as $row) {
                $actual[$row['Key_name']]['columns'][(int) $row['Seq_in_index']] = $row['Column_name'];
                $actual[$row['Key_name']]['unique'] = (int) $row['Non_unique'] === 0;
            }
            foreach ($indexes as $referenceId => $columns) {
                $name = $connection->getIndexName($physicalTable, $columns);
                $this->assertArrayHasKey($name, $actual, $table . '.' . $referenceId);
                $this->assertSame($columns, array_values($actual[$name]['columns']), $referenceId);
                $this->assertFalse($actual[$name]['unique'], $referenceId . ' must not be unique');
            }
        }
    }

    public function testItReportsTheDeclarativeSchemaAsUpToDateAfterInstallation(): void
    {
        $this->assertSame([], $this->moduleSchemaChanges(), 'Pending declarative schema changes for the module');
    }

    public function testItComputesTheSchemaDiffIdenticallyOnMysqlAndMariadbInstalls(): void
    {
        // No flavour branch on purpose: the module schema (declared columns and index names) must diff to nothing
        // on both servers, so a flavour-only difference (type display width, index naming) fails here.
        $this->assertSame(
            [],
            $this->moduleSchemaChanges(),
            'Schema diff of the module is not empty on ' . ServerFlavourAssertions::current()->value
        );
    }

    public function testItHasAWhitelistEntryForEveryModuleIndex(): void
    {
        $whitelist = json_decode(
            (string) file_get_contents(self::MODULE_ROOT . '/etc/db_schema_whitelist.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($this->declaredIndexes() as $table => $indexes) {
            foreach (array_keys($indexes) as $referenceId) {
                $this->assertArrayHasKey($referenceId, $whitelist[$table]['index'] ?? [], $table);
            }
        }
    }

    /**
     * @return string[]
     */
    private function moduleSchemaChanges(): array
    {
        $objectManager = Bootstrap::getObjectManager();
        $schemaConfig = $objectManager->get(SchemaConfigInterface::class);
        $diff = $objectManager->get(SchemaDiff::class)
            ->diff($schemaConfig->getDeclarationConfig(), $schemaConfig->getDbConfig());

        $moduleTables = array_diff(array_keys($this->declaredTables()), ['sales_order', 'sales_order_item', 'quote']);
        $moduleIndexes = array_merge(...array_values(array_map('array_keys', $this->declaredIndexes())));
        $moduleChanges = [];
        foreach ($diff->getAll() ?? [] as $operations) {
            foreach ($operations as $operation => $changes) {
                foreach ($changes as $change) {
                    $element = $change->getNew() ?: $change->getOld();
                    $name = $element->getName();
                    $table = $element instanceof TableElementInterface ? $element->getTable()->getName() : $name;
                    if (\in_array($table, $moduleTables, true) || \in_array($name, $moduleIndexes, true)) {
                        $moduleChanges[] = $operation . ': ' . $table . '.' . $name;
                    }
                }
            }
        }

        return $moduleChanges;
    }

    /**
     * @return array<string, SimpleXMLElement>
     */
    private function declaredTables(): array
    {
        $schema = simplexml_load_string((string) file_get_contents(self::MODULE_ROOT . '/etc/db_schema.xml'));
        $this->assertNotFalse($schema);
        $tables = [];
        foreach ($schema->table as $table) {
            $tables[(string) $table['name']] = $table;
        }

        return $tables;
    }

    /**
     * @return array<string, array<string, string[]>> table => referenceId => ordered columns
     */
    private function declaredIndexes(): array
    {
        $indexes = [];
        foreach ($this->declaredTables() as $name => $table) {
            foreach ($table->index as $index) {
                $columns = [];
                foreach ($index->column as $column) {
                    $columns[] = (string) $column['name'];
                }
                $indexes[$name][(string) $index['referenceId']] = $columns;
            }
        }

        return $indexes;
    }

    private function resource(): ResourceConnection
    {
        return Bootstrap::getObjectManager()->get(ResourceConnection::class);
    }
}
