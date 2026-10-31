<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Schema;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\DataSource\Mysql\SelectBuilder;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavour;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Preset\PresetQueryPool;
use MageOS\AdvancedReport\Model\Query\PresetRequestBuilder;
use MageOS\AdvancedReport\Model\Query\QueryPlanner;
use MageOS\AdvancedReport\Test\Integration\Helper\ServerFlavourAssertions;
use PHPUnit\Framework\TestCase;

/**
 * Every index the module adds must be a candidate (`possible_keys`) of the statement it serves; `key` is not
 * asserted because the optimizer prefers a full scan on the tiny integration data set.
 */
#[DbIsolation(true)]
#[AppIsolation(true)]
#[AppArea('adminhtml')]
final class IndexUsageTest extends TestCase
{
    private const string MODULE_ROOT = __DIR__ . '/../../..';

    /** Indexes added by the module in `etc/db_schema.xml`, with the preset whose statement serves them. */
    private const array NEW_INDEXES = [
        'SALES_ORDER_ITEM_PRODUCT_ID_ORDER_ID' => 'dead_stock',
        'QUOTE_UPDATED_AT' => 'abandoned_carts_trend',
    ];

    /** Core indexes the statements rely on, as table and columns. */
    private const array CORE_INDEXES = ['SALES_ORDER_QUOTE_ID' => ['sales_order', ['quote_id']]];

    public function testItListsTheFirstOrderIndexAmongThePossibleKeysOfTheNewCustomerLookup(): void
    {
        $this->assertIndexListed(
            'SALES_ORDER_CUSTOMER_ID_CREATED_AT',
            $this->possibleKeys($this->presetSelect('new_vs_returning'))
        );
    }

    public function testItListsTheCustomerEmailIndexAmongThePossibleKeysOfTheGuestFirstOrderLookup(): void
    {
        $this->assertIndexListed(
            'SALES_ORDER_CUSTOMER_EMAIL_CREATED_AT',
            $this->possibleKeys($this->presetSelect('new_vs_returning'))
        );
    }

    public function testItListsTheProductIndexAmongThePossibleKeysOfTheDeadStockAntiJoin(): void
    {
        $this->assertIndexListed(
            'SALES_ORDER_ITEM_PRODUCT_ID',
            $this->possibleKeys($this->presetSelect('dead_stock'))
        );
    }

    public function testItAssertsIndexUsageOnlyForIndexesTheDetectedFlavourIsExpectedToChoose(): void
    {
        // MySQL lists both product indexes for the anti-join; MariaDB may list only the covering composite one.
        $expected = ServerFlavourAssertions::current() === ServerFlavour::MariaDb
            ? ['SALES_ORDER_ITEM_PRODUCT_ID_ORDER_ID']
            : ['SALES_ORDER_ITEM_PRODUCT_ID', 'SALES_ORDER_ITEM_PRODUCT_ID_ORDER_ID'];

        $possible = $this->possibleKeys($this->presetSelect('dead_stock'));
        foreach ($expected as $index) {
            $this->assertIndexListed($index, $possible);
        }
    }

    public function testItListsEveryNewIndexAmongThePossibleKeysOfTheStatementItServes(): void
    {
        foreach (self::NEW_INDEXES as $index => $preset) {
            $this->assertIndexListed($index, $this->possibleKeys($this->presetSelect($preset)));
        }
    }

    public function testItOffersQuoteUpdatedAtForTheCartsDatasetDateRangeStatement(): void
    {
        $this->assertIndexListed('QUOTE_UPDATED_AT', $this->possibleKeys($this->presetSelect('recent_carts')));
    }

    public function testItOffersQuoteUpdatedAtForTheCartItemsDatasetDateRangeStatement(): void
    {
        $this->assertIndexListed('QUOTE_UPDATED_AT', $this->possibleKeys($this->presetSelect('recent_cart_items')));
    }

    public function testItOffersSalesOrderQuoteIdForTheConvertedCartSubSelect(): void
    {
        $this->assertIndexListed(
            'SALES_ORDER_QUOTE_ID',
            $this->possibleKeys($this->presetSelect('abandoned_carts_trend'))
        );
    }

    public function testItDocumentsARationaleForEveryModuleIndexAndTheRemovableIndexes(): void
    {
        $documentation = (string) file_get_contents(self::MODULE_ROOT . '/docs/indexes.md');
        $schema = simplexml_load_string((string) file_get_contents(self::MODULE_ROOT . '/etc/db_schema.xml'));

        foreach ($schema->xpath('//table[@resource="sales" or @resource="checkout"]/index') as $index) {
            $this->assertStringContainsString((string) $index['referenceId'], $documentation);
        }
        $this->assertStringContainsString('## Removable', $documentation);
        $removable = substr($documentation, (int) strpos($documentation, '## Removable'));
        $this->assertStringContainsString('SALES_ORDER_ITEM_PRODUCT_ID', $removable);
    }

    /**
     * On a prefixed install the declarative schema names indexes from the prefixed table, so the referenceId
     * is accepted as is or through the adapter's naming of the prefixed table and the declared columns.
     *
     * @param string[] $possibleKeys
     */
    private function assertIndexListed(string $referenceId, array $possibleKeys): void
    {
        $accepted = [$referenceId];
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        if (isset(self::CORE_INDEXES[$referenceId])) {
            [$table, $columns] = self::CORE_INDEXES[$referenceId];
            $accepted[] = $resource->getConnection()->getIndexName($resource->getTableName($table), $columns);
        }
        $schema = simplexml_load_string((string) file_get_contents(self::MODULE_ROOT . '/etc/db_schema.xml'));
        foreach ($schema->xpath('//table/index[@referenceId="' . $referenceId . '"]') ?: [] as $index) {
            $columns = array_map(static fn ($column): string => (string) $column['name'], $index->xpath('column'));
            $table = (string) $index->xpath('..')[0]['name'];
            $accepted[] = $resource->getConnection()->getIndexName($resource->getTableName($table), $columns);
        }

        $this->assertNotEmpty(array_intersect($accepted, $possibleKeys), $referenceId);
    }

    private function presetSelect(string $code): Select
    {
        $objectManager = Bootstrap::getObjectManager();
        $preset = $objectManager->get(DefinitionRegistry::class)->getPreset($code);
        $request = $objectManager->get(PresetRequestBuilder::class)->build($preset, [
            'dateRange' => ['type' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'],
        ]);
        $main = $objectManager->get(QueryPlanner::class)->plan($request, false)['main'];

        if ($preset->presetQuery !== null) {
            return $objectManager->get(PresetQueryPool::class)->get($preset->presetQuery)->getSelect($main);
        }

        return $objectManager->get(SelectBuilder::class)->buildPage($main);
    }

    /**
     * @return string[]
     */
    private function possibleKeys(Select $select): array
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $keys = [];
        foreach ($connection->fetchAll('EXPLAIN ' . $select->assemble(), $select->getBind()) as $row) {
            $keys = [...$keys, ...explode(',', (string) ($row['possible_keys'] ?? ''))];
        }

        return array_values(array_filter($keys));
    }
}
