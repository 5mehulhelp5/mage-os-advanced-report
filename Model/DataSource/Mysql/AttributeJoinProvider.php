<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DatasetExtenderInterface;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\Join;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use MageOS\AdvancedReport\Model\Label\AttributeOptionLabelResolver;

/**
 * Synthesizes fields, joins and dimensions for the configured product attributes on datasets
 * exposing `product_id`.
 *
 * Values are read from the simple child when the item is a configurable (colour/size live on
 * the child), otherwise from the item product. EAV tables are joined on `entity_id` directly:
 * Adobe Commerce staging (`row_id`) is deliberately not supported. Multiselect values are
 * comma separated and bucketed by their raw value.
 *
 * Datasets without a `child` join (e.g. the provider-backed `catalog_stock`) have no child to prefer:
 * their attribute joins do not require `child` and read the value from `main.product_id`. The stock
 * providers only select cost, price and status themselves, so the synthesized joins never duplicate them.
 */
class AttributeJoinProvider implements DatasetExtenderInterface
{
    private const string ENTITY_TYPE = 'catalog_product';
    private const string PRODUCT_FIELD = 'product_id';
    private const string CHILD_JOIN = 'child';
    private const string LABEL_RESOLVER = AttributeOptionLabelResolver::class;
    private const array VALUE_TABLES = [
        'select' => 'catalog_product_entity_int',
        'multiselect' => 'catalog_product_entity_varchar',
    ];

    /**
     * @param Config $config
     * @param AttributeRepositoryInterface $attributeRepository
     */
    public function __construct(
        private readonly Config $config,
        private readonly AttributeRepositoryInterface $attributeRepository,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function extend(Dataset $dataset): Dataset
    {
        if (!$dataset->hasField(self::PRODUCT_FIELD)) {
            return $dataset;
        }

        $fields = $dataset->fields;
        $joins = $dataset->joins;
        $dimensions = $dataset->dimensions;
        $hasChild = isset($dataset->joins[self::CHILD_JOIN]);
        foreach ($this->config->getReportAttributes() as $code) {
            $attribute = $this->loadAttribute($code);
            $table = $attribute !== null ? self::VALUE_TABLES[(string) $attribute->getFrontendInput()] ?? null : null;
            if ($attribute === null || $table === null) {
                continue;
            }

            $key = 'attr_' . $code;
            $label = (string) ($attribute->getDefaultFrontendLabel() ?: $code);
            $condition = $this->condition($key, (int) $attribute->getAttributeId(), $hasChild);
            $joins[$key] = new Join($key, $table, $key, $condition, $hasChild ? [self::CHILD_JOIN] : []);
            $fields[$key] = new Field(
                $key,
                $label,
                FieldType::STRING,
                ['mysql' => new SourceMapping($key . '.value', null, [$key])],
            );
            $dimensions[$key] = new Dimension(
                $key,
                $label,
                $key,
                [],
                self::LABEL_RESOLVER,
                ['attribute_code' => $code],
            );
        }

        return new Dataset(
            $dataset->code,
            $dataset->label,
            $dataset->aclResource,
            $dataset->area,
            $dataset->dateless,
            $dataset->baseTable,
            $dataset->baseAlias,
            $dataset->baseProvider,
            $dataset->implicitFilters,
            $fields,
            $joins,
            $dataset->metrics,
            $dimensions,
        );
    }

    /**
     * @param string $alias
     * @param int $attributeId
     * @param bool $hasChild
     */
    private function condition(string $alias, int $attributeId, bool $hasChild): string
    {
        return \sprintf(
            '%1$s.entity_id = %3$s AND %1$s.attribute_id = %2$d AND %1$s.store_id = 0',
            $alias,
            $attributeId,
            $hasChild ? 'COALESCE(child.product_id, main.product_id)' : 'main.product_id',
        );
    }

    /**
     * @param string $code
     */
    private function loadAttribute(string $code): ?AttributeInterface
    {
        try {
            return $this->attributeRepository->get(self::ENTITY_TYPE, $code);
        } catch (NoSuchEntityException) {
            return null;
        }
    }
}
