<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Exploration;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Api\SavedReportRepositoryInterface;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use MageOS\AdvancedReport\Model\Query\PresetRequestBuilder;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;

/**
 * Resolves what the exploration page opens with: a preset, a saved report visible to the
 * admin, or an empty exploration on the first dataset the admin may read.
 */
class InitialStateResolver
{
    /**
     * @param DefinitionRegistry $registry
     * @param SavedReportRepositoryInterface $savedReportRepository
     * @param PresetRequestBuilder $presetRequestBuilder
     * @param ReportRequestFactory $requestFactory
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        private readonly DefinitionRegistry $registry,
        private readonly SavedReportRepositoryInterface $savedReportRepository,
        private readonly PresetRequestBuilder $presetRequestBuilder,
        private readonly ReportRequestFactory $requestFactory,
        private readonly AuthorizationInterface $authorization,
    ) {
    }

    /**
     * Initial request array plus saved report meta (null unless a saved report is opened).
     *
     * @param string|null $presetCode
     * @param int|null $savedId
     * @param int $adminUserId
     * @throws NoSuchEntityException Unknown, invisible or forbidden target.
     * @return array{request:array<string,mixed>,saved:array<string,mixed>|null}
     */
    public function resolve(?string $presetCode, ?int $savedId, int $adminUserId): array
    {
        if ($savedId !== null) {
            return $this->fromSavedReport($savedId, $adminUserId);
        }
        if ($presetCode !== null && $presetCode !== '') {
            return $this->fromPreset($presetCode);
        }

        return ['request' => $this->emptyRequest(), 'saved' => null];
    }

    /**
     * @throws NoSuchEntityException
     * @return array{request:array<string,mixed>,saved:array<string,mixed>}
     */
    private function fromSavedReport(int $id, int $adminUserId): array
    {
        $saved = $this->savedReportRepository->getById($id);
        $isOwner = $saved->getAdminUserId() === $adminUserId;
        if (!$isOwner && !$saved->isShared()) {
            throw $this->notFound();
        }
        $this->assertDatasetAllowed($saved->getDatasetCode());

        return [
            'request' => $saved->getDefinition(),
            'saved' => [
                'id' => (int) $saved->getId(),
                'name' => $saved->getName(),
                'is_shared' => $saved->isShared(),
                'is_owner' => $isOwner,
            ],
        ];
    }

    /**
     * @throws NoSuchEntityException
     * @return array{request:array<string,mixed>,saved:null}
     */
    private function fromPreset(string $code): array
    {
        try {
            $preset = $this->registry->getPreset($code);
            $this->assertDatasetAllowed($preset->dataset);

            return [
                'request' => $this->requestFactory->toArray($this->presetRequestBuilder->build($preset)),
                'saved' => null,
            ];
        } catch (NoSuchDefinitionException|InvalidRequestException) {
            throw $this->notFound();
        }
    }

    /**
     * @throws NoSuchEntityException
     * @return array<string,mixed>
     */
    private function emptyRequest(): array
    {
        foreach ($this->registry->getDatasets() as $dataset) {
            if ($this->authorization->isAllowed($dataset->aclResource)) {
                return [
                    'dataset' => $dataset->code,
                    'preset' => null,
                    'metrics' => [],
                    'dimensions' => [],
                    'filters' => [],
                    'dateRange' => ['type' => 'last_30_days'],
                    'compareTo' => 'none',
                    'sort' => [],
                ];
            }
        }

        throw $this->notFound();
    }

    /**
     * @throws NoSuchEntityException
     */
    private function assertDatasetAllowed(string $datasetCode): void
    {
        try {
            $allowed = $this->authorization->isAllowed($this->registry->getDataset($datasetCode)->aclResource);
        } catch (NoSuchDefinitionException) {
            $allowed = false;
        }
        if (!$allowed) {
            throw $this->notFound();
        }
    }

    private function notFound(): NoSuchEntityException
    {
        return new NoSuchEntityException(new Phrase('The requested report does not exist.'));
    }
}
