<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use InvalidArgumentException;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Resolves a dataset field to its MySQL expression, via the raw expression or a named provider.
 */
class FieldExpressionResolver
{
    private const string SOURCE = 'mysql';

    /**
     * @param array<string,FieldExpressionProviderInterface> $providers Keyed by provider name.
     */
    public function __construct(private readonly array $providers = [])
    {
    }

    /**
     * MySQL expression of the given field.
     *
     * @param Dataset $dataset
     * @param string $fieldCode
     * @param AdapterInterface $connection
     * @throws InvalidArgumentException When the field has no MySQL mapping or the provider is unknown.
     */
    public function resolve(Dataset $dataset, string $fieldCode, AdapterInterface $connection): string
    {
        $mapping = $dataset->getField($fieldCode)->sourceMappings[self::SOURCE] ?? null;
        if ($mapping?->expression !== null) {
            return $mapping->expression;
        }
        if ($mapping?->expressionProvider !== null) {
            $provider = $this->providers[$mapping->expressionProvider] ?? throw new InvalidArgumentException(
                \sprintf('Unknown expression provider "%s".', $mapping->expressionProvider)
            );

            return $provider->getExpression($dataset, $connection);
        }

        throw new InvalidArgumentException(
            \sprintf('Field "%s" of dataset "%s" has no mysql mapping.', $fieldCode, $dataset->code)
        );
    }
}
