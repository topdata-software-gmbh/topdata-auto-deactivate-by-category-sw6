<?php declare(strict_types=1);

namespace Topdata\TopdataAutoDeactivateByCategorySW6\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataAutoDeactivateByCategorySW6\Service\ConfigurationService;
use Topdata\TopdataAutoDeactivateByCategorySW6\Service\ProductStatusManager;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

class ProductCategorySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigurationService $configService,
        private readonly ProductStatusManager $productStatusManager,
        private readonly EntityRepository $productRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'product_category.written' => 'onProductCategoryWritten',
            'product_category.deleted' => 'onProductCategoryDeleted',
            'product.written' => 'onProductWritten'
        ];
    }

    public function onProductCategoryWritten(EntityWrittenEvent $event): void
    {
        if ($event->getContext()->hasState(ProductStatusManager::STATE_SKIP_AUTO_DEACTIVATE)) {
            return;
        }

        $trashCategoryIds = $this->configService->getTrashCategoryIds();
        if (empty($trashCategoryIds)) {
            return;
        }

        $productsToDeactivate = [];
        foreach ($event->getWriteResults() as $result) {
            $payload = $result->getPayload();
            if (isset($payload['categoryId'], $payload['productId']) && in_array($payload['categoryId'], $trashCategoryIds, true)) {
                $productsToDeactivate[] = $payload['productId'];
            }
        }

        $this->productStatusManager->updateProductStatus($productsToDeactivate, false, $event->getContext());
    }

    public function onProductCategoryDeleted(EntityDeletedEvent $event): void
    {
        if ($event->getContext()->hasState(ProductStatusManager::STATE_SKIP_AUTO_DEACTIVATE)) {
            return;
        }

        if ($this->configService->getReactivationBehavior() !== 'auto') {
            return;
        }

        $trashCategoryIds = $this->configService->getTrashCategoryIds();
        if (empty($trashCategoryIds)) {
            return;
        }

        $productsToReactivate = [];
        foreach ($event->getWriteResults() as $result) {
            $pk = $result->getPrimaryKey();
            if (is_array($pk) && isset($pk['categoryId'], $pk['productId']) && in_array($pk['categoryId'], $trashCategoryIds, true)) {
                $productsToReactivate[] = $pk['productId'];
            }
        }

        if (!empty($productsToReactivate)) {
            $criteria = new Criteria($productsToReactivate);
            $criteria->addAssociation('categories');
            
            $products = $this->productRepository->search($criteria, $event->getContext());
            
            $safeToReactivate = [];
            foreach ($products as $product) {
                $hasTrashCategory = false;
                foreach ($product->getCategories() ?? [] as $category) {
                    if (in_array($category->getId(), $trashCategoryIds, true)) {
                        $hasTrashCategory = true;
                        break;
                    }
                }
                if (!$hasTrashCategory) {
                    $safeToReactivate[] = $product->getId();
                }
            }

            $this->productStatusManager->updateProductStatus($safeToReactivate, true, $event->getContext());
        }
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        if ($event->getContext()->hasState(ProductStatusManager::STATE_SKIP_AUTO_DEACTIVATE)) {
            return;
        }

        if (!$this->configService->isForceInactiveEnabled()) {
            return;
        }

        $trashCategoryIds = $this->configService->getTrashCategoryIds();
        if (empty($trashCategoryIds)) {
            return;
        }

        $productIdsToCheck = [];
        foreach ($event->getWriteResults() as $result) {
            $payload = $result->getPayload();
            if (isset($payload['active']) && $payload['active'] === true) {
                $productIdsToCheck[] = $result->getPrimaryKey();
            }
        }

        if (empty($productIdsToCheck)) {
            return;
        }

        $criteria = new Criteria($productIdsToCheck);
        $criteria->addAssociation('categories');
        $products = $this->productRepository->search($criteria, $event->getContext());

        $productsToRevert = [];
        foreach ($products as $product) {
            foreach ($product->getCategories() ?? [] as $category) {
                if (in_array($category->getId(), $trashCategoryIds, true)) {
                    $productsToRevert[] = $product->getId();
                    break;
                }
            }
        }

        $this->productStatusManager->updateProductStatus($productsToRevert, false, $event->getContext());
    }
}
