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
