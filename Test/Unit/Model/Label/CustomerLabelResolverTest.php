<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use MageOS\AdvancedReport\Model\Label\CustomerLabelResolver;
use MageOS\AdvancedReport\Model\ResourceModel\CustomerOrderNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomerLabelResolver::class)]
final class CustomerLabelResolverTest extends TestCase
{
    public function testItLabelsACustomerEmailWithTheFullNameAndEmail(): void
    {
        self::assertSame(
            ['a@x.com' => 'Ann Lee <a@x.com>'],
            $this->resolver(['a@x.com' => 'Ann Lee'])->resolve(['a@x.com'])
        );
    }

    public function testItLabelsACustomerEmailWithTheEmailOnlyWhenNoNameIsStored(): void
    {
        self::assertSame(
            ['a@x.com' => 'a@x.com'],
            $this->resolver(['a@x.com' => ''])->resolve(['a@x.com'])
        );
    }

    public function testItKeepsTheRawEmailWhenNoOrderIsFound(): void
    {
        self::assertSame(['z@x.com' => 'z@x.com'], $this->resolver([])->resolve(['z@x.com']));
    }

    /**
     * @param array<string, string> $names
     */
    private function resolver(array $names): CustomerLabelResolver
    {
        $lookup = $this->createStub(CustomerOrderNames::class);
        $lookup->method('getNames')->willReturn($names);

        return new CustomerLabelResolver($lookup);
    }
}
