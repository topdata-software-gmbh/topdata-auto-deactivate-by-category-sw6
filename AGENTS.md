# AGENTS.md

Shopware 6.7 plugin (`shopware-platform-plugin` type) that auto-deactivates products when assigned to configured "trash" categories. Source under `src/`; namespace `Topdata\TopdataAutoDeactivateByCategorySW6\` is mapped via `composer.json` `autoload.psr-4`.

## Layout (where things actually live)
- `src/TopdataAutoDeactivateByCategorySW6.php` — empty Plugin class (only entrypoint Composer/shopware needs).
- `src/Service/ConfigurationService.php` — reads plugin config via `SystemConfigService`. Config key prefix is **`TopdataAutoDeactivateByCategorySW6.config.`** (literal, with trailing dot). Keys: `trashCategories`, `reactivationBehavior` (`manual`|`auto`), `forceInactive`.
- `src/Service/ProductStatusManager.php` — bulk `product.repository->update(...)` wrapper. Sets context state **`skip_auto_deactivate_plugin`** before the update and removes it after, to break subscriber loops. **Always go through this service** for product activation writes — direct `update()` would re-trigger `product.written`/`product_category.written`.
- `src/Subscriber/ProductCategorySubscriber.php` — listens to `product_category.written`, `product_category.deleted`, `product.written`. First line of every handler must be the `STATE_SKIP_AUTO_DEACTIVATE` check, otherwise the CLI sync and reactivation writes will recurse.
- `src/Command/DeactivateExistingProductsCommand.php` — CLI `topdata:autodeactivate:sync`. Registered via `#[AsCommand]` (no `services.xml` entry needed for the command itself, but it IS still listed there — both work, don't double-register).
- `src/Resources/config/services.xml` — explicit DI. **No `autoconfigure`/`autowire`**; new services must be added here by hand or they will not be in the container.
- `src/Resources/config/config.xml` — admin config form. Trash-category picker uses `<component name="sw-entity-multi-id-select">` (intentional — see commit `dff853e`; do not revert to `multi-entity-select`).

## Operational notes
- Target Shopware: **6.7.\*** (see `composer.json` `require`).
- Plugin icon path declared in `composer.json` `extra.plugin-icon` is `src/Resources/config/plugin.png` (yes, the icon lives next to the XML configs, not under `Resources/public/`).
- There is **no test suite** (`tests/` contains only `.gitkeep`) and **no CI / lint / typecheck config** in the repo. Don't invent commands; ask before running anything beyond `git`, `composer`, or Shopware's own CLI.
- `bin/console topdata:autodeactivate:sync` (from a Shopware install with this plugin activated) is the only operational entry — useful for back-filling products that were already in trash categories before install.
- `_ai/backlog/` exists for control-plane docs (plans/reports/epics). Not part of the runtime plugin.

## Conventions specific to this repo
- New service classes go in `src/Service/`, subscribers in `src/Subscriber/`, commands in `src/Command/`. Register each in `services.xml`; do not rely on autoconfigure.
- Use `declare(strict_types=1);` and constructor property promotion (matches existing code).
- Bilingual labels (`en-GB`/`de-DE`) are the norm in `config.xml` — keep parity when adding fields.
- Avoid removing the `STATE_SKIP_AUTO_DEACTIVATE` guard from `ProductStatusManager::updateProductStatus`; it is load-bearing for preventing infinite DAL write loops.

## Reference
- OpenCode project config: `.opencode/opencode.jsonc` (inherits global AGENTS.md via `instructions`).
- README has user-facing install/usage notes; this file covers agent/developer context only.