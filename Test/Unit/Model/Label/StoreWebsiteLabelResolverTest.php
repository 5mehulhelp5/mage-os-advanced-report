<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AdvancedReport\Model\Label\StoreLabelResolver;
use MageOS\AdvancedReport\Model\Label\WebsiteLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StoreLabelResolver::class)]
#[CoversClass(WebsiteLabelResolver::class)]
final class StoreWebsiteLabelResolverTest extends TestCase
{
    public function testItResolvesStoreAndWebsiteNames(): void
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getName')->willReturn('Default Store View');
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn(2);
        $website->method('getName')->willReturn('Main Website');
        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->expects($this->once())->method('getStores')->with(true)->willReturn([$store]);
        $manager->expects($this->once())->method('getWebsites')->with(true)->willReturn([$website]);

        $this->assertSame(
            ['1' => 'Default Store View', '9' => '9'],
            (new StoreLabelResolver($manager))->resolve(['1', '9'])
        );
        $this->assertSame(['2' => 'Main Website'], (new WebsiteLabelResolver($manager))->resolve(['2']));
    }
}
