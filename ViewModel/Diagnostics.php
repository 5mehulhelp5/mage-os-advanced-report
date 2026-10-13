<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\ViewModel;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavourDetector;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneStrategy;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\SourceResolver;
use Throwable;

/**
 * Diagnostics panel data, visible only to admins allowed to see the module configuration.
 */
class Diagnostics implements ArgumentInterface
{
    private const string ACL_CONFIG = 'MageOS_AdvancedReport::config';

    /**
     * @param DefinitionRegistry $registry
     * @param SourceResolver $sourceResolver
     * @param ReportRequestFactory $requestFactory
     * @param TimezoneStrategy $timezoneStrategy
     * @param ServerFlavourDetector $flavourDetector
     * @param Config $config
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        private readonly DefinitionRegistry $registry,
        private readonly SourceResolver $sourceResolver,
        private readonly ReportRequestFactory $requestFactory,
        private readonly TimezoneStrategy $timezoneStrategy,
        private readonly ServerFlavourDetector $flavourDetector,
        private readonly Config $config,
        private readonly AuthorizationInterface $authorization,
    ) {
    }

    /**
     * Whether the admin may see the panel.
     */
    public function isVisible(): bool
    {
        return $this->authorization->isAllowed(self::ACL_CONFIG);
    }

    /**
     * Diagnostic rows as `label => value`; empty for admins without permission.
     *
     * @return array<string,string>
     */
    public function getRows(): array
    {
        if (!$this->isVisible()) {
            return [];
        }

        $rows = [];
        foreach ($this->registry->getDatasets() as $dataset) {
            $rows[(string) __('Active source: %1', $dataset->code)] = $this->resolveSourceCode($dataset->code);
        }
        $rows[(string) __('Timezone strategy')] = $this->timezoneStrategy->getName();
        $rows[(string) __('Server flavour')] = $this->flavourDetector->detect()->value;
        $rows[(string) __('Max rows')] = (string) $this->config->getMaxRows();
        $rows[(string) __('Query timeout (ms)')] = (string) $this->config->getQueryTimeoutMs();

        return $rows;
    }

    private function resolveSourceCode(string $datasetCode): string
    {
        try {
            $dataset = $this->registry->getDataset($datasetCode);
            $metric = current(array_filter(
                $dataset->metrics,
                static fn (Metric $metric): bool => !$metric->isVirtual()
            ));
            if ($metric === false) {
                return (string) __('none');
            }
            $request = $this->requestFactory->fromArray(['dataset' => $datasetCode, 'metrics' => [$metric->code]]);

            return $this->sourceResolver->resolve($dataset, $request)->getCode();
        } catch (Throwable) {
            return (string) __('none');
        }
    }
}
