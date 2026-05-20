<?php declare(strict_types=1);

namespace Topdata\TopdataAutoDeactivateByCategorySW6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class ProductStatusManager
{
    public const STATE_SKIP_AUTO_DEACTIVATE = 'skip_auto_deactivate_plugin';

    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly ConfigurationService $configService
    ) {
    }

    public function updateProductStatus(array $productIds, bool $active, Context $context): void
    {
        if (empty($productIds)) {
            return;
        }

        $updates = array_map(static fn (string $id) => [
            'id' => $id,
            'active' => $active
        ], array_unique($productIds));

        // Add state to context to prevent infinite loops in subscribers
        $context->addState(self::STATE_SKIP_AUTO_DEACTIVATE);
        
        $this->productRepository->update($updates, $context);
        
        $context->removeState(self::STATE_SKIP_AUTO_DEACTIVATE);
    }
}
