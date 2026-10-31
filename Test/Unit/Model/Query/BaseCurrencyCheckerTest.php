<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use MageOS\AdvancedReport\Model\Query\BaseCurrencyChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BaseCurrencyChecker::class)]
final class BaseCurrencyCheckerTest extends TestCase
{
    /**
     * @param int[] $storeIds
     * @param array<int,int> $websiteIdsByStoreId
     * @param array<int,string> $currencyByWebsiteId
     */
    #[DataProvider('storeScopeProvider')]
    public function testItDetectsMixedBaseCurrenciesAcrossTheWebsitesOfTheSelectedStores(
        array $storeIds,
        array $websiteIdsByStoreId,
        array $currencyByWebsiteId,
        bool $expectedMixed,
    ): void {
        $storeManager = $this->buildStoreManager($websiteIdsByStoreId);
        $scopeConfig = $this->buildScopeConfig($currencyByWebsiteId);
        $checker = new BaseCurrencyChecker($storeManager, $scopeConfig);

        self::assertSame($expectedMixed, $checker->hasMixedCurrencies($storeIds));
    }

    /**
     * @return array<string,array{0: int[], 1: array<int,int>, 2: array<int,string>, 3: bool}>
     */
    public static function storeScopeProvider(): array
    {
        return [
            'mixed currencies' => [[1, 2], [1 => 10, 2 => 20], [10 => 'EUR', 20 => 'USD'], true],
            'same currency' => [[1, 2], [1 => 10, 2 => 20], [10 => 'EUR', 20 => 'EUR'], false],
            'single store' => [[1], [1 => 10], [10 => 'EUR'], false],
        ];
    }

    public function testItListsTheDistinctSortedBaseCurrencies(): void
    {
        $checker = new BaseCurrencyChecker(
            $this->buildStoreManager([1 => 10, 2 => 20, 3 => 30]),
            $this->buildScopeConfig([10 => 'USD', 20 => 'EUR', 30 => 'USD'])
        );

        self::assertSame(['EUR', 'USD'], $checker->getCurrencyCodes([1, 2, 3]));
    }

    public function testItResolvesTheDefaultScopeBaseCurrencyForDatasetsWithoutAStoreField(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())->method('getValue')
            ->with('currency/options/base')->willReturn('GBP');
        $checker = new BaseCurrencyChecker($this->buildStoreManager([1 => 10]), $scopeConfig);

        self::assertSame('GBP', $checker->resolveCurrencyCode($this->buildDataset(false), [1]));
    }

    public function testItResolvesTheSingleBaseCurrencyInScope(): void
    {
        $checker = new BaseCurrencyChecker(
            $this->buildStoreManager([1 => 10, 2 => 20]),
            $this->buildScopeConfig([10 => 'EUR', 20 => 'EUR'])
        );

        self::assertSame('EUR', $checker->resolveCurrencyCode($this->buildDataset(true), [1, 2]));
    }

    public function testItResolvesAnEmptyCurrencyWhenTheScopeMixesCurrencies(): void
    {
        $checker = new BaseCurrencyChecker(
            $this->buildStoreManager([1 => 10, 2 => 20]),
            $this->buildScopeConfig([10 => 'EUR', 20 => 'USD'])
        );

        self::assertSame('', $checker->resolveCurrencyCode($this->buildDataset(true), [1, 2]));
    }

    public function testItUsesTheDefaultBaseCurrencyForEveryWebsiteWhenThePriceScopeIsGlobal(): void
    {
        $checker = new BaseCurrencyChecker(
            $this->buildStoreManager([1 => 10, 2 => 20]),
            $this->buildScopeConfig([10 => 'USD', 20 => 'GBP'], 0, 'EUR')
        );

        self::assertSame(['EUR'], $checker->getCurrencyCodes([1, 2]));
    }

    public function testItIgnoresALeftoverWebsiteBaseCurrencyWhenThePriceScopeIsGlobal(): void
    {
        $checker = new BaseCurrencyChecker(
            $this->buildStoreManager([1 => 10, 2 => 20]),
            $this->buildScopeConfig([10 => 'EUR', 20 => 'USD'], 0, 'EUR')
        );

        self::assertFalse($checker->hasMixedCurrencies([1, 2]));
        self::assertSame('EUR', $checker->resolveCurrencyCode($this->buildDataset(true), [1, 2]));
    }

    public function testItReadsTheWebsiteBaseCurrencyWhenThePriceScopeIsWebsite(): void
    {
        $checker = new BaseCurrencyChecker(
            $this->buildStoreManager([1 => 10]),
            $this->buildScopeConfig([10 => 'USD'], 1, 'EUR')
        );

        self::assertSame(['USD'], $checker->getCurrencyCodes([1]));
    }

    public function testItStillReportsMixedCurrenciesWhenWebsiteScopedBaseCurrenciesDiffer(): void
    {
        $checker = new BaseCurrencyChecker(
            $this->buildStoreManager([1 => 10, 2 => 20]),
            $this->buildScopeConfig([10 => 'EUR', 20 => 'USD'], 1, 'EUR')
        );

        self::assertTrue($checker->hasMixedCurrencies([1, 2]));
    }

    private function buildDataset(bool $withStoreField): Dataset
    {
        $fields = $withStoreField
            ? ['store_id' => new Field('store_id', 'Store', FieldType::ID, ['mysql' => new SourceMapping(expression: 'main.store_id')])]
            : [];

        return new Dataset(
            code: 'sales_orders',
            label: 'Orders',
            aclResource: 'MageOS_AdvancedReport::sales',
            area: 'sales',
            dateless: false,
            baseTable: 'sales_order',
            baseAlias: 'main',
            baseProvider: null,
            implicitFilters: [],
            fields: $fields,
            joins: [],
            metrics: [],
            dimensions: [],
        );
    }

    /**
     * @param array<int,int> $websiteIdsByStoreId
     */
    private function buildStoreManager(array $websiteIdsByStoreId): StoreManagerInterface
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(
            function (int $storeId) use ($websiteIdsByStoreId): StoreInterface {
                $store = $this->createStub(StoreInterface::class);
                $store->method('getWebsiteId')->willReturn($websiteIdsByStoreId[$storeId]);

                return $store;
            }
        );

        return $storeManager;
    }

    /**
     * @param array<int,string> $currencyByWebsiteId
     */
    private function buildScopeConfig(
        array $currencyByWebsiteId,
        int $priceScope = 1,
        string $defaultCurrency = 'EUR',
    ): ScopeConfigInterface {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, int|string|null $websiteId = null) use (
                $currencyByWebsiteId,
                $priceScope,
                $defaultCurrency,
            ): string|int {
                if ($path === 'catalog/price/scope') {
                    return $priceScope;
                }
                self::assertSame('currency/options/base', $path);
                if ($scopeType === ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
                    return $defaultCurrency;
                }
                self::assertSame(ScopeInterface::SCOPE_WEBSITE, $scopeType);

                return $currencyByWebsiteId[(int) $websiteId];
            }
        );

        return $scopeConfig;
    }
}
