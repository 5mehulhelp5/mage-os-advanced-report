<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model;

use Magento\Framework\Exception\NotFoundException;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\ModuleGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleGuard::class)]
final class ModuleGuardTest extends TestCase
{
    public function testItThrowsNotFoundFromTheModuleGuardWhenTheModuleIsDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(false);

        $this->expectException(NotFoundException::class);

        (new ModuleGuard($config))->assertEnabled();
    }

    public function testItDoesNothingWhenTheModuleIsEnabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);

        (new ModuleGuard($config))->assertEnabled();

        $this->addToAssertionCount(1);
    }
}
