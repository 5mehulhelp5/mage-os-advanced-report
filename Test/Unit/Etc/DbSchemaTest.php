<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

final class DbSchemaTest extends TestCase
{
    public function testItDeclaresTheSavedTableAndTheThreeNativeIndexesInDbSchema(): void
    {
        $etc = \dirname(__DIR__, 3) . '/etc';
        $xml = simplexml_load_string((string) file_get_contents($etc . '/db_schema.xml'));
        self::assertNotFalse($xml);
        $whitelist = json_decode((string) file_get_contents($etc . '/db_schema_whitelist.json'), true);

        $indexes = [];
        $tables = [];
        foreach ($xml->table as $table) {
            $name = (string) $table['name'];
            $tables[] = $name;
            foreach ($table->index as $index) {
                $indexes[$name][] = (string) $index['referenceId'];
            }
        }

        self::assertContains('mageos_advancedreport_saved', $tables);
        self::assertSame(['SALES_ORDER_CUSTOMER_ID_CREATED_AT', 'SALES_ORDER_CUSTOMER_EMAIL_CREATED_AT'], $indexes['sales_order']);
        self::assertSame(['SALES_ORDER_ITEM_PRODUCT_ID', 'SALES_ORDER_ITEM_PRODUCT_ID_ORDER_ID'], $indexes['sales_order_item']);
        self::assertSame(['QUOTE_UPDATED_AT'], $indexes['quote']);
        foreach ($indexes as $table => $referenceIds) {
            foreach ($referenceIds as $referenceId) {
                self::assertArrayHasKey($referenceId, $whitelist[$table]['index']);
            }
        }
    }

    public function testItDocumentsEveryModuleIndexInTheIndexesDocument(): void
    {
        $root = \dirname(__DIR__, 3);
        $xml = simplexml_load_string((string) file_get_contents($root . '/etc/db_schema.xml'));
        self::assertNotFalse($xml);
        $documentation = (string) file_get_contents($root . '/docs/indexes.md');

        $referenceIds = [];
        foreach ($xml->xpath('//index') ?: [] as $index) {
            $referenceIds[] = (string) $index['referenceId'];
        }

        self::assertNotEmpty($referenceIds);
        foreach ($referenceIds as $referenceId) {
            self::assertStringContainsString('### ' . $referenceId . "\n", $documentation, $referenceId);
        }
    }
}
