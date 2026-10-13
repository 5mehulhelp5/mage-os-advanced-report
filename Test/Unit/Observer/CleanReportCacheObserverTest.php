<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Observer;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Event\Observer;
use MageOS\AdvancedReport\Model\Cache\Type;
use MageOS\AdvancedReport\Observer\CleanReportCacheObserver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CleanReportCacheObserver::class)]
final class CleanReportCacheObserverTest extends TestCase
{
    public function testItCleansTheAdvancedReportCacheTypeWhenTheModuleConfigurationSectionIsSaved(): void
    {
        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $cacheTypeList->expects(self::once())
            ->method('cleanType')
            ->with(Type::TYPE_IDENTIFIER);

        $observer = new CleanReportCacheObserver($cacheTypeList);

        $observer->execute($this->createStub(Observer::class));
    }
}
