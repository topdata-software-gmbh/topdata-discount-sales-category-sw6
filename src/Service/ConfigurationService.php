<?php declare(strict_types=1);

namespace Topdata\TopdataDiscountSalesCategorySW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigurationService
{
    private const CONFIG_PREFIX = 'TopdataDiscountSalesCategorySW6.config.';

    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function getSalesCategoryId(?string $salesChannelId = null): ?string
    {
        $value = $this->systemConfigService->get(self::CONFIG_PREFIX . 'salesCategoryId', $salesChannelId);
        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function isAutoCreateCategoryEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfigService->get(self::CONFIG_PREFIX . 'autoCreateCategory', $salesChannelId);
    }
}
