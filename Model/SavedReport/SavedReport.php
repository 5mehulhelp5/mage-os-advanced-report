<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\SavedReport;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AdvancedReport\Api\Data\SavedReportInterface;
use MageOS\AdvancedReport\Model\ResourceModel\SavedReport as SavedReportResource;

class SavedReport extends AbstractModel implements SavedReportInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        private readonly Json $json,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
    ) {
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    /**
     * @SuppressWarnings("PHPMD.CamelCaseMethodName")
     */
    protected function _construct(): void
    {
        $this->_init(SavedReportResource::class);
    }

    public function getName(): string
    {
        return (string) $this->getData(self::NAME);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::NAME, $name);
    }

    public function getDescription(): ?string
    {
        $value = $this->getData(self::DESCRIPTION);

        return $value === null ? null : (string) $value;
    }

    public function setDescription(?string $description): static
    {
        return $this->setData(self::DESCRIPTION, $description);
    }

    public function getDatasetCode(): string
    {
        return (string) $this->getData(self::DATASET_CODE);
    }

    public function setDatasetCode(string $datasetCode): static
    {
        return $this->setData(self::DATASET_CODE, $datasetCode);
    }

    public function getDefinition(): array
    {
        $raw = $this->getData(self::DEFINITION);
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = $this->json->unserialize($raw);

        return \is_array($decoded) ? $decoded : [];
    }

    public function setDefinition(array $definition): static
    {
        return $this->setData(self::DEFINITION, $this->json->serialize($definition));
    }

    public function getAdminUserId(): int
    {
        return (int) $this->getData(self::ADMIN_USER_ID);
    }

    public function setAdminUserId(int $adminUserId): static
    {
        return $this->setData(self::ADMIN_USER_ID, $adminUserId);
    }

    public function isShared(): bool
    {
        return (bool) $this->getData(self::IS_SHARED);
    }

    public function setIsShared(bool $isShared): static
    {
        return $this->setData(self::IS_SHARED, $isShared ? 1 : 0);
    }

    public function getPresetCode(): ?string
    {
        $value = $this->getData(self::PRESET_CODE);

        return $value === null ? null : (string) $value;
    }

    public function setPresetCode(?string $presetCode): static
    {
        return $this->setData(self::PRESET_CODE, $presetCode);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);

        return $value === null ? null : (string) $value;
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);

        return $value === null ? null : (string) $value;
    }
}
