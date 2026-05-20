---
filename: "_ai/backlog/active/260520_1152__IMPLEMENTATION_PLAN__auto-deactivate-by-category.md"
title: "Implementation Plan: Auto Deactivate By Category"
createdAt: 2026-05-20 11:52
updatedAt: 2026-05-20 11:52
status: draft
priority: high
tags: [shopware6, plugin, products, categories, automation]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description
Merchants often use a specific "Trash" or "Archive" category (e.g., "Geloeschte Artikel") to hide products they no longer want to sell. However, in Shopware 6, assigning a product to an inactive category does not automatically deactivate the product itself. Consequently, these products continue to appear in Storefront search results, search suggestions, and remain accessible via direct URLs. Manually deactivating each product is tedious and prone to human error.

## 2. Executive Summary
This implementation plan provides a robust, automated solution via a Shopware 6 plugin (`TopdataAutoDeactivateByCategorySW6`). The plugin will:
1. Provide a configuration interface for merchants to define which categories act as "Trash" categories, define whether products should automatically reactivate when removed from these categories, and toggle a "Force Inactive" mode.
2. Introduce an Event Subscriber that listens to category assignments (`product_category.written` / `deleted`) and product updates (`product.written`), automatically toggling the product's `active` status based on the configured rules.
3. Include a CLI command designed to process existing products already residing in these categories, ensuring the database is instantly clean upon plugin installation.
4. Adhere to SOLID principles by separating concerns (Configuration Service, Product Status Manager, Event Subscriber, CLI Command).

## 3. Project Environment Details
```yaml
Framework: Shopware 6.7.*
Plugin Name: TopdataAutoDeactivateByCategorySW6
Namespace: Topdata\TopdataAutoDeactivateByCategorySW6
Primary Database Entities: product, category, product_category
Dependencies: SystemConfigService, EntityRepository (Product)
Architecture Pattern: Event-Driven, Service-Oriented (SOLID)
```

---

## Phase 1: Configuration & Service Layer
First, we will define the plugin settings in the administration and build a service to read them. We will also build a dedicated manager service to handle product status updates without causing infinite loops.

### `src/Resources/config/config.xml` [MODIFY]
```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/platform/trunk/src/Core/System/SystemConfig/Schema/config.xsd">
    <card>
        <title>General Settings</title>
        <title lang="de-DE">Allgemeine Einstellungen</title>

        <input-field type="multi-entity-select">
            <name>trashCategories</name>
            <entity>category</entity>
            <label>Trash Categories</label>
            <label lang="de-DE">Papierkorb-Kategorien</label>
            <helpText>Products assigned to these categories will be automatically deactivated.</helpText>
            <helpText lang="de-DE">Produkte, die diesen Kategorien zugewiesen werden, werden automatisch deaktiviert.</helpText>
        </input-field>

        <input-field type="single-select">
            <name>reactivationBehavior</name>
            <label>Behavior on Category Removal</label>
            <label lang="de-DE">Verhalten bei Kategorie-Entfernung</label>
            <options>
                <option>
                    <id>manual</id>
                    <name>Leave inactive (Manual activation required)</name>
                    <name lang="de-DE">Inaktiv lassen (Manuelle Aktivierung erforderlich)</name>
                </option>
                <option>
                    <id>auto</id>
                    <name>Automatically reactivate</name>
                    <name lang="de-DE">Automatisch reaktivieren</name>
                </option>
            </options>
            <defaultValue>manual</defaultValue>
        </input-field>

        <input-field type="bool">
            <name>forceInactive</name>
            <label>Force Inactive (Override Manual Toggles)</label>
            <label lang="de-DE">Inaktiv erzwingen (Überschreibt manuelle Änderungen)</label>
            <helpText>If enabled, prevents a product from being manually activated while it remains in a Trash Category.</helpText>
            <helpText lang="de-DE">Wenn aktiviert, wird verhindert, dass ein Produkt manuell aktiviert wird, solange es sich in einer Papierkorb-Kategorie befindet.</helpText>
            <defaultValue>true</defaultValue>
        </input-field>
    </card>
</config>
```

### `src/Service/ConfigurationService.php` [NEW FILE]
```php
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
```

### `src/Service/ProductStatusManager.php` [NEW FILE]
```php
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
```

---

## Phase 2: Event Subscriptions
We will hook into Shopware's DataAbstractionLayer (DAL) events to catch product creations, updates, and category assignments.

### `src/Subscriber/ProductCategorySubscriber.php` [NEW FILE]
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAutoDeactivateByCategorySW6\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataAutoDeactivateByCategorySW6\Service\ConfigurationService;
use Topdata\TopdataAutoDeactivateByCategorySW6\Service\ProductStatusManager;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

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
            // Check if these products still belong to ANY other trash category before reactivating
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
            // Only care if active was set to true manually
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
```

---

## Phase 3: CLI Command
We need to handle the existing products by building a command that finds all active products in the configured trash categories and deactivates them.

### `src/Command/DeactivateExistingProductsCommand.php` [NEW FILE]
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAutoDeactivateByCategorySW6\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Topdata\TopdataAutoDeactivateByCategorySW6\Service\ConfigurationService;
use Topdata\TopdataAutoDeactivateByCategorySW6\Service\ProductStatusManager;

#[AsCommand(
    name: 'topdata:autodeactivate:sync',
    description: 'Deactivates all existing active products assigned to configured trash categories.'
)]
class DeactivateExistingProductsCommand extends Command
{
    public function __construct(
        private readonly ConfigurationService $configService,
        private readonly ProductStatusManager $productStatusManager,
        private readonly EntityRepository $productRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $trashCategoryIds = $this->configService->getTrashCategoryIds();
        
        if (empty($trashCategoryIds)) {
            $output->writeln('<comment>No trash categories configured. Please configure the plugin first.</comment>');
            return Command::SUCCESS;
        }

        $context = Context::createDefaultContext();
        
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsAnyFilter('categories.id', $trashCategoryIds));
        
        $productIds = $this->productRepository->searchIds($criteria, $context)->getIds();
        
        if (empty($productIds)) {
            $output->writeln('<info>No active products found in trash categories. Everything is clean.</info>');
            return Command::SUCCESS;
        }
        
        $output->writeln(sprintf('<info>Found %d active products in trash categories. Deactivating...</info>', count($productIds)));
        
        $this->productStatusManager->updateProductStatus($productIds, false, $context);
        
        $output->writeln('<info>Successfully deactivated products.</info>');
        
        return Command::SUCCESS;
    }
}
```

### `src/Command/ExampleCommand.php` [DELETE]

---

## Phase 4: Service Registration & Cleanup
We will update `services.xml` to inject the dependencies required by our new classes, and delete the example controllers.

### `src/Resources/config/services.xml` [MODIFY]
```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <!-- Services -->
        <service id="Topdata\TopdataAutoDeactivateByCategorySW6\Service\ConfigurationService">
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
        </service>

        <service id="Topdata\TopdataAutoDeactivateByCategorySW6\Service\ProductStatusManager">
            <argument type="service" id="product.repository"/>
            <argument type="service" id="Topdata\TopdataAutoDeactivateByCategorySW6\Service\ConfigurationService"/>
        </service>

        <!-- Subscribers -->
        <service id="Topdata\TopdataAutoDeactivateByCategorySW6\Subscriber\ProductCategorySubscriber">
            <argument type="service" id="Topdata\TopdataAutoDeactivateByCategorySW6\Service\ConfigurationService"/>
            <argument type="service" id="Topdata\TopdataAutoDeactivateByCategorySW6\Service\ProductStatusManager"/>
            <argument type="service" id="product.repository"/>
            <tag name="kernel.event_subscriber"/>
        </service>

        <!-- Commands -->
        <service id="Topdata\TopdataAutoDeactivateByCategorySW6\Command\DeactivateExistingProductsCommand">
            <argument type="service" id="Topdata\TopdataAutoDeactivateByCategorySW6\Service\ConfigurationService"/>
            <argument type="service" id="Topdata\TopdataAutoDeactivateByCategorySW6\Service\ProductStatusManager"/>
            <argument type="service" id="product.repository"/>
            <tag name="console.command"/>
        </service>
    </services>
</container>
```

### Delete Boilerplate Files
- `src/Controller/AdminApiExampleController.php` [DELETE]
- `src/Controller/StorefrontExampleController.php` [DELETE]
- `src/Resources/config/routes.xml` [DELETE]
- `src/Resources/views/storefront/example.html.twig` [DELETE]
- `src/Resources/views/storefront` directory [DELETE]
- `src/Resources/views` directory [DELETE]

### `README.md` [MODIFY]
```markdown
# Topdata Auto Deactivate By Category SW6

![Plugin Icon](src/Resources/config/plugin.png)

## Overview
This Shopware 6 plugin automatically deactivates products when they are assigned to specific "Trash" or "Archive" categories. 
This is highly useful for managing seasonal items, discontinued stock, or deleted articles without having to manually toggle product visibility settings.

## Installation
1. Download the plugin.
2. Upload to your Shopware 6 installation via the Plugin Manager or place it in `custom/plugins/TopdataAutoDeactivateByCategorySW6`.
3. Install and activate the plugin.
4. **Configuration:** Navigate to the plugin configuration and select your designated Trash Categories.

## Usage & Features
* **Auto-Deactivation:** Assign a product to a configured category, and its status will be set to `Inactive`.
* **Auto-Reactivation (Optional):** If configured, removing a product from the trash category will set it back to `Active`.
* **Force Inactive:** Prevents merchants from accidentally activating a product that is currently residing in a Trash Category.

## CLI Command
If you already have products sitting in your trash categories prior to installing this plugin, you can synchronize their status using the built-in CLI command:
```bash
bin/console topdata:autodeactivate:sync
```

## Requirements
- Shopware 6.7.*

## License
MIT
```

---

## Phase 5: Implementation Report Generator
Once the coding agent finishes implementing this plan, it should output an implementation report exactly following this format in the specific file path:

---
filename: "_ai/backlog/reports/260520_1152__IMPLEMENTATION_REPORT__auto-deactivate-by-category.md"
title: "Report: Implementation Plan: Auto Deactivate By Category"
createdAt: 2026-05-20 11:52
updatedAt: 2026-05-20 11:52
planFile: "_ai/backlog/active/260520_1152__IMPLEMENTATION_PLAN__auto-deactivate-by-category.md"
project: "TopdataAutoDeactivateByCategorySW6"
status: completed
filesCreated: 4
filesModified: 3
filesDeleted: 6
tags: [shopware6, plugin, products, categories, automation]
documentType: IMPLEMENTATION_REPORT
---
*(The coding agent must generate the body of the report containing Summary, Files Changed, Key Changes, Deviations, Technical Decisions, Testing Notes, Usage Examples, and Next Steps based on the execution).*

