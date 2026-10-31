<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Security;

use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\Preset;

/**
 * Decides whether the current admin may read a dataset: the module dataset ACL and,
 * when mapped and declared, the default core ACL of the dataset's entity.
 *
 * A dataset without a core mapping is gated by its module ACL only.
 */
class DatasetAccess
{
    /** @var array<string,true>|null */
    private ?array $declaredIds = null;

    /**
     * @param AuthorizationInterface $authorization
     * @param ProviderInterface $aclResourceProvider
     * @param DefinitionRegistry $definitionRegistry
     * @param array<string,string|string[]> $coreResources dataset code => core ACL id or list of ids (all required)
     * @param array<string,string[]> $presetDatasets preset code => extra dataset codes the preset reads (all required)
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly ProviderInterface $aclResourceProvider,
        private readonly DefinitionRegistry $definitionRegistry,
        private readonly array $coreResources = [],
        private readonly array $presetDatasets = [],
    ) {
    }

    /**
     * First core ACL resource gating the dataset, or null when unmapped or not declared.
     *
     * @param string $datasetCode
     */
    public function getCoreResource(string $datasetCode): ?string
    {
        return $this->getCoreResources($datasetCode)[0] ?? null;
    }

    /**
     * Declared core ACL resources gating the dataset; every one is required.
     *
     * @param string $datasetCode
     * @return string[]
     */
    public function getCoreResources(string $datasetCode): array
    {
        $declared = $this->getDeclaredIds();
        $resources = array_map(strval(...), (array) ($this->coreResources[$datasetCode] ?? []));

        return array_values(array_filter($resources, static fn (string $id): bool => isset($declared[$id])));
    }

    /**
     * Whether the current admin may read the dataset.
     *
     * @param Dataset $dataset
     */
    public function isAllowed(Dataset $dataset): bool
    {
        return $this->firstDenied($dataset) === null;
    }

    /**
     * Whether the current admin holds the field's ACL resource (always true for an ungated field).
     *
     * @param Field $field
     */
    public function isFieldAllowed(Field $field): bool
    {
        return $field->acl === null || $this->authorization->isAllowed($field->acl);
    }

    /**
     * Fails when the current admin may not read the dataset.
     *
     * @param Dataset $dataset
     * @throws AuthorizationException
     */
    public function assertAllowed(Dataset $dataset): void
    {
        $denied = $this->firstDenied($dataset);
        if ($denied !== null) {
            throw new AuthorizationException(
                new Phrase('You are not allowed to query the resource "%1".', [$denied])
            );
        }
    }

    /**
     * Whether the current admin may read every extra dataset the preset depends on
     * (e.g. sales history behind the stock anti-join presets).
     *
     * @param Preset $preset
     */
    public function isPresetAllowed(Preset $preset): bool
    {
        foreach ($this->presetRequiredDatasets($preset) as $dataset) {
            if (!$this->isAllowed($dataset)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fails when the current admin may not read an extra dataset the preset depends on.
     *
     * @param Preset $preset
     * @throws AuthorizationException
     */
    public function assertPresetAllowed(Preset $preset): void
    {
        foreach ($this->presetRequiredDatasets($preset) as $dataset) {
            $this->assertAllowed($dataset);
        }
    }

    /**
     * Sorted distinct module dataset ACLs, declared core resources and field ACLs.
     *
     * @return string[]
     */
    public function getResources(): array
    {
        $resources = [];
        foreach ($this->definitionRegistry->getDatasets() as $dataset) {
            $resources[$dataset->aclResource] = true;
            foreach ($dataset->fields as $field) {
                if ($field->acl !== null) {
                    $resources[$field->acl] = true;
                }
            }
        }
        foreach (array_keys($this->coreResources) as $code) {
            foreach ($this->getCoreResources((string) $code) as $core) {
                $resources[$core] = true;
            }
        }
        $ids = array_map(strval(...), array_keys($resources));
        sort($ids);

        return $ids;
    }

    /**
     * @return Dataset[]
     */
    private function presetRequiredDatasets(Preset $preset): array
    {
        return array_map(
            $this->definitionRegistry->getDataset(...),
            array_map(strval(...), $this->presetDatasets[$preset->code] ?? [])
        );
    }

    private function firstDenied(Dataset $dataset): ?string
    {
        $candidates = [$dataset->aclResource, ...$this->getCoreResources($dataset->code)];
        foreach ($candidates as $resource) {
            if (!$this->authorization->isAllowed($resource)) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * @return array<string,true>
     */
    private function getDeclaredIds(): array
    {
        return $this->declaredIds ??= $this->collect($this->aclResourceProvider->getAclResources());
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @param array<string,true> $ids
     * @return array<string,true>
     */
    private function collect(array $nodes, array $ids = []): array
    {
        foreach ($nodes as $node) {
            $ids[(string) $node['id']] = true;
            $ids = $this->collect($node['children'] ?? [], $ids);
        }

        return $ids;
    }
}
