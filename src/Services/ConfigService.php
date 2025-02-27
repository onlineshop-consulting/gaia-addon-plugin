<?php

namespace OncoSfcAddon\Services;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigService
{
    private SystemConfigService $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {

        $this->systemConfigService = $systemConfigService;
    }

    public function getValue(string $key, ?string $salesChannelId = null)
    {
        return $this->systemConfigService->get('OncoSfcAddon.config.' . $key, $salesChannelId);
    }

    public function isWriteConfigurationListInOrderComment(): bool
    {
        return (bool)$this->getValue('writeConfigurationListInOrderComment');
    }

    public function isWriteConfigurationListInDescription(): bool
    {
        return (bool)$this->getValue('writeConfigurationListInDescription');
    }

    public function isConfigurationListCondensed(): bool
    {
        return (bool)$this->getValue('isConfigurationListCondensed');
    }
    public function isCreateOrderLineItemsForProducts(): bool
    {
        return (bool)$this->getValue('createOrderLineItemsForProducts');
    }

    public function isCreateOrderLineItemsForItemsWithNumber(): bool
    {
        return (bool)$this->getValue('createOrderLineItemsForItemsWithNumber');
    }

    public function isCreateOrderLineItemsForAllItems(): bool
    {
        return (bool)$this->getValue('createOrderLineItemsForAllItems');
    }
    public function isCreateOptions(): bool
    {
        return (bool)$this->getValue('createOptions');
    }

    public function isOverrideArticleNumber(): bool
    {
        return (bool)$this->getValue('overrideArticleNumber');
    }


}
