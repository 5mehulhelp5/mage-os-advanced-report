<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Api\Data;

/**
 * A named, persisted report definition owned by an admin user.
 */
interface SavedReportInterface
{
    public const ID = 'id';
    public const NAME = 'name';
    public const DESCRIPTION = 'description';
    public const DATASET_CODE = 'dataset_code';
    public const DEFINITION = 'definition';
    public const ADMIN_USER_ID = 'admin_user_id';
    public const IS_SHARED = 'is_shared';
    public const PRESET_CODE = 'preset_code';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /**
     * @return int|string|null
     */
    public function getId();

    public function getName(): string;

    public function setName(string $name): static;

    public function getDescription(): ?string;

    public function setDescription(?string $description): static;

    public function getDatasetCode(): string;

    public function setDatasetCode(string $datasetCode): static;

    /**
     * Report request payload (the array a ReportRequest is built from).
     *
     * @return array<string, mixed>
     */
    public function getDefinition(): array;

    /**
     * @param array<string, mixed> $definition
     */
    public function setDefinition(array $definition): static;

    public function getAdminUserId(): int;

    public function setAdminUserId(int $adminUserId): static;

    public function isShared(): bool;

    public function setIsShared(bool $isShared): static;

    public function getPresetCode(): ?string;

    public function setPresetCode(?string $presetCode): static;

    public function getCreatedAt(): ?string;

    public function getUpdatedAt(): ?string;
}
