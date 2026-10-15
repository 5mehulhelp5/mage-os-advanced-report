<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Tooling;

use MageOS\AdvancedReport\Test\Tooling\XsdValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(XsdValidator::class)]
final class XsdValidatorTest extends TestCase
{
    private const string ETC = __DIR__ . '/../../../etc';

    public function testItReportsNoErrorsForTheShippedReportDatasetsXml(): void
    {
        $errors = (new XsdValidator())->validateFile(
            self::ETC . '/report_datasets.xml',
            self::ETC . '/report_datasets_file.xsd'
        );

        self::assertSame([], $errors);
    }

    public function testItReportsAnErrorForAJoinWithUnknownCardinalityValue(): void
    {
        $errors = $this->validateXml(
            '<config><dataset code="a"><join code="j" cardinality="many-to-many"/></dataset></config>'
        );

        self::assertCount(1, $errors);
        self::assertStringContainsString('cardinality', $errors[0]);
    }

    public function testItReportsAnErrorForADatasetWithoutCodeAttribute(): void
    {
        $errors = $this->validateXml('<config><dataset label="No code"/></config>');

        self::assertCount(1, $errors);
        self::assertStringContainsString("'code' is required", $errors[0]);
    }

    public function testItReportsLineNumbersOfLibxmlValidationErrors(): void
    {
        $errors = $this->validateXml("<config>\n<dataset code=\"a\"/>\n<dataset label=\"x\"/>\n</config>");

        self::assertCount(1, $errors);
        self::assertStringStartsWith('Line 3:', $errors[0]);
    }

    /**
     * @return list<string>
     */
    private function validateXml(string $xml): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xsd');
        self::assertIsString($path);
        file_put_contents($path, $xml);

        try {
            return (new XsdValidator())->validateFile($path, self::ETC . '/report_datasets_file.xsd');
        } finally {
            unlink($path);
        }
    }
}
