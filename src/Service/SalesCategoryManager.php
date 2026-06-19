<?php declare(strict_types=1);

namespace Topdata\TopdataDiscountSalesCategorySW6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class SalesCategoryManager
{
    public const CATEGORY_NAME = 'Sales';

    public function __construct(
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $productCategoryRepository,
        private readonly ConfigurationService $configService
    ) {
    }

    public function ensureSalesCategoryExists(Context $context): ?string
    {
        $categoryId = $this->configService->getSalesCategoryId();
        if ($categoryId) {
            $exists = $this->categoryRepository->search(new Criteria([$categoryId]), $context)->first();
            if ($exists) {
                return $categoryId;
            }
        }

        if (!$this->configService->isAutoCreateCategoryEnabled()) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::CATEGORY_NAME));
        $existing = $this->categoryRepository->search($criteria, $context)->first();
        if ($existing) {
            return $existing->getId();
        }

        $newId = Uuid::randomHex();
        $this->categoryRepository->create([[
            'id' => $newId,
            'name' => self::CATEGORY_NAME,
            'parentId' => null,
            'active' => true,
            'visible' => true,
        ]], $context);

        return $newId;
    }

    public function assignProductsToSalesCategory(array $productIds, string $categoryId, Context $context): void
    {
        if (empty($productIds)) {
            return;
        }

        $entries = array_map(static fn (string $id) => [
            'productId' => $id,
            'categoryId' => $categoryId,
        ], array_values(array_unique($productIds)));

        $this->productCategoryRepository->upsert($entries, $context);
    }

    public function removeProductsFromSalesCategory(array $productIds, string $categoryId, Context $context): void
    {
        if (empty($productIds)) {
            return;
        }

        $entries = array_map(static fn (string $id) => [
            'productId' => $id,
            'categoryId' => $categoryId,
        ], array_values(array_unique($productIds)));

        $this->productCategoryRepository->delete($entries, $context);
    }

    /**
     * @return string[] hex-encoded UUIDs
     */
    public function getProductIdsInCategory(string $categoryId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('categories.id', $categoryId));
        return $this->productRepository->searchIds($criteria, $context)->getIds();
    }
}
