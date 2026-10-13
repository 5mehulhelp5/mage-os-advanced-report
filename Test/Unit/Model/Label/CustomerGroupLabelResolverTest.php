<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\Data\GroupSearchResultsInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use MageOS\AdvancedReport\Model\Label\CustomerGroupLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomerGroupLabelResolver::class)]
final class CustomerGroupLabelResolverTest extends TestCase
{
    public function testItResolvesCustomerGroupCodesInASingleRepositoryCall(): void
    {
        $criteria = $this->createStub(SearchCriteriaInterface::class);
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->expects($this->once())->method('addFilter')
            ->with('customer_group_id', ['0', '1'], 'in')->willReturnSelf();
        $builder->method('create')->willReturn($criteria);
        $g0 = $this->createStub(GroupInterface::class);
        $g0->method('getId')->willReturn('0');
        $g0->method('getCode')->willReturn('NOT LOGGED IN');
        $g1 = $this->createStub(GroupInterface::class);
        $g1->method('getId')->willReturn('1');
        $g1->method('getCode')->willReturn('General');
        $results = $this->createStub(GroupSearchResultsInterface::class);
        $results->method('getItems')->willReturn([$g0, $g1]);
        $repository = $this->createMock(GroupRepositoryInterface::class);
        $repository->expects($this->once())->method('getList')->with($criteria)->willReturn($results);

        $labels = (new CustomerGroupLabelResolver($repository, $builder))->resolve(['0', '1', '1']);

        $this->assertSame(['0' => 'NOT LOGGED IN', '1' => 'General'], $labels);
    }
}
