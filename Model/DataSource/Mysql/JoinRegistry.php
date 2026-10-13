<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Join;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;

/**
 * Computes the minimal, dependency-ordered set of joins needed by a set of dataset fields.
 */
class JoinRegistry
{
    private const string SOURCE = 'mysql';

    /**
     * Joins used by the given fields (including transitive `requires`), each once, required joins first.
     *
     * @param Dataset $dataset
     * @param string[] $fieldCodes
     * @throws NoSuchDefinitionException When a field or a referenced join is not declared.
     * @return Join[]
     */
    public function resolve(Dataset $dataset, array $fieldCodes): array
    {
        $resolved = [];
        foreach ($fieldCodes as $fieldCode) {
            $mapping = $dataset->getField($fieldCode)->sourceMappings[self::SOURCE] ?? null;
            foreach ($mapping->joins ?? [] as $joinCode) {
                $this->collect($dataset, $joinCode, $resolved, []);
            }
        }

        return array_values($resolved);
    }

    /**
     * Depth-first collection so that required joins precede the joins depending on them.
     *
     * @param Dataset $dataset
     * @param string $joinCode
     * @param array<string,Join> $resolved
     * @param string[] $path Join codes being resolved, guards against circular requirements.
     * @throws NoSuchDefinitionException
     */
    private function collect(Dataset $dataset, string $joinCode, array &$resolved, array $path): void
    {
        if (isset($resolved[$joinCode]) || \in_array($joinCode, $path, true)) {
            return;
        }

        $join = $dataset->getJoin($joinCode);
        $path[] = $joinCode;
        foreach ($join->requires as $required) {
            $this->collect($dataset, $required, $resolved, $path);
        }
        $resolved[$joinCode] = $join;
    }
}
