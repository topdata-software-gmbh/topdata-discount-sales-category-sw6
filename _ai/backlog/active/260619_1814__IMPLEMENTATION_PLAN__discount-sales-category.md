---
filename: "_ai/backlog/active/260619_1814__IMPLEMENTATION_PLAN__discount-sales-category.md"
title: "Implementation Plan: Discount Sales Category SW6"
createdAt: 2026-06-19 18:14
updatedAt: 2026-06-19 18:14
status: draft
priority: high
tags: [shopware6, plugin, discounts, categories, prems, cli]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description

The shop owner wants: *"Alle Produkte mit Rabatt sollen in Kategorie 'Sales' erscheinen"* — all products that currently have an active discount (managed by the third-party `PremsDiscountCategory6` plugin) should automatically appear in a "Sales" category in the storefront.

The `PremsDiscountCategory6` plugin assigns discounts to products indirectly via **Product Streams**: each discount campaign references a `product_stream_id`, and products belong to that stream via `product_stream_mapping`. Discounts are also time-bound (`valid_from` / `valid_until`) and can be restricted by sales channel, customer group, and category filters.

We cannot modify the Prems plugin. We need our own add-on plugin.

## 2. Executive Summary

This plan implements **Phase 1 (CLI-only approach)** of the `TopdataDiscountSalesCategorySW6` plugin. The plugin provides:

1. A **console command** `topdata:discount-sales:sync` that:
   - Queries the `prems_discount_category_base` table for all currently active discount campaigns (active=1, valid_from ≤ now ≤ valid_until)
   - Resolves the product stream IDs from those campaigns
   - Finds all product IDs in `product_stream_mapping` for those streams
   - Assigns those products to a configurable "Sales" category
   - Removes products from that category that are no longer discounted
2. A **plugin configuration** where the admin selects the target "Sales" category.
3. An **initial setup command** that creates the "Sales" category if it does not exist.
4. A **README note** that an event-based (real-time) sync can be built later.

All logic uses raw DBAL queries against Prems tables — no runtime dependency on Prems PHP classes.

## 3. Project Environment Details
```yaml
Project Name: SW6.7 Plugin — TopdataDiscountSalesCategorySW6
Namespace: Topdata\TopdataDiscountSalesCategorySW6
Backend root: src
PHP Version: 8.2 / 8.3 / 8.4
Framework: Shopware 6.7.*
Key Dependencies: Doctrine DBAL Connection, EntityRepository (product, category), SystemConfigService
External Data Sources: 
  - prems_discount_category_base (Prems table)
  - product_stream_mapping (Shopware core table)
Pattern: CLI-driven, Service-Oriented (SOLID)
```

---

## Phase 1: Clean Up Skeleton Boilerplate

Delete the example files that came with the plugin skeleton.

### Delete Boilerplate Files

| Action | File |
|--------|------|
| DELETE | `src/Controller/AdminApiExampleController.php` |
| DELETE | `src/Controller/StorefrontExampleController.php` |
| DELETE | `src/Command/ExampleCommand.php` |
| DELETE | `src/Resources/config/routes.xml` |
| DELETE | `src/Resources/views/storefront/example.html.twig` |
| DELETE | `src/Resources/views` directory (if empty after removal) |

---

## Phase 2: Configuration Layer

### `src/Resources/config/config.xml` [MODIFY]

Replace the placeholder config with a category single-select and a toggle for auto-creating the Sales category.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/platform/trunk/src/Core/System/SystemConfig/Schema/config.xsd">
    <card>
        <title>Sales Category Configuration</title>
        <title lang="de-DE">Sales-Kategorie Konfiguration</title>

        <input-field type="entity-single-select">
            <name>salesCategoryId</name>
            <entity>category</entity>
            <label>Sales Category</label>
            <label lang="de-DE">Sales-Kategorie</label>
            <helpText>Products with active discounts will be assigned to this category.</helpText>
            <helpText lang="de-DE">Produkte mit aktiven Rabatten werden dieser Kategorie zugewiesen.</helpText>
        </input-field>

        <input-field type="bool">
            <name>autoCreateCategory</name>
            <label>Auto-Create "Sales" Category</label>
            <label lang="de-DE">"Sales"-Kategorie automatisch anlegen</label>
            <helpText>If enabled and no category is selected, a "Sales" category will be created under the root on first sync.</helpText>
            <helpText lang="de-DE">Wenn aktiviert und keine Kategorie ausgewählt ist, wird beim ersten Sync eine "Sales"-Kategorie unter dem Root angelegt.</helpText>
            <defaultValue>true</defaultValue>
        </input-field>
    </card>
</config>
```

### `src/Service/ConfigurationService.php` [NEW FILE]

```php
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
```

---

## Phase 3: Service Layer

### `src/Service/DiscountProductResolver.php` [NEW FILE]

This service queries the Prems discount tables and Shopware's `product_stream_mapping` to find all products that currently have an active discount.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataDiscountSalesCategorySW6\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

class DiscountProductResolver
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Returns all product IDs that currently have an active discount
     * according to the PremsDiscountCategory6 plugin.
     *
     * @return string[] hex-encoded UUIDs
     */
    public function getDiscountedProductIds(): array
    {
        $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        // Step 1: Get all active discount campaign stream IDs
        $streamIds = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(d.discount_product_stream_id))
             FROM prems_discount_category_base d
             WHERE d.active = 1
               AND (d.valid_from IS NULL OR d.valid_from <= :now)
               AND (d.valid_until IS NULL OR d.valid_until >= :now)
               AND d.discount_product_stream_id IS NOT NULL',
            ['now' => $now],
            ['now' => ParameterType::STRING]
        );

        if (empty($streamIds)) {
            return [];
        }

        // Step 2: Get all product IDs in those streams
        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(m.product_id))
             FROM product_stream_mapping m
             WHERE m.product_stream_id IN (:streamIds)
               AND m.product_version_id = :versionId',
            [
                'streamIds' => Uuid::fromHexToBytesList($streamIds),
                'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ],
            [
                'streamIds' => ArrayParameterType::STRING,
                'versionId' => ParameterType::STRING,
            ]
        );
    }
}
```

### `src/Service/SalesCategoryManager.php` [NEW FILE]

This service handles assigning products to the Sales category and removing products that no longer belong there. It also handles creating the Sales category if needed.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataDiscountSalesCategorySW6\Service;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class SalesCategoryManager
{
    public const CATEGORY_NAME = 'Sales';
    public const CATEGORY_NAME_DE = 'Sales';

    public function __construct(
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $productCategoryRepository,
        private readonly ConfigurationService $configService
    ) {
    }

    /**
     * Ensures the Sales category exists. If configured, creates it automatically.
     * Returns the category ID to use.
     */
    public function ensureSalesCategoryExists(Context $context): ?string
    {
        $categoryId = $this->configService->getSalesCategoryId();
        if ($categoryId) {
            // Verify the configured category still exists
            $exists = $this->categoryRepository->search(new Criteria([$categoryId]), $context)->first();
            if ($exists) {
                return $categoryId;
            }
        }

        if (!$this->configService->isAutoCreateCategoryEnabled()) {
            return null;
        }

        // Try to find existing "Sales" category by name
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::CATEGORY_NAME));
        $existing = $this->categoryRepository->search($criteria, $context)->first();
        if ($existing) {
            return $existing->getId();
        }

        // Create a new "Sales" category under the root
        $newId = Uuid::randomHex();
        $this->categoryRepository->create([[
            'id' => $newId,
            'name' => self::CATEGORY_NAME,
            'parentId' => null, // root level
            'active' => true,
            'visible' => true,
        ]], $context);

        return $newId;
    }

    /**
     * Assigns the given product IDs to the Sales category by writing directly
     * to the product_category mapping table. This preserves all existing category
     * assignments — it only adds the Sales category, nothing else is touched.
     */
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

    /**
     * Removes the given product IDs from the Sales category by deleting rows
     * from the product_category mapping table. Only the Sales category link is
     * removed — all other category assignments remain untouched.
     */
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
     * Gets all product IDs currently assigned to the Sales category.
     *
     * @return string[] hex-encoded UUIDs
     */
    public function getProductIdsInCategory(string $categoryId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('categories.id', $categoryId));
        return $this->productRepository->searchIds($criteria, $context)->getIds();
    }
}
```

---

## Phase 4: CLI Command

### `src/Command/SyncSalesCategoryCommand.php` [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataDiscountSalesCategorySW6\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataDiscountSalesCategorySW6\Service\ConfigurationService;
use Topdata\TopdataDiscountSalesCategorySW6\Service\DiscountProductResolver;
use Topdata\TopdataDiscountSalesCategorySW6\Service\SalesCategoryManager;

#[AsCommand(
    name: 'topdata:discount-sales:sync',
    description: 'Syncs discounted products into the Sales category.'
)]
class SyncSalesCategoryCommand extends Command
{
    public function __construct(
        private readonly ConfigurationService $configService,
        private readonly DiscountProductResolver $discountProductResolver,
        private readonly SalesCategoryManager $salesCategoryManager,
        private readonly EntityRepository $productRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be done without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $context = Context::createDefaultContext();

        // ---- Step 1: Ensure Sales category exists ----
        $output->writeln('<info>Ensuring Sales category exists...</info>');
        $categoryId = $this->salesCategoryManager->ensureSalesCategoryExists($context);

        if ($categoryId === null) {
            $output->writeln('<error>No Sales category configured and auto-create is disabled. Please configure the plugin first.</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('  Using category ID: <comment>%s</comment>', $categoryId));

        // ---- Step 2: Find currently discounted products ----
        $output->writeln('<info>Querying products with active discounts...</info>');
        $discountedProductIds = $this->discountProductResolver->getDiscountedProductIds();
        $output->writeln(sprintf('  Found <comment>%d</comment> discounted products.', count($discountedProductIds)));

        // ---- Step 3: Find products currently in the Sales category ----
        $currentlyAssignedIds = $this->salesCategoryManager->getProductIdsInCategory($categoryId, $context);
        $output->writeln(sprintf('  Currently <comment>%d</comment> products in Sales category.', count($currentlyAssignedIds)));

        // ---- Step 4: Compute diff ----
        $discountedSet = array_flip($discountedProductIds);
        $currentSet = array_flip($currentlyAssignedIds);

        $toAdd = [];
        foreach ($discountedProductIds as $id) {
            if (!isset($currentSet[$id])) {
                $toAdd[] = $id;
            }
        }

        $toRemove = [];
        foreach ($currentlyAssignedIds as $id) {
            if (!isset($discountedSet[$id])) {
                $toRemove[] = $id;
            }
        }

        // ---- Step 5: Report ----
        $output->writeln(sprintf('  To add: <comment>%d</comment> products', count($toAdd)));
        $output->writeln(sprintf('  To remove: <comment>%d</comment> products', count($toRemove)));

        if ($dryRun) {
            $output->writeln('<info>Dry-run mode — no changes were made.</info>');
            return Command::SUCCESS;
        }

        // ---- Step 6: Apply ----
        if (!empty($toAdd)) {
            $output->writeln('<info>Adding products to Sales category...</info>');
            $this->salesCategoryManager->assignProductsToSalesCategory($toAdd, $categoryId, $context);
        }

        if (!empty($toRemove)) {
            $output->writeln('<info>Removing products from Sales category...</info>');
            $this->salesCategoryManager->removeProductsFromSalesCategory($toRemove, $categoryId, $context);
        }

        $output->writeln('<info>Sync complete.</info>');
        return Command::SUCCESS;
    }
}
```

---

## Phase 5: Service Registration

### `src/Resources/config/services.xml` [MODIFY]

Replace the skeleton services with the real service wiring.

```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <!-- Services -->
        <service id="Topdata\TopdataDiscountSalesCategorySW6\Service\ConfigurationService">
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
        </service>

        <service id="Topdata\TopdataDiscountSalesCategorySW6\Service\DiscountProductResolver">
            <argument type="service" id="Doctrine\DBAL\Connection"/>
        </service>

        <service id="Topdata\TopdataDiscountSalesCategorySW6\Service\SalesCategoryManager">
            <argument type="service" id="category.repository"/>
            <argument type="service" id="product.repository"/>
            <argument type="service" id="product_category.repository"/>
            <argument type="service" id="Topdata\TopdataDiscountSalesCategorySW6\Service\ConfigurationService"/>
        </service>

        <!-- Commands -->
        <service id="Topdata\TopdataDiscountSalesCategorySW6\Command\SyncSalesCategoryCommand">
            <argument type="service" id="Topdata\TopdataDiscountSalesCategorySW6\Service\ConfigurationService"/>
            <argument type="service" id="Topdata\TopdataDiscountSalesCategorySW6\Service\DiscountProductResolver"/>
            <argument type="service" id="Topdata\TopdataDiscountSalesCategorySW6\Service\SalesCategoryManager"/>
            <argument type="service" id="product.repository"/>
            <tag name="console.command"/>
        </service>
    </services>
</container>
```

---

## Phase 6: Documentation

### `README.md` [MODIFY]

```markdown
# Topdata Discount Sales Category SW6

![Plugin Icon](src/Resources/config/plugin.png)

## Overview

This plugin automatically assigns products with active discounts (managed by the
**PremsDiscountCategory6** plugin) to a configurable "Sales" category in the storefront.

Every product that has an active discount campaign (valid date range, active flag)
will appear in the Sales category. When a discount expires or is deactivated,
the product is removed from the Sales category on the next sync.

## Installation

1. Download the plugin.
2. Place it in `custom/plugins/topdata-discount-sales-category-sw6/`.
3. Install and activate via Plugin Manager or CLI:
   ```bash
   bin/console plugin:install --activate --clearCache TopdataDiscountSalesCategorySW6
   ```
4. **Configuration:** Navigate to the plugin configuration and select your Sales category
   (or enable auto-creation).

## CLI Usage

Run the sync command to assign discounted products to the Sales category:

```bash
# Full sync (adds discounted products, removes expired ones)
bin/console topdata:discount-sales:sync

# Dry-run to preview changes
bin/console topdata:discount-sales:sync --dry-run
```

> **Tip:** Set up a cron job (e.g., every hour) to keep the Sales category up to date
> as discounts expire or new ones are created:
> ```
> * * * * * cd /path/to/shopware && bin/console topdata:discount-sales:sync
> ```

## Requirements

- Shopware 6.7.*
- PremsDiscountCategory6 plugin (must be installed and active)

## Future Improvements

- **Event-based sync:** Currently, the sync is CLI-only. An event-driven approach
  (listening to `prems_discount_category_base.written` and
  `product_stream_mapping.written`/`deleted`) could be added in a future version
  to provide real-time updates without a cron job.
- **Sales channel filtering:** Respect the Prems discount campaign's
  `discount_sales_channel_ids` restriction when assigning to the Sales category.
- **Plugin configuration UI:** More granular control over which discount campaigns
  contribute to the Sales category.

## License

MIT
```

---

## Phase 7: Implementation Report

After implementing, write a report to `_ai/backlog/reports/{YYMMDD_HHmm}__IMPLEMENTATION_REPORT__discount-sales-category.md`.

```yaml
---
filename: "_ai/backlog/reports/{YYMMDD_HHmm}__IMPLEMENTATION_REPORT__discount-sales-category.md"
title: "Report: Implementation Plan: Discount Sales Category SW6"
createdAt: YYYY-MM-DD HH:mm
updatedAt: YYYY-MM-DD HH:mm
planFile: "_ai/backlog/active/{YYMMDD_HHmm}__IMPLEMENTATION_PLAN__discount-sales-category.md"
project: "TopdataDiscountSalesCategorySW6"
status: completed
filesCreated: 3
filesModified: 4
filesDeleted: 6
tags: [shopware6, plugin, discounts, categories, prems, cli]
documentType: IMPLEMENTATION_REPORT
---
```
