<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Tooling;

use DOMDocument;
use LibXMLError;
use RuntimeException;

final class XsdValidator
{
    /**
     * @return list<string> one "Line N: message" entry per libxml validation error
     */
    public function validateFile(string $xmlPath, string $xsdPath): array
    {
        $xml = file_get_contents($xmlPath);
        if ($xml === false) {
            throw new RuntimeException(sprintf('Cannot read XML file "%s".', $xmlPath));
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument();
            $document->loadXML($xml);
            $document->schemaValidate($xsdPath);

            return array_map(
                static fn (LibXMLError $error): string => sprintf('Line %d: %s', $error->line, trim($error->message)),
                libxml_get_errors()
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
