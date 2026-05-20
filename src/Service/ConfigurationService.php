<?php declare(strict_types=1);

namespace Topdata\TopdataAutoDeactivateByCategorySW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigurationService
{
    private const CONFIG_PREFIX = 'TopdataAutoDeactivateByCategorySW6.config.';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function getTrashCategoryIds(?string $salesChannelId = null): array
    {
        $categories = $this->systemConfigService->get(self::CONFIG_PREFIX . 'trashCategories', $salesChannelId);
        return is_array($categories) ? $categories : [];
    }

    public function getReactivationBehavior(?string $salesChannelId = null): string
    {
        return (string) $this->systemConfigService->get(self::CONFIG_PREFIX . 'reactivationBehavior', $salesChannelId) ?: 'manual';
    }

    public function isForceInactiveEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfigService->get(self::CONFIG_PREFIX . 'forceInactive', $salesChannelId);
    }
}
