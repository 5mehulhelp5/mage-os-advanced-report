<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Config;

use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use MageOS\AdvancedReport\Model\Config\SchemaLocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaLocator::class)]
final class SchemaLocatorTest extends TestCase
{
    public function testItLocatesTheMergedAndPerFileSchemasInTheModuleEtcDirectory(): void
    {
        $moduleDirReader = $this->createMock(ModuleDirReader::class);
        $moduleDirReader->expects(self::once())
            ->method('getModuleDir')
            ->with(Dir::MODULE_ETC_DIR, 'MageOS_AdvancedReport')
            ->willReturn('/magento/app/code/MageOS/AdvancedReport/etc');

        $schemaLocator = new SchemaLocator($moduleDirReader);

        self::assertSame(
            '/magento/app/code/MageOS/AdvancedReport/etc/report_datasets.xsd',
            $schemaLocator->getSchema()
        );
        self::assertSame(
            '/magento/app/code/MageOS/AdvancedReport/etc/report_datasets_file.xsd',
            $schemaLocator->getPerFileSchema()
        );
    }
}
