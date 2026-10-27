<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Helper;

use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavour;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavourDetector;
use PHPUnit\Framework\TestCase;

/**
 * Lets integration tests branch on the database server flavour using the production detector. Only flavour-specific
 * mechanics may depend on it; metric value assertions must never be skipped by flavour.
 */
final class ServerFlavourAssertions
{
    public static function current(): ServerFlavour
    {
        return Bootstrap::getObjectManager()->get(ServerFlavourDetector::class)->detect();
    }

    public static function skipUnless(TestCase $test, ServerFlavour $expected): void
    {
        $actual = self::current();
        if ($actual !== $expected) {
            $test->markTestSkipped(\sprintf('Flavour specific test for %s, server is %s.', $expected->value, $actual->value));
        }
    }
}
