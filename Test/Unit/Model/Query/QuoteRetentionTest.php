<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateTimeImmutable;
use DateTimeZone;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Query\ClockInterface;
use MageOS\AdvancedReport\Model\Query\QuoteRetention;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuoteRetention::class)]
final class QuoteRetentionTest extends TestCase
{
    /**
     * @param int[] $storeIds
     */
    #[DataProvider('smallestLifetimeProvider')]
    public function testItUsesTheSmallestStoreScopedQuoteLifetimeAcrossTheStoresInScope(
        array $storeIds,
        int $expectedDays,
    ): void {
        $window = $this->buildRetention(['admin' => '90', 'main' => '30', 'second' => '10', 'third' => '60'])
            ->window($this->buildDataset('carts'), $storeIds);

        self::assertNotNull($window);
        self::assertSame($expectedDays, $window->days);
        self::assertSame(
            (new DateTimeImmutable('2026-03-15'))->modify(\sprintf('-%d days', $expectedDays))->format('Y-m-d'),
            $window->cutoff->format('Y-m-d'),
        );
    }

    /**
     * @return array<string,array{int[],int}>
     */
    public static function smallestLifetimeProvider(): array
    {
        return [
            'all stores when none is selected' => [[], 10],
            'selected stores only' => [[1, 3], 30],
            'single store' => [[3], 60],
        ];
    }

    #[DataProvider('emptyOrZeroProvider')]
    public function testItTreatsAnEmptyOrZeroQuoteLifetimeAsDeletingEveryQuoteNotUpdatedToday(mixed $value): void
    {
        $window = $this->buildRetention(['main' => $value])->window($this->buildDataset('carts'), []);

        self::assertNotNull($window);
        self::assertSame(0, $window->days);
        self::assertSame('2026-03-15', $window->cutoff->format('Y-m-d'));
    }

    /**
     * @return array<string,array{mixed}>
     */
    public static function emptyOrZeroProvider(): array
    {
        return ['null' => [null], 'empty string' => [''], 'zero' => ['0']];
    }

    public function testItTreatsANegativeQuoteLifetimeAsDeletingEveryQuote(): void
    {
        $window = $this->buildRetention(['main' => '-5'])->window($this->buildDataset('carts'), []);

        self::assertNotNull($window);
        self::assertSame('2026-03-16', $window->cutoff->format('Y-m-d'));
    }

    public function testItHasNoWindowForDatasetsOutsideTheCartsArea(): void
    {
        self::assertNull($this->buildRetention(['main' => '30'])->window($this->buildDataset('sales'), []));
    }

    /**
     * @param array<string,mixed> $lifetimeByStoreCode
     */
    private function buildRetention(array $lifetimeByStoreCode): QuoteRetention
    {
        $codes = ['admin' => 0, 'main' => 1, 'second' => 2, 'third' => 3];
        $stores = [];
        foreach ($codes as $code => $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn((string) $id);
            $store->method('getCode')->willReturn($code);
            $stores[] = $store;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scope, string $code) use ($lifetimeByStoreCode): mixed {
                self::assertSame('checkout/cart/delete_quote_after', $path);
                self::assertSame(ScopeInterface::SCOPE_STORE, $scope);

                return $lifetimeByStoreCode[$code] ?? null;
            }
        );

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-03-15 12:00:00', new DateTimeZone('UTC')));

        return new QuoteRetention($scopeConfig, $storeManager, $clock);
    }

    private function buildDataset(string $area): Dataset
    {
        return new Dataset(
            code: 'carts',
            label: 'Carts',
            aclResource: 'MageOS_AdvancedReport::carts',
            area: $area,
            dateless: false,
            baseTable: 'quote',
            baseAlias: 'main',
            baseProvider: null,
            implicitFilters: [],
            fields: [],
            joins: [],
            metrics: [],
            dimensions: [],
        );
    }
}
