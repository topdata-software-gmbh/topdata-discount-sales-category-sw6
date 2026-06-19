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
