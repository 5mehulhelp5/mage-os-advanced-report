<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use MageOS\AdvancedReport\Model\Label\ProductNameLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductNameLabelResolver::class)]
final class ProductNameLabelResolverTest extends TestCase
{
    public function testItResolvesProductNamesInBulkFallingBackToRaw(): void
    {
        $criteria = $this->createStub(SearchCriteriaInterface::class);
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->expects($this->once())->method('addFilter')->with('entity_id', ['10', '11'], 'in')->willReturnSelf();
        $builder->method('create')->willReturn($criteria);
        $product = $this->createStub(ProductInterface::class);
        $product->method('getId')->willReturn(10);
        $product->method('getName')->willReturn('Widget');
        $results = $this->createStub(ProductSearchResultsInterface::class);
        $results->method('getItems')->willReturn([$product]);
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects($this->once())->method('getList')->with($criteria)->willReturn($results);

        $labels = (new ProductNameLabelResolver($repository, $builder))->resolve(['10', '11']);

        $this->assertSame(['10' => 'Widget', '11' => '11'], $labels);
    }
}
