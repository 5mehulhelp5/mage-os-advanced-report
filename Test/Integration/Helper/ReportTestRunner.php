<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Helper;

use Magento\Backend\Model\Auth;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\TestFramework\Bootstrap as TestBootstrap;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Api\ReportRunnerInterface;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Query\PresetRequestBuilder;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;

/**
 * Runs reports through the real {@see ReportRunnerInterface} for integration tests (cache bypassed).
 *
 * Usage: call {@see login()} in `setUp()` (with `#[AppArea('adminhtml')]`) and {@see logout()}
 * in `tearDown()`, then `run()` / `runPreset()` and read the outcome with `rows()` (keyed on
 * raw dimension values merged with metrics) and `totals()`.
 */
final readonly class ReportTestRunner
{
    private ReportRunnerInterface $runner;
    private ReportRequestFactory $requestFactory;
    private PresetRequestBuilder $presetRequestBuilder;
    private DefinitionRegistry $registry;

    public function __construct()
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->runner = $objectManager->get(ReportRunnerInterface::class);
        $this->requestFactory = $objectManager->get(ReportRequestFactory::class);
        $this->presetRequestBuilder = $objectManager->get(PresetRequestBuilder::class);
        $this->registry = $objectManager->get(DefinitionRegistry::class);
    }

    /**
     * Logs in the integration admin user so the ACL check of the runner passes.
     */
    public function login(): void
    {
        $this->loginAs(TestBootstrap::ADMIN_NAME);
    }

    /**
     * Logs in a restricted admin and resets the runtime ACL: the ACL object is built once per process and
     * does not know roles created by fixtures afterwards. Call inside the test method, not in `setUp()`.
     */
    public function loginAs(string $username): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->get(Auth::class)->login($username, TestBootstrap::ADMIN_PASSWORD);
        $objectManager->get(AclBuilder::class)->resetRuntimeAcl();
    }

    public function logout(): void
    {
        Bootstrap::getObjectManager()->get(Auth::class)->logout();
    }

    /**
     * @param string[] $metrics
     * @param list<array{code: string, granularity: ?string}> $dimensions
     * @param list<array{field: string, operator: string, value: mixed}> $filters
     * @param int[] $storeIds
     */
    public function run(
        string $dataset,
        array $metrics,
        array $dimensions = [],
        array $filters = [],
        string $from = '2026-01-01',
        string $to = '2026-01-31',
        array $storeIds = []
    ): ReportResultInterface {
        return $this->runner->run($this->requestFactory->fromArray([
            'dataset' => $dataset,
            'metrics' => $metrics,
            'dimensions' => $dimensions,
            'filters' => $filters,
            'dateRange' => ['type' => 'custom', 'from' => $from, 'to' => $to],
            'storeIds' => $storeIds,
            'bypassCache' => true,
        ]));
    }

    /**
     * Runs a custom-range request with sort, cache and extra raw request keys (e.g. `offset`, `preset`).
     *
     * @param string[] $metrics
     * @param list<array{code: string, granularity: ?string}> $dimensions
     * @param list<array{by: string, direction: string}> $sort
     * @param array<string,mixed> $extra
     */
    public function runWith(
        string $dataset,
        array $metrics,
        array $dimensions = [],
        array $sort = [],
        bool $bypassCache = true,
        array $extra = []
    ): ReportResultInterface {
        return $this->runner->run($this->requestFactory->fromArray(array_merge([
            'dataset' => $dataset,
            'metrics' => $metrics,
            'dimensions' => $dimensions,
            'filters' => [],
            'sort' => $sort,
            'dateRange' => ['type' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'],
            'storeIds' => [],
            'bypassCache' => $bypassCache,
        ], $extra)));
    }

    /**
     * @param array<string,mixed> $overrides Extra request keys (e.g. `storeIds`, `filters`).
     */
    public function runPreset(string $presetCode, string $from, string $to, array $overrides = []): ReportResultInterface
    {
        $request = $this->presetRequestBuilder->build(
            $this->registry->getPreset($presetCode),
            array_replace(
                ['dateRange' => ['type' => 'custom', 'from' => $from, 'to' => $to], 'bypassCache' => true],
                $overrides
            )
        );

        return $this->runner->run($request);
    }

    /**
     * Flattens every row to its raw dimension values plus metrics.
     *
     * @return list<array<string,mixed>>
     */
    public function rows(ReportResultInterface $result): array
    {
        return array_map(
            static fn (array $row): array => array_merge($row['dims_raw'] ?? [], $row['metrics'] ?? []),
            array_values($result->getRows())
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function totals(ReportResultInterface $result): array
    {
        return $result->getTotals()['metrics'];
    }
}
