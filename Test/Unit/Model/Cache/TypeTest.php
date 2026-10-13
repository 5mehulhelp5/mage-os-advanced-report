<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Cache;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use MageOS\AdvancedReport\Model\Cache\Type;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Type::class)]
final class TypeTest extends TestCase
{
    public function testItExposesTheCacheTypeIdentifierDeclaredInCacheXml(): void
    {
        $xml = simplexml_load_string((string) file_get_contents(\dirname(__DIR__, 4) . '/etc/cache.xml'));
        self::assertNotFalse($xml);

        self::assertSame(Type::class, (string) $xml->type['instance']);
        self::assertSame(Type::TYPE_IDENTIFIER, (string) $xml->type['name']);
    }

    public function testItTagsCacheEntriesWithTheAdvancedReportCacheTag(): void
    {
        $frontendPool = $this->createMock(FrontendPool::class);
        $frontend = $this->createStub(FrontendInterface::class);
        $frontendPool->expects(self::once())
            ->method('get')
            ->with(Type::TYPE_IDENTIFIER)
            ->willReturn($frontend);

        $model = (new ObjectManager($this))->getObject(
            Type::class,
            ['cacheFrontendPool' => $frontendPool]
        );

        self::assertInstanceOf(Type::class, $model);
        self::assertSame('ADVANCED_REPORT', $model->getTag());
    }
}
