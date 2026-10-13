<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Config;

use Magento\Framework\Config\SchemaLocatorInterface;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;

/**
 * Locates the merged and per-file XSD schemas for report_datasets.xml.
 */
class SchemaLocator implements SchemaLocatorInterface
{
    private const string MODULE_NAME = 'MageOS_AdvancedReport';
    private const string MERGED_SCHEMA = 'report_datasets.xsd';
    private const string PER_FILE_SCHEMA = 'report_datasets_file.xsd';

    /**
     * @var string
     */
    private readonly string $schema;

    /**
     * @var string
     */
    private readonly string $perFileSchema;

    /**
     * @param ModuleDirReader $moduleDirReader
     */
    public function __construct(ModuleDirReader $moduleDirReader)
    {
        $etcDir = $moduleDirReader->getModuleDir(Dir::MODULE_ETC_DIR, self::MODULE_NAME);
        $this->schema = $etcDir . '/' . self::MERGED_SCHEMA;
        $this->perFileSchema = $etcDir . '/' . self::PER_FILE_SCHEMA;
    }

    /**
     * Get path to merged config schema
     *
     * @return string|null
     */
    public function getSchema(): ?string
    {
        return $this->schema;
    }

    /**
     * Get path to per file validation schema
     *
     * @return string|null
     */
    public function getPerFileSchema(): ?string
    {
        return $this->perFileSchema;
    }
}
