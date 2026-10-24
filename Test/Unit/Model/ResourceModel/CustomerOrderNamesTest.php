<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\ResourceModel\CustomerOrderNames;
use MageOS\AdvancedReport\Test\Unit\Helper\SelectTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomerOrderNames::class)]
final class CustomerOrderNamesTest extends TestCase
{
    use SelectTestTrait;

    /** @var string[] */
    private array $queries = [];

    /** @var array<int, array<int, array<string, string|null>>> */
    private array $results = [];

    private CustomerOrderNames $subject;

    protected function setUp(): void
    {
        $adapter = $this->createSqlAdapter();
        $adapter->method('fetchAll')->willReturnCallback(function (string $sql): array {
            $this->queries[] = $this->normalizeSql($sql);

            return $this->results[\count($this->queries) - 1] ?? [];
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturnCallback(
            static fn (string $name): object => $name === 'report' ? $adapter : throw new \LogicException($name)
        );
        $resource->method('getTableName')->willReturnArgument(0);
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn('report');
        $guardrails = $this->createStub(Guardrails::class);
        $guardrails->method('applyTimeout')->willReturnCallback(
            static fn (string $sql): string => 'TIMEOUT ' . $sql
        );
        $this->subject = new CustomerOrderNames($resource, new ReportConnection($resource, $config), $guardrails);
    }

    public function testItLooksUpTheRequestedEmailsWithOneQueryPerChunkOfAtMost500Emails(): void
    {
        $emails = array_map(static fn (int $i): string => 'u' . $i . '@x.com', range(1, 1001));

        $this->subject->getNames($emails);

        self::assertCount(3, $this->queries);
        self::assertSame(500, substr_count($this->queries[0], '@x.com'));
        self::assertSame(1, substr_count($this->queries[2], '@x.com'));
    }

    public function testItAppliesTheQueryTimeoutToTheCustomerOrderNamesQuery(): void
    {
        $this->subject->getNames(['a@x.com']);

        self::assertStringStartsWith('TIMEOUT SELECT', $this->queries[0]);
    }

    public function testItFiltersOnTheRawCustomerEmailColumnSoTheEmailIndexIsUsable(): void
    {
        $this->subject->getNames(['a@x.com']);

        self::assertStringContainsString("(o.customer_email IN ('a@x.com'))", $this->queries[0]);
        self::assertStringNotContainsString('LOWER(', $this->queries[0]);
        self::assertStringNotContainsString('TRIM(', $this->queries[0]);
        self::assertStringNotContainsString('status', $this->queries[0]);
        self::assertStringContainsString('ORDER BY `o`.`created_at` DESC, `o`.`entity_id` DESC', $this->queries[0]);
        self::assertStringContainsString("a.address_type = 'billing'", $this->queries[0]);
    }

    public function testItTakesTheNameFromTheMostRecentOrderOfTheEmail(): void
    {
        $this->results[0] = [
            $this->row('a@x.com', 'New', 'Name'),
            $this->row('a@x.com', 'Old', 'Name'),
        ];

        self::assertSame(['a@x.com' => 'New Name'], $this->subject->getNames(['a@x.com']));
    }

    public function testItNormalisesEmailsToLowerCaseBeforeMatching(): void
    {
        $this->results[0] = [
            $this->row(' A@X.com ', 'Ann', 'Lee'),
            $this->row('a@x.COM', 'Old', 'One'),
        ];

        self::assertSame(['a@x.com' => 'Ann Lee'], $this->subject->getNames(['a@x.com']));
    }

    public function testItFallsBackToTheBillingAddressNameWhenTheOrderHasNoCustomerName(): void
    {
        $this->results[0] = [$this->row('g@x.com', ' ', null, 'Gus', 'Guest')];

        self::assertSame(['g@x.com' => 'Gus Guest'], $this->subject->getNames(['g@x.com']));
    }

    public function testItReturnsAnEmptyNameWhenNeitherTheOrderNorItsBillingAddressHasOne(): void
    {
        $this->results[0] = [$this->row('n@x.com', '', '')];

        self::assertSame(['n@x.com' => ''], $this->subject->getNames(['n@x.com']));
    }

    /**
     * @return array<string, string|null>
     */
    private function row(
        string $email,
        ?string $first,
        ?string $last,
        ?string $billingFirst = null,
        ?string $billingLast = null
    ): array {
        return [
            'customer_email' => $email,
            'customer_firstname' => $first,
            'customer_lastname' => $last,
            'firstname' => $billingFirst,
            'lastname' => $billingLast,
        ];
    }
}
