<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Config;

use Magento\Framework\Config\Dom;
use Magento\Framework\Config\FileResolverInterface;
use Magento\Framework\Config\Reader\Filesystem;
use Magento\Framework\Config\SchemaLocatorInterface;
use Magento\Framework\Config\ValidationStateInterface;

/**
 * Reads and merges report_datasets.xml across all modules by "code".
 *
 * $_idAttributes overrides the parent's legacy-named property
 * (Magento\Framework\Config\Reader\Filesystem).
 *
 * @SuppressWarnings("PHPMD.CamelCasePropertyName")
 */
class Reader extends Filesystem
{
    /**
     * @var array<string,string>
     */
    protected $_idAttributes = [
        '/config/dataset' => 'code',
        '/config/dataset/field' => 'code',
        '/config/dataset/metric' => 'code',
        '/config/dataset/dimension' => 'code',
        '/config/dataset/join' => 'code',
        '/config/dataset/implicitFilter' => 'code',
        '/config/dataset/source' => 'name',
        '/config/preset' => 'code',
        '/config/preset/metric' => 'code',
        '/config/preset/dimension' => 'code',
        '/config/preset/filter' => 'field',
        '/config/preset/sort' => 'by',
    ];

    /**
     * @param FileResolverInterface $fileResolver
     * @param Converter $converter
     * @param SchemaLocatorInterface $schemaLocator
     * @param ValidationStateInterface $validationState
     * @param string $fileName
     * @param array<string,string> $idAttributes
     * @param string $domDocumentClass
     * @param string $defaultScope
     */
    public function __construct(
        FileResolverInterface $fileResolver,
        Converter $converter,
        SchemaLocatorInterface $schemaLocator,
        ValidationStateInterface $validationState,
        string $fileName = 'report_datasets.xml',
        array $idAttributes = [],
        string $domDocumentClass = Dom::class,
        string $defaultScope = 'global',
    ) {
        parent::__construct(
            $fileResolver,
            $converter,
            $schemaLocator,
            $validationState,
            $fileName,
            $idAttributes,
            $domDocumentClass,
            $defaultScope,
        );
    }
}
