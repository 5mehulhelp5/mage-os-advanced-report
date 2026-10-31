<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Carts;

use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\Query\Clock;
use MageOS\AdvancedReport\Model\Query\ClockInterface;
use MageOS\AdvancedReport\Test\Integration\Helper\FixedClock;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Quote lifetime warning. "Now" is pinned to 2026-03-15 12:00:00 UTC with a 30 day quote lifetime, so the
 * retention window starts on 2026-02-13.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    Config('checkout/cart/delete_quote_after', '30'),
    Config('general/locale/timezone', 'UTC'),
    Config('general/locale/timezone', 'UTC', 'website', 'base'),
]
final class QuoteRetentionWarningTest extends TestCase
{
    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        $clock = new FixedClock('2026-03-15 12:00:00');
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->addSharedInstance($clock, Clock::class);
        $objectManager->addSharedInstance($clock, ClockInterface::class);
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    public function testItWarnsForACartRangeStartingBeforeTheQuoteLifetimeWindow(): void
    {
        $warnings = $this->runner->run('carts', ['carts'], [], [], '2026-01-01', '2026-03-15')->getWarnings();

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('more than 30 days', $warnings[0]);
        $this->assertStringContainsString('before 2026-02-13', $warnings[0]);
    }

    public function testItDoesNotWarnForACartRangeInsideTheWindow(): void
    {
        $this->assertSame([], $this->runner->run('carts', ['carts'], [], [], '2026-03-01', '2026-03-15')->getWarnings());
    }

    public function testItDoesNotWarnForDatasetsOutsideTheCartsArea(): void
    {
        $this->assertSame(
            [],
            $this->runner->run('sales_orders', ['orders'], [], [], '2026-01-01', '2026-03-15')->getWarnings()
        );
    }
}
