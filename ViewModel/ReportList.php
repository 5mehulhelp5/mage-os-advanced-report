<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\ViewModel;

use Magento\Backend\Model\Auth\Session;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use MageOS\AdvancedReport\Api\SavedReportRepositoryInterface;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;

/**
 * Presets and saved reports grouped by area, filtered by the admin's dataset ACLs.
 */
class ReportList implements ArgumentInterface
{
    private const array AREA_LABELS = [
        'sales' => 'Sales',
        'catalog' => 'Catalog',
        'customers' => 'Customers',
    ];
    private const array AREA_DESCRIPTIONS = [
        'sales' => 'Ready-made sales reports: revenue, orders and customer behaviour across channels, '
            . 'payments and shipping.',
        'catalog' => 'Ready-made catalog reports: product sales, categories and stock health of your catalog.',
        'customers' => 'Ready-made customer reports: spending, order frequency, delivery times and new '
            . 'registrations.',
    ];

    /**
     * @param DefinitionRegistry $registry
     * @param SavedReportRepositoryInterface $savedReportRepository
     * @param AuthorizationInterface $authorization
     * @param Session $authSession
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        private readonly DefinitionRegistry $registry,
        private readonly SavedReportRepositoryInterface $savedReportRepository,
        private readonly AuthorizationInterface $authorization,
        private readonly Session $authSession,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    /**
     * Visible areas: `code => [label, description, presets: [[label, description, url]], saved: [[label, url, shared]]]`.
     *
     * @return array<string,array{label:string,description:string,presets:array<int,array<string,mixed>>,saved:array<int,array<string,mixed>>}>
     */
    public function getAreas(): array
    {
        $areas = [];
        foreach ($this->registry->getPresets() as $preset) {
            if (!$this->isDatasetAllowed($preset->dataset)) {
                continue;
            }
            $areas[$preset->area] ??= $this->emptyArea($preset->area);
            $areas[$preset->area]['presets'][] = [
                'label' => $preset->label,
                'description' => $preset->description,
                'url' => $this->urlBuilder->getUrl('advancedreport/report/view', ['preset' => $preset->code]),
            ];
        }
        foreach ($this->savedReportRepository->getVisibleForUser($this->getAdminUserId()) as $saved) {
            $datasetCode = $saved->getDatasetCode();
            if (!$this->isDatasetAllowed($datasetCode)) {
                continue;
            }
            $area = $this->registry->getDataset($datasetCode)->area;
            $areas[$area] ??= $this->emptyArea($area);
            $areas[$area]['saved'][] = [
                'label' => $saved->getName(),
                'url' => $this->urlBuilder->getUrl('advancedreport/report/view', ['id' => $saved->getId()]),
                'shared' => $saved->isShared(),
            ];
        }
        ksort($areas);

        return $areas;
    }

    /**
     * Whether the admin may read the dataset (unknown datasets are hidden).
     *
     * @param string $datasetCode
     */
    private function isDatasetAllowed(string $datasetCode): bool
    {
        try {
            $acl = $this->registry->getDataset($datasetCode)->aclResource;
        } catch (NoSuchDefinitionException) {
            return false;
        }

        return $this->authorization->isAllowed($acl);
    }

    /**
     * Current admin user id, 0 when not logged in.
     */
    private function getAdminUserId(): int
    {
        return (int) $this->authSession->getUser()?->getId();
    }

    /**
     * @param string $area
     * @return array{label:string,description:string,presets:array<int,mixed>,saved:array<int,mixed>}
     */
    private function emptyArea(string $area): array
    {
        return [
            'label' => (string) __(self::AREA_LABELS[$area] ?? ucfirst($area)),
            'description' => isset(self::AREA_DESCRIPTIONS[$area]) ? (string) __(self::AREA_DESCRIPTIONS[$area]) : '',
            'presets' => [],
            'saved' => [],
        ];
    }
}
