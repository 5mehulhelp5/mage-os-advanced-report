<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use Magento\Catalog\Api\CategoryListInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\CategorySearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use MageOS\AdvancedReport\Model\Label\CategoryLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CategoryLabelResolver::class)]
final class CategoryLabelResolverTest extends TestCase
{
    public function testItResolvesCategoryNamesInBulk(): void
    {
        $criteria = $this->createStub(SearchCriteriaInterface::class);
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->expects($this->once())->method('addFilter')->with('entity_id', ['3', '4'], 'in')->willReturnSelf();
        $builder->method('create')->willReturn($criteria);
        $items = [];
        foreach ([3 => 'Men', 4 => 'Women'] as $id => $name) {
            $category = $this->createStub(CategoryInterface::class);
            $category->method('getId')->willReturn($id);
            $category->method('getName')->willReturn($name);
            $items[] = $category;
        }
        $results = $this->createStub(CategorySearchResultsInterface::class);
        $results->method('getItems')->willReturn($items);
        $list = $this->createMock(CategoryListInterface::class);
        $list->expects($this->once())->method('getList')->with($criteria)->willReturn($results);

        $this->assertSame(
            ['3' => 'Men', '4' => 'Women'],
            (new CategoryLabelResolver($list, $builder))->resolve(['3', '4'])
        );
    }
}
