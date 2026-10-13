<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

final class PresetDescriptionsTest extends TestCase
{
    public function testItGivesEveryPresetADescription(): void
    {
        $xml = simplexml_load_string((string) file_get_contents(__DIR__ . '/../../../etc/report_datasets.xml'));
        self::assertNotFalse($xml);

        $presets = $xml->xpath('//preset');
        self::assertIsArray($presets);
        self::assertCount(18, $presets);
        foreach ($presets as $preset) {
            self::assertNotSame('', trim((string) $preset['description']), (string) $preset['code']);
        }
    }
}
