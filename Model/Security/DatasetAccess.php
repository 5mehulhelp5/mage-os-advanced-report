<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Security;

use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;

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
     * @param array<string,string> $coreResources dataset code => core ACL id
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly ProviderInterface $aclResourceProvider,
        private readonly DefinitionRegistry $definitionRegistry,
        private readonly array $coreResources = [],
    ) {
    }

    /**
     * Core ACL resource gating the dataset, or null when unmapped or not declared.
     *
     * @param string $datasetCode
     */
    public function getCoreResource(string $datasetCode): ?string
    {
        $resource = $this->coreResources[$datasetCode] ?? null;

        return $resource !== null && isset($this->getDeclaredIds()[$resource]) ? $resource : null;
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
     * Sorted distinct module dataset ACLs plus declared core resources.
     *
     * @return string[]
     */
    public function getResources(): array
    {
        $resources = [];
        foreach ($this->definitionRegistry->getDatasets() as $dataset) {
            $resources[$dataset->aclResource] = true;
        }
        foreach (array_keys($this->coreResources) as $code) {
            $core = $this->getCoreResource((string) $code);
            if ($core !== null) {
                $resources[$core] = true;
            }
        }
        $ids = array_map(strval(...), array_keys($resources));
        sort($ids);

        return $ids;
    }

    private function firstDenied(Dataset $dataset): ?string
    {
        $candidates = [$dataset->aclResource, $this->getCoreResource($dataset->code)];
        foreach ($candidates as $resource) {
            if ($resource !== null && !$this->authorization->isAllowed($resource)) {
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
