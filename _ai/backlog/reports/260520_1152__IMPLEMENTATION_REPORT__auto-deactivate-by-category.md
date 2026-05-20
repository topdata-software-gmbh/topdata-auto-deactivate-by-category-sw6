---
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

## Summary
The implementation plan for the "Topdata Auto Deactivate By Category SW6" plugin was successfully executed. The plugin automatically toggles product visibility status based on trash category assignments, with additional features to prevent manual reactivations of discarded items, and to synchronize inherited products using a CLI command.

## Files Changed

### Files Created
1. `src/Service/ConfigurationService.php`
2. `src/Service/ProductStatusManager.php`
3. `src/Subscriber/ProductCategorySubscriber.php`
4. `src/Command/DeactivateExistingProductsCommand.php`

### Files Modified
1. `src/Resources/config/config.xml`
2. `src/Resources/config/services.xml`
3. `README.md`

### Files Deleted
1. `src/Command/ExampleCommand.php`
2. `src/Controller/AdminApiExampleController.php`
3. `src/Controller/StorefrontExampleController.php`
4. `src/Resources/config/routes.xml`
5. `src/Resources/views/storefront/example.html.twig`
6. `src/Resources/views/` (and its subdirectories)

## Key Changes
- **Configuration Fields**: Introduced three configuration fields inside `config.xml` to manage trash categories, auto-reactivation behavior, and force-inactive functionality.
- **Service Layer**: Configured `ConfigurationService` to fetch plugin settings easily, and `ProductStatusManager` to safely interact with DAL without triggering infinite entity update loops.
- **Event Subscriber**: Added `ProductCategorySubscriber` checking DAL hooks on `product_category.written`, `product_category.deleted`, and `product.written` to toggle `[active]` fields. 
- **CLI Command**: Created `DeactivateExistingProductsCommand` at `bin/console topdata:autodeactivate:sync` to initially migrate and synchronize database legacy records.
- **Cleanup**: Unnecessary boilerplate skeleton classes such as example Command, Controller, and API Routes from the Shopware default template builder were deleted. The DI container XML (`services.xml`) was modernized for our implementations.

## Deviations
None. The implementation was executed precisely verbatim.

## Testing Notes
The plugin requires existing Shopware 6.7 environment to be manually tested. Focus areas:
1. Adding a trash category to a product deactivates it immediately.
2. The config flag `Force Inactive` blocks activation attempts from Admin.
3. Running `bin/console topdata:autodeactivate:sync` successfully processes any backlog items asynchronously.

## Usage Examples
See `# Usage & Features` section in the updated `README.md`.

## Next Steps
- Zip the plugin and test installation on a fresh Shopware 6.7 environment.
- Plan unit/integration tests covering `ProductCategorySubscriber` logic if expanding the feature-set.