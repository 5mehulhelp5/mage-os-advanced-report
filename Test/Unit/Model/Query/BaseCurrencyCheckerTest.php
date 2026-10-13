<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
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

    public function testItReturnsTheBaseCurrencyOfTheFirstWebsiteInScope(): void
    {
        $storeManager = $this->buildStoreManager([5 => 10, 6 => 20]);
        $scopeConfig = $this->buildScopeConfig([10 => 'EUR', 20 => 'USD']);
        $checker = new BaseCurrencyChecker($storeManager, $scopeConfig);

        self::assertSame('EUR', $checker->getCurrencyCode([5, 6]));
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
    private function buildScopeConfig(array $currencyByWebsiteId): ScopeConfigInterface
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scopeType, int $websiteId) use ($currencyByWebsiteId): string {
                self::assertSame('currency/options/base', $path);
                self::assertSame(ScopeInterface::SCOPE_WEBSITE, $scopeType);

                return $currencyByWebsiteId[$websiteId];
            }
        );

        return $scopeConfig;
    }
}
