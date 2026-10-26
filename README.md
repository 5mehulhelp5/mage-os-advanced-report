# MageOS_AdvancedReport

Advanced sales, catalog and customer reporting for Magento Open Source / Mage-OS. It adds an admin
exploration app (Reports > Advanced Report) on top of a declarative semantic layer: datasets, fields,
metrics and dimensions are declared in XML and translated to SQL by a MySQL data source.

## Purpose

- Predefined reports (sales overview, sales by payment/shipping method/country/customer group/hour/weekday,
  new vs returning customers, coupon performance, product performance, sales by category/attribute,
  customer value, new customer registrations, stock value, low stock with coverage, dead stock, never sold).
- Free exploration: pick metrics, group by dimensions, filter, compare to the previous period/year,
  chart and table, CSV export.
- Saved reports (private or shared with other admins) stored in `mageos_advancedreport_saved`.
- Everything is computed live from the native Magento tables (no aggregation tables, no cron).

## How it works

Reports are not hard-coded queries. Every report is a combination of a few building blocks declared in
`etc/report_datasets.xml` (the semantic layer); the module turns the combination chosen in the UI into SQL.

### Building blocks

| Concept | What it is | Example |
|---|---|---|
| **Dataset** | A reportable entity with one row per record: its base table (or a base select provider), the ACL resource that protects it, the area it belongs to (`sales`, `catalog`, `customers`) and the implicit filters always applied to it. | `sales_orders` (one row per order), `sales_items` (one row per top-level order item), `customer_orders`, `customers`, `catalog_stock`, `catalog_salable` (MSI only). |
| **Field** | A named, typed SQL expression on the dataset rows (`money`, `integer`, `decimal`, `boolean`, `string`, `id`, `date`). It may need one or more joins. Every field can be used as a filter. | `gross_sales_amount` = subtotal minus canceled subtotal; `billing_country` = billing address country (needs the `billing_address` join). |
| **Join** | A table joined to the dataset, declared once and added to the query only when a selected field needs it. A join can `require` another join. | `payment`, `billing_address`, `store`. |
| **Metric** | A number to measure. Either an **aggregate** (`sum`, `count`, `count_distinct`, `min`, `max`, `avg`) of a field, optionally restricted to the rows where a boolean field is true (`condition`), or a **formula** over other metrics (`+ - * /`, parentheses, numbers). | `orders` = `COUNT(order_id)`; `net_sales` = `gross_sales - discounts - returns`; `aov` = `net_sales / orders`. |
| **Dimension** | A field to group by. Date dimensions support granularities (hour, day, week, month, year); a label resolver turns raw ids/codes into readable labels; `nonAdditive` marks dimensions whose rows do not sum up to the total. | `order_date` by day, `payment_method`, `customer_group_id`, `category_id` (non-additive). |
| **Preset** | A ready-made report: dataset, metrics, dimensions, filters, sort, default date range and comparison. A preset may delegate the whole computation to its own query class (`presetQuery`) for logic that is not a plain aggregation. | `sales_overview`, `product_performance`, `low_stock_coverage`. |

Reserved fields: every dated dataset declares `created_at` (date filtering and date buckets), `store_id` (store
scope) and, when it applies the `counted_status` implicit filter, `status` (counted order statuses). Metrics
flagged `hidden` are helper inputs of formulas and are not offered in the metric picker; metrics flagged `virtual`
have no SQL at all and are produced only by a preset query.

### From a click to a result

1. The exploration UI (a preset, a saved report or a free selection) sends a report request to the `report/data`
   endpoint: dataset, metrics, dimensions with granularity, filters, sort, period, comparison, stores and page.
2. The endpoint checks the ACL resource of the dataset; the request validator checks every code against the
   semantic layer.
3. The query planner resolves the period in the website timezone (and the comparison period), and expands each
   formula into the aggregates it needs, so `aov` alone fetches `gross_sales`, `discounts`, `returns` and `orders`.
4. The result cache is checked (see [Performance notes](#performance-notes)).
5. The source resolver picks a data source able to serve every requested field (MySQL today), or the preset
   query class when the preset declares one.
6. The MySQL data source builds the select: base table, only the joins needed, implicit filters, date and store
   scope, user filters (always as bound parameters), `GROUP BY` the dimensions and the aggregates. It runs the
   page query, a totals query and a row count, under the configured timeout and row cap.
7. The result is post-processed: missing date buckets (and missing hours/weekdays) are filled with empty rows,
   formulas are computed on every row and on the totals, the comparison period is merged with deltas, raw
   dimension values are translated by their label resolvers and warnings are collected.
8. The result is cached and returned to the UI, which renders the chart and the table.

Because formulas are computed after aggregation, ratios such as `aov` or `margin_pct` are always correct on totals
and in comparisons (they are never averages of averages).

## Requirements

- Magento Open Source 2.4.7+ or Mage-OS, PHP 8.3+.
- `Magento_Reports` (hard dependency: the admin menu entry is a child of the Reports menu).
- Optional: MySQL/MariaDB timezone tables (`mysql_tzinfo_to_sql`) for exact hourly reports in non-UTC store
  timezones; without them the module falls back to an UTC-hour aggregation and warns in the diagnostics panel.
- Optional: Magento MSI (`Magento_InventoryApi`) for multi-source stock in the `catalog_stock` dataset; the legacy
  `cataloginventory_stock_item` table is used otherwise.

## Installation

```bash
bin/magento module:enable MageOS_AdvancedReport
bin/magento setup:upgrade
bin/magento cache:flush
```

`setup:upgrade` creates the `mageos_advancedreport_saved` table and adds native indexes required by the reports:
`sales_order (customer_id, created_at)`, `sales_order (customer_email, created_at)` and `sales_order_item (product_id)`.
On a store with a large `sales_order` table, creating these indexes can take a long time and locks
writes on that table: run the upgrade in a maintenance window.

## Configuration reference

Stores > Configuration > Advanced Report > Configuration (default scope only).

| Path | Default | Description |
|---|---|---|
| `mageos_advancedreport/general/enabled` | `1` | Disables the admin menu and the controllers when set to No. |
| `mageos_advancedreport/sales/counted_statuses` | empty | Order statuses counted as sales. Empty means every status except `canceled`. |
| `mageos_advancedreport/catalog/dead_stock_days` | `90` | Days without sales after which in-stock products are flagged as dead stock. |
| `mageos_advancedreport/catalog/report_attributes` | empty | Extra product attributes exposed as `attr_<code>` dimensions of the `sales_items` dataset. |
| `mageos_advancedreport/performance/connection_name` | `default` | Database connection (from `app/etc/env.php`) used to run report queries, e.g. a read replica. |
| `mageos_advancedreport/performance/max_rows` | `10000` | Hard cap on the rows a single query may return; results above the cap are truncated with a warning. |
| `mageos_advancedreport/performance/query_timeout_ms` | `30000` | Maximum execution time of a report query. |
| `mageos_advancedreport/performance/cache_ttl_open_period` | `300` | Cache lifetime (seconds) for periods that can still change. |
| `mageos_advancedreport/performance/cache_ttl_closed_period` | `86400` | Cache lifetime (seconds) for closed periods. The cache type `advanced_report` is cleaned when the configuration section is saved. |

The report view shows a line with the order statuses currently counted (store-scoped labels when a single store is selected) and a link to this configuration.

Weeks start on `general/locale/firstday` of the report scope store. Date filters use the website timezone
(`general/locale/timezone`); amounts are in the base currency of the store. See [time and currency](docs/time-and-currency.md).

## Metric definitions

Amounts are base currency values. `net_sales` is gross sales (excluding tax, net of cancellations) minus discounts
minus returns; credit memo adjustments are exposed separately as `adjustments` and subtracted in `total_sales`.
Derived metrics (formulas) are recomputed from their aggregate inputs, so they stay correct on totals and
comparisons; a division by zero yields an empty value.

Customer identity in the customer datasets is the order email (lowercased, trimmed); guest and registered orders
are merged. Metrics are scoped to the selected period, `days_since_last_order` is relative to today (UTC), and the
waiting time uses the first shipment (unshipped orders are excluded).

### `sales_orders` (Orders)

| Metric | Label | Definition | Notes |
|---|---|---|---|
| `orders` | Orders | `COUNT(order_id)` | Number of orders placed. |
| `gross_sales` | Gross sales | `SUM(gross_sales_amount)` | Order subtotal excluding tax, net of cancellations. |
| `discounts` | Discounts | `SUM(discount_amount)` | Discount amount applied, net of cancellations. A lower value is an improvement. |
| `returns` | Returns | `SUM(returns_amount)` | Subtotal and discount refunded via credit memos, net of refunded discount. Limitation: returns are attributed to the original order date, not the credit memo date. |
| `net_sales` | Net sales | `gross_sales - discounts - returns` | Gross sales minus discounts minus returns. |
| `shipping` | Shipping | `SUM(shipping_amount)` | Shipping amount charged, net of cancellations and refunds. |
| `taxes` | Taxes | `SUM(tax_amount)` | Tax amount charged, net of cancellations and refunds. |
| `adjustments` | Adjustments | `SUM(adjustment_amount)` | Credit memo adjustment fees: extra refunded amount minus refund fees. |
| `total_sales` | Total sales | `net_sales + shipping + taxes - adjustments` | Net sales plus shipping plus taxes, minus credit memo adjustments. |
| `aov` | Average order value | `net_sales / orders` | Average order value: net sales divided by the number of orders. |
| `units` | Units | `SUM(units_qty)` | Total quantity of items ordered, net of canceled quantities (parent items). |
| `new_customer_orders` | New customer orders | `SUM(is_first_order)` | Orders placed by a customer without a prior order. Limitation: a guest checkout later followed by a registered account for the same person is counted as a new customer twice. |
| `returning_customer_orders` | Returning customer orders | `orders - new_customer_orders` | Orders minus new customer orders. |

### `customer_orders` (Customer orders)

| Metric | Label | Definition | Notes |
|---|---|---|---|
| `orders` | Orders | `COUNT(order_id)` | Number of orders placed. |
| `unique_customers` | Unique customers | `COUNT DISTINCT(customer_key)` | Number of distinct customers (by lowercased order email) who placed orders. |
| `gross_sales` | Gross sales | `SUM(gross_sales_amount)` | Helper metric for total spent: order subtotal, net of cancellations. |
| `discounts` | Discounts | `SUM(discount_amount)` | Helper metric for total spent: discount amount applied, net of cancellations. |
| `returns` | Returns | `SUM(returns_amount)` | Helper metric for total spent: subtotal and discount refunded via credit memos. |
| `shipping` | Shipping | `SUM(shipping_amount)` | Helper metric for total spent: shipping amount charged, net of cancellations and refunds. |
| `taxes` | Taxes | `SUM(tax_amount)` | Helper metric for total spent: tax amount charged, net of cancellations and refunds. |
| `adjustments` | Adjustments | `SUM(adjustment_amount)` | Helper metric for total spent: credit memo adjustment fees. |
| `total_spent` | Total spent | `gross_sales - discounts - returns + shipping + taxes - adjustments` | Net sales plus shipping plus taxes, minus credit memo adjustments. |
| `avg_spent` | Average spent per order | `(gross_sales - discounts - returns + shipping + taxes - adjustments) / orders` | Total spent divided by the number of orders. |
| `avg_shipping_cost` | Average shipping cost | `shipping / orders` | Shipping amount divided by the number of orders. |
| `days_since_last_order` | Days since last order | `MIN(days_since_order)` | Days since the most recent order, measured from today, considering only orders in the selected period. A lower value is an improvement. |
| `avg_wait_days` | Average wait days | `AVG(wait_days)` | Average days between the order and its first shipment. Unshipped orders are excluded; orders with multiple shipments use the first shipment. A lower value is an improvement. |

### `customers` (Customers)

| Metric | Label | Definition | Notes |
|---|---|---|---|
| `registrations` | Registrations | `COUNT(customer_id)` | Number of customer accounts created. Limitation: accounts created by an admin or whose store was deleted are excluded when specific stores are selected. |

### `sales_items` (Order items)

| Metric | Label | Definition | Notes |
|---|---|---|---|
| `units_sold` | Units sold | `SUM(qty_net)` | Quantity ordered, net of cancellations. |
| `units_refunded` | Units refunded | `SUM(qty_refunded_qty)` | Quantity refunded via credit memos. A lower value is an improvement. |
| `units_shipped` | Units shipped | `SUM(qty_shipped_qty)` | Quantity shipped. Limitation: shipped units are attributed to the original order date, not the shipment date; bundle products shipped separately are not counted. |
| `units_to_ship` | Units to ship | `SUM(qty_to_ship_qty)` | Quantity ordered and not yet shipped, refunded or canceled (virtual items and bundle products shipped separately excluded). A lower value is an improvement. Limitation: units are attributed to the original order date, so only orders placed in the selected period are included. |
| `gross_sales` | Gross sales | `SUM(gross_sales_amount)` | Row total, excluding tax. Configurable products are counted as a single line (bundle = single line). |
| `discounts` | Discounts | `SUM(discount_amount)` | Discount amount applied to the item. A lower value is an improvement. |
| `returns` | Returns | `SUM(returns_amount)` | Amount refunded via credit memos, net of refunded discount. Limitation: returns are attributed to the original order date, not the credit memo date. |
| `net_sales` | Net sales | `gross_sales - discounts - returns` | Gross sales minus discounts minus returns. |
| `cost` | Cost | `SUM(cost_amount)` where `has_cost` | Unit cost multiplied by units sold, only over rows with a known cost. |
| `gross_margin` | Gross margin | `net_sales - cost` | Net sales minus cost. |
| `margin_pct` | Margin % | `gross_margin / net_sales` | Gross margin as a percentage of net sales. |
| `return_rate` | Return rate | `returns / gross_sales` | Returns as a percentage of gross sales. A lower value is an improvement. |
| `orders` | Orders | `COUNT DISTINCT(order_id)` | Number of distinct orders contributing item rows. |
| `shipments` | Shipments | `SUM(shipment_count)` | Number of shipments containing the product. Limitation: shipments are attributed to the original order date, not the shipment date. |
| `credit_memos` | Credit memos | `SUM(credit_memo_count)` | Number of credit memos containing the product, excluding canceled credit memos. A lower value is an improvement. Limitation: credit memos are attributed to the original order date, not the credit memo date. |
| `has_cost_rows` | Rows with cost | `SUM(has_cost)` | Helper metric for cost_coverage: number of item rows with a known unit cost. |
| `rows` | Rows | `COUNT(item_id)` | Helper metric for cost_coverage: total number of item rows. |
| `cost_coverage` | Cost coverage | `has_cost_rows / rows` | Share of item rows with a known unit cost. |

Note: a product repeated on two separate lines of the same order is counted once per line for the same document. Canceled credit memos are excluded, while open (pending refund) credit memos are counted. Adjustment-only credit memos (no item with qty > 0) are not counted.

### `catalog_stock` (Stock)

| Metric | Label | Definition | Notes |
|---|---|---|---|
| `qty_on_hand` | Qty on hand | `SUM(qty)` | Total quantity on hand across enabled sources. Source items of disabled sources (`inventory_source.enabled = 0`) are excluded; items with status 0 (out of stock at the source) still count their physical quantity. |
| `stock_value_cost` | Stock value (cost) | `SUM(value_cost)` | Inventory value at cost: physical quantity times the global (store 0) `cost` attribute; products without a cost count as 0. |
| `stock_value_retail` | Stock value (retail) | `SUM(value_retail)` | Inventory value at retail price: physical quantity times the global (store 0) `price` attribute. |
| `skus_in_stock` | SKUs in stock | `COUNT DISTINCT(product_id)` where `in_stock` | Number of distinct SKUs with a quantity on hand greater than zero. |
| `units_sold_30d` | Units sold (30 days) | Produced by a preset query | Units sold over the last 30 days. |
| `avg_daily_sales` | Avg daily sales | Produced by a preset query | Units sold over the last 30 days divided by 30. |
| `coverage_days` | Coverage (days) | Produced by a preset query | Days the current stock lasts at the average daily sales; empty when there are no sales. |
| `days_since_last_sale` | Days since last sale | Produced by a preset query | Days since the last counted sale; empty when the product was never sold. |

### `catalog_salable` (Salable stock)

MSI only: the dataset (and any preset on it) is dropped from the registry when `Magento_InventoryApi`, `Magento_InventoryIndexer`, `Magento_InventoryReservations` or `Magento_InventoryCatalog` is disabled. One row per SKU and stock; configurable, bundle and grouped products are excluded.

| Metric | Label | Definition | Notes |
|---|---|---|---|
| `stock_qty` | Stock quantity | `SUM(stock_qty)` | Quantity in the stock index, before pending reservations. |
| `reserved_qty` | Reserved quantity | `SUM(reserved_qty)` | Quantity reserved by pending orders (positive when reserved). |
| `salable_qty` | Salable quantity | `SUM(salable_qty)` | Stock quantity minus reserved quantity minus the minimum quantity, or 0 when the stock index marks the SKU not salable; can be negative like in core. "Available" means salable in this module. |
| `skus_salable` | SKUs salable | `COUNT DISTINCT(product_id)` where `salable_qty > 0` | Number of distinct SKUs with a salable quantity greater than zero. |

### Customer dimensions

Five customer dimensions are available on `sales_orders`, `customer_orders` and `customers`.

| Dimension | Label | Source in `sales_orders` / `customer_orders` (order snapshot) | Source in `customers` (account) | Label resolver |
|---|---|---|---|---|
| `billing_postcode` | Billing zipcode | Order billing address postcode (trimmed, uppercased) | Default billing address postcode (trimmed, uppercased) | None (raw value) |
| `birth_year` | Year of birth | Year of the order `customer_dob` | Year of the account `dob` | None (raw value) |
| `birth_month` | Month of birth | Month of the order `customer_dob` | Month of the account `dob` | `MonthLabelResolver` |
| `birth_weekday` | Day of the week of birth | Weekday of the order `customer_dob` (0 = Sunday) | Weekday of the account `dob` (0 = Sunday) | `WeekdayLabelResolver` |
| `gender` | Gender | Order `customer_gender` | Account `gender` | `GenderLabelResolver` |

- Order datasets use the values stored on the order at checkout, not the current account values.
- Orders placed by guests have no date of birth or gender, so month of birth, weekday of birth and gender show
  "(none)", while year of birth and zipcode (no label resolver) show an empty cell.
- Dates of birth are used as stored, without timezone conversion.
- The five fields are also available in the filter picker (every dataset field is filterable). The gender filter
  takes raw option ids (1 = Male, 2 = Female, 3 = Not Specified by default).
- Zipcode and birth values are personal data: users with `MageOS_AdvancedReport::sales` can group and filter by
  them on the Orders report.

## Extending the semantic layer

Any module can add fields, joins, metrics, dimensions, presets or whole new datasets without touching this module.
The reports, the pickers, the filters, the CSV export, the cache and `advanced-report:explain` pick up the new
definitions automatically.

### How the merge works

Every module may ship its own `etc/report_datasets.xml`. Magento reads them all and merges them by `code`:

- a `<dataset code="sales_orders">` in your file is the **same** dataset as the core one, so you only declare the
  elements you add (no need to repeat `label`, `acl`, `area` or `<source>`);
- an element with an existing `code` (e.g. `<metric code="aov">`) **overrides** the attributes you set on it;
- an element with a new `code` is **added**.

The per-file schema (`etc/report_datasets_file.xsd`) only requires the `code` attributes; the merged result is
validated against `etc/report_datasets.xsd` and by `DefinitionValidator`. In developer mode an invalid definition
throws an exception listing the errors; in production it is dropped and logged (an invalid field or metric
drops only that element, an invalid dataset drops the dataset and its presets), so a broken extension never takes
down the other reports.

### Step by step

The example adds a "sales channel" to the `sales_orders` dataset, assuming your module `Acme_Channel` added a
column `sales_order.acme_channel_id` that points to a table `acme_channel (channel_id, name, fee_rate)`.

**1. Depend on this module** so your file is merged after the core one, in `Acme/Channel/etc/module.xml`:

```xml
<module name="Acme_Channel">
    <sequence>
        <module name="MageOS_AdvancedReport"/>
    </sequence>
</module>
```

**2. Declare the definitions** in `Acme/Channel/etc/report_datasets.xml` (no schema location is needed):

```xml
<?xml version="1.0"?>
<config>
    <dataset code="sales_orders">
        <!-- Join: table, alias, type (inner|left) and the ON condition. "main" is the dataset base alias.
             Added to the query only when a selected field lists it in "joins". -->
        <join code="acme_channel" table="acme_channel" alias="acme_channel" type="left"
              condition="acme_channel.channel_id = main.acme_channel_id"/>

        <!-- Fields: a typed SQL expression for the mysql source. Every field is also a filter. -->
        <field code="acme_channel_id" label="Sales channel" type="id">
            <mysql expression="main.acme_channel_id"/>
        </field>
        <field code="acme_channel_fee" label="Channel fee" type="money"
               description="Marketplace fee charged on the order.">
            <mysql expression="(COALESCE(main.base_subtotal, 0) * COALESCE(acme_channel.fee_rate, 0))"
                   joins="acme_channel"/>
        </field>
        <field code="is_marketplace" label="Is marketplace order" type="boolean">
            <mysql expression="(main.acme_channel_id IS NOT NULL)"/>
        </field>

        <!-- Metrics: an aggregate over a field (never SQL), optionally only where a boolean field is true... -->
        <metric code="channel_fees" label="Channel fees" format="money" aggregate="sum"
                field="acme_channel_fee" lowerIsBetter="true"
                description="Marketplace fees charged on the orders."/>
        <metric code="marketplace_orders" label="Marketplace orders" format="integer" aggregate="count"
                field="order_id" condition="is_marketplace"/>
        <!-- ...or a formula over other metric codes, including the core ones. -->
        <metric code="net_sales_after_fees" label="Net sales after fees" format="money"
                formula="net_sales - channel_fees"
                description="Net sales minus marketplace fees."/>
        <metric code="marketplace_share" label="Marketplace share" format="percent"
                formula="marketplace_orders / orders"/>

        <!-- Dimension: group by a field; the label resolver turns ids into names. -->
        <dimension code="acme_channel" label="Sales channel" field="acme_channel_id"
                   labelResolver="Acme\Channel\Model\Label\ChannelLabelResolver"/>
    </dataset>

    <!-- Preset: a ready-made report listed in the report page. -->
    <preset code="acme_sales_by_channel" area="sales" dataset="sales_orders" label="Sales by channel"
            description="Orders, net sales and marketplace fees for each sales channel."
            dateRange="last_30_days" compareTo="previous_period" sources="mysql">
        <metric code="orders"/>
        <metric code="net_sales"/>
        <metric code="channel_fees"/>
        <metric code="net_sales_after_fees"/>
        <dimension code="acme_channel"/>
        <filter field="is_marketplace" operator="eq" value="1"/>
        <sort by="net_sales" direction="desc"/>
    </preset>
</config>
```

Attribute reference:

| Element | Attributes |
|---|---|
| `dataset` | `code`; for a new dataset also `label`, `acl` (ACL resource checked by the data endpoint), `area` (`sales`, `catalog`, `customers`), optional `dateless="true"` for snapshot datasets without `created_at`. Children: `<source name="mysql" table="..." alias="main"/>` (or `provider="..."`), `<implicitFilter code="counted_status"/>`, joins, fields, metrics, dimensions. |
| `join` | `code`, `table`, `alias`, `condition`, optional `type` (`left` default, `inner`) and `requires` (comma-separated join codes this join depends on) and `cardinality` (`one` default, `many` for one-to-many joins that can multiply rows and inflate aggregates). |
| `field` | `code`, `label`, `type` (`money`, `integer`, `decimal`, `boolean`, `string`, `id`, `date`), optional `description`. Child `<mysql expression="..." joins="a,b"/>` or `<mysql expressionProvider="name"/>`. |
| `metric` | `code`, `label`, `format` (`money`, `number`, `percent`, `integer`), `description`, `lowerIsBetter` (colours the comparison delta), `hidden` (formula helper not shown in the picker). Either `aggregate` (`sum`, `count`, `count_distinct`, `min`, `max`, `avg`) + `field` + optional `condition` (a boolean field), or `formula`, or `virtual="true"` (value supplied by a preset query). |
| `dimension` | `code`, `label`, `field`, optional `labelResolver` (class name), `granularities` (for date fields: `hour,day,week,month,year`), `nonAdditive="true"` (rows may not sum up to the total; a warning is shown). |
| `preset` | `code`, `area`, `dataset`, `label`, optional `description`, `dateRange` (`today`, `last_7_days`, `last_30_days`, `month_to_date`, `last_month`, `year_to_date`), `compareTo` (`none`, `previous_period`, `previous_year`), `sources`, `presetQuery`, `alternateDimension`. Children: `<metric code=""/>`, `<dimension code="" granularity=""/>`, `<filter field="" operator="eq\|neq\|in\|nin\|gte\|lte\|between" value=""/>`, `<sort by="" direction="asc\|desc"/>`. |

**3. Add a label resolver** when the dimension stores ids or codes. Extend
`MageOS\AdvancedReport\Model\Label\AbstractLabelResolver` (it labels empty values "(none)" and keeps unknown values
raw) or implement `MageOS\AdvancedReport\Api\LabelResolverInterface` directly. It receives all the values of the
result at once: load them with a single query.

```php
<?php

declare(strict_types=1);

namespace Acme\Channel\Model\Label;

use Magento\Framework\App\ResourceConnection;
use MageOS\AdvancedReport\Model\Label\AbstractLabelResolver;

class ChannelLabelResolver extends AbstractLabelResolver
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    protected function loadLabels(array $values, array $context): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('acme_channel'), ['channel_id', 'name'])
            ->where('channel_id IN (?)', $values);

        return $connection->fetchPairs($select);
    }
}
```

Register it in the resolver pool, keyed by its class name, in `Acme/Channel/etc/di.xml`:

```xml
<type name="MageOS\AdvancedReport\Model\Label\LabelResolverPool">
    <arguments>
        <argument name="resolvers" xsi:type="array">
            <item name="Acme\Channel\Model\Label\ChannelLabelResolver" xsi:type="object">Acme\Channel\Model\Label\ChannelLabelResolver</item>
        </argument>
    </arguments>
</type>
```

**4. Translate** every `label` and `description` in your module `i18n/*.csv` files: they are rendered through `__()`.

**5. Refresh and verify**:

```bash
bin/magento cache:clean config advanced_report
bin/magento advanced-report:explain --preset=acme_sales_by_channel --from=2026-01-01 --to=2026-01-31
```

The definitions are cached in the `config` cache type; the cache key of the results includes a hash of the
definitions, so cached results of the old definitions are never served. Check that the `EXPLAIN` output of your
preset uses indexes on large tables (see [Performance notes](#performance-notes)).

### Advanced extension points

When XML is not enough, register a class in `di.xml`:

| Need | Implement | Register in |
|---|---|---|
| A field whose SQL depends on runtime state (table prefixes, correlated subqueries, configuration) | `MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionProviderInterface`, referenced by `<mysql expressionProvider="name"/>` | `FieldExpressionResolver` argument `providers`, keyed by `name` (see `FirstShipmentWaitExpression`) |
| A dataset whose base is not a single table (e.g. a union or a derived select) | `MageOS\AdvancedReport\Model\DataSource\Mysql\BaseSelectProviderInterface`, referenced by `<source name="mysql" provider="Class"/>` | `SelectBuilder` argument `baseSelectProviders`, keyed by class name (see `StockSelectProvider`) |
| A report that is not a single aggregation (anti-joins, rolling windows, two-step queries) | `MageOS\AdvancedReport\Api\PresetQueryInterface`, referenced by `<preset presetQuery="Class">`; may fill `virtual` metrics | `PresetQueryPool` argument `presetQueries`, keyed by class name (see `LowStockCoverageQuery`) |
| Definitions generated from data or configuration (e.g. one dimension per product attribute) | `MageOS\AdvancedReport\Model\Definition\DatasetExtenderInterface` or `PresetExtenderInterface` | `DefinitionRegistry` arguments `datasetExtenders` / `presetExtenders` (see `AttributeJoinProvider`) |

### Rules

- Metrics never contain SQL: SQL lives only in field expressions and joins, which come from XML or PHP, never from
  user input. All user values (filters, dates, stores) are bound as query parameters.
- `created_at`, `store_id` and `status` are reserved field codes: they drive date filtering and buckets, the store
  scope and the counted order statuses. A new dated dataset must declare `created_at` and `store_id`.
- Use `main.` (or the alias of your `<source>`) and the join aliases in expressions; always wrap nullable columns in
  `COALESCE` so sums and formulas do not turn into `NULL`.
- `count_distinct` and `avg` metrics cannot be re-aggregated: in a non-UTC store timezone without database timezone
  tables they come back empty when grouped by order date (see [Known limits](#known-limits)).
- Prefix your codes (e.g. `acme_`) to avoid clashes with future core definitions.

## `advanced-report:explain`

Prints, for one preset or all presets, the generated SQL and its `EXPLAIN` output, to check index usage on real data.

```bash
bin/magento advanced-report:explain --from=2026-01-01 --to=2026-03-31
bin/magento advanced-report:explain --preset=sales_overview --from=2026-01-01 --to=2026-01-31
```

`--from` and `--to` (Y-m-d, `from <= to`) are required, `--preset` is optional (default: all presets).

Every statement of a request is explained: `page`, `row_count` (only run when the page is full), `totals`, `comparison_page`,
`comparison_totals`, `currency_probe`, the timezone `fallback` variant and the preset's own query. Further options:

- `--compare=previous_period|previous_year`: include the comparison statements (default: the preset's own comparison).
- `--analyze`: use `EXPLAIN ANALYZE`. **This executes every statement** (bounded by the configured query timeout).
- `--format=table|json`: `json` prints a list of `{preset, statement, sql, plan}` entries.

## `advanced-report:benchmark`

Read-only benchmark of the report scenarios (it only runs the report queries; it never writes data). Each scenario is run
`--repeat` times cold (cache bypassed) and `--repeat` times warm; the output lists cold median and p95, SQL statements, rows
examined, warm median and statements (0 when served from cache) and peak memory. The default matrix covers the dated presets
(`sales_overview`, `new_vs_returning`, `product_performance`, `customer_value`) over 30 days and 12 months, all stores and one
store, with and without comparison, the dateless presets (`stock_value`, `low_stock_coverage`, `dead_stock`) and a few
expensive scenarios.

```bash
bin/magento advanced-report:benchmark --profile-label=medium --repeat=3 --format=markdown
bin/magento advanced-report:benchmark --preset=sales_overview --range=last_30_days --budget-ms=3000
bin/magento advanced-report:benchmark --preset=sales_overview --range=last_30_days --store=0 --compare=none --concurrency=8
```

| Option | Meaning |
| --- | --- |
| `--preset=CODE` | Preset, repeatable (default: the full matrix) |
| `--range=last_30_days\|last_12_months\|custom` | Date range; `custom` needs `--from` and `--to` (Y-m-d) |
| `--store=ID` | Store id, repeatable; `0` = all stores (cannot be combined with other ids). Default: the matrix stores |
| `--compare=none\|previous_period\|previous_year` | Comparison override |
| `--repeat=N` | Cold and warm runs per scenario (default 3) |
| `--format=table\|json\|markdown` | Output format (default `table`) |
| `--output=FILE` | Write the output to a file relative to `var/` |
| `--budget-ms=N` | Scenarios whose cold median exceeds N ms are listed and the exit code is 1 |
| `--profile-label=NAME` | Label of the data profile (small, medium, large) shown in the header |
| `--concurrency=N` | Run exactly one scenario in N (1-32) parallel worker processes and report how many executed SQL or were served from cache. **Cleans the whole `advanced_report` cache type.** |

`--store` and `--compare` (and `--range=custom`) override the store, comparison and range part of the scenario code
(`--range=custom` sets the range part to `custom` plus `@from..to`), and scenarios that become identical run once. Without
overrides the matrix is unchanged. With overrides, an expensive scenario identical to a matrix scenario (for example
`new_vs_returning_12m`) is merged into the matrix one (the first one is kept).

`--worker` and `--start-at` are internal options of the concurrency mode. The command runs under a trusted CLI authorization
(no admin session), see [security](docs/security.md#trusted-cli-authorization-benchmark). The seed, procedure, budgets and the
results table are in [docs/performance.md](docs/performance.md).

## `advanced-report:inventory`

Prints the metric inventory: for every metric of every dataset its kind, aggregate, expression, joins, fan-out (row multiplication) risk and context dependencies. Output is deterministic, so regenerating it for unchanged definitions gives an empty diff.

```bash
bin/magento advanced-report:inventory                                  # Markdown, all datasets
bin/magento advanced-report:inventory --format=json --dataset=sales_orders
bin/magento advanced-report:inventory --output=docs/metric-inventory.md
```

`--format` (`md` default, or `json`), `--dataset` (default: all) and `--output` (file path, relative to the current directory, instead of stdout) are optional. Regenerate `docs/metric-inventory.md` whenever definitions change with `bin/magento advanced-report:inventory --output=app/code/MageOS/AdvancedReport/docs/metric-inventory.md` from the Magento root (prefer `--output` over redirecting stdout).

## Performance notes

Reports are live queries (no indexer, no aggregation tables): the cost grows with the rows of the selected period and
pagination does not reduce the database work. Details live in three documents:

- [Performance](docs/performance.md): statements per report shape, safeguards (timeout on every statement, `max_rows`,
  granularity limits, export limits, connection errors), the benchmark procedure (performance toolkit seed,
  `advanced-report:benchmark`, `advanced-report:explain`), budgets, the results table and the known bottlenecks.
- [Cache](docs/cache.md): key components (including the result config), TTLs, invalidation, label staleness, failures, the stampede lock and the freshness per report category.
- [Indexes](docs/indexes.md): rationale of every index, removable indexes and the installation time / maintenance window.

In short:

- **Indexes**: `setup:upgrade` adds `sales_order (customer_id, created_at)`, `sales_order (customer_email, created_at)`,
  `sales_order_item (product_id)` and `sales_order_item (product_id, order_id)`; date filters use the native indexes. Check
  index usage with `advanced-report:explain` on production-size data.
- **Result cache**: the `advanced_report` cache type, TTL-only freshness: periods including today expire after
  `cache_ttl_open_period` (5 minutes), all others (and reports without a date range, such as stock) after
  `cache_ttl_closed_period` (24 hours). `Refresh`, saving the configuration section and `bin/magento cache:clean advanced_report` bypass or empty it.
- **Guardrails**: every report statement carries a timeout (`query_timeout_ms`; on timeout the admin sees a message
  instead of a hanging page), results above `max_rows` are truncated with a warning, the CSV export is one execution
  capped at `max_rows`, and an unreachable report connection returns HTTP 503.
- **Read replica**: set `performance/connection_name` to a read-only connection declared in `app/etc/env.php` to keep
  report queries away from the checkout database.
- **Database timezone tables**: load them (`mysql_tzinfo_to_sql`) when stores use a non-UTC timezone, so hourly and
  daily buckets are converted in SQL; without them the module falls back to UTC-hour aggregation and re-aggregates in
  PHP, which is slower and cannot serve `count_distinct`/`avg` metrics by date.
- **Heavier reports**: new vs returning customers (first-order lookup, limited to 12 months on MySQL), customer value
  (`COUNT DISTINCT` over emails), sales by category (one row per product category) and the dead stock / never sold
  presets (anti-joins on `sales_order_item`). Prefer shorter periods on very large catalogs or order tables.
- **After upgrading**: constructors changed, so regenerate the generated code (`setup:di:compile` in production, clear
  `generated/` in developer mode) and run `setup:upgrade` for the new indexes (risk R33 in the [risk register](docs/risk-register.md)).

## Security and permissions

Access is enforced server-side: a dataset needs its module ACL (`sales`, `catalog`, `customers`) **and** the core ACL of
its entity. Full functionality needs these core resources:

- `Magento_Sales::sales_order` (`sales_orders`, `customer_orders`, `sales_items`)
- `Magento_Customer::manage` (`customers`)
- `Magento_Catalog::products` (`catalog_stock`)
- `Magento_InventoryApi::stock` (`catalog_salable`, MSI only)

Details, the entry-point inventory and the data classification are in [docs/security.md](docs/security.md).

## Known limits

The full list with severity and owner is in the [risk register](docs/risk-register.md). Metric semantics are in the [metric definitions](docs/metric-definitions.md); timezone and currency rules are in [time and currency](docs/time-and-currency.md).

- **Monetary reports and several base currencies**: a request with money metrics (or money filters) over stores or orders with different base currencies is rejected with HTTP 400 (also on Save, Export and `advanced-report:explain`). Select stores sharing one base currency. Orders, units and customer reports are not affected.
- **Upgrade note**: the former "mixed base currencies" warning was removed; reports that used to show it are now rejected. Without MySQL timezone tables, results are exact but non-additive metrics are empty and non whole-hour timezones reach the row cap sooner.

## Documentation

- [Metric definitions](docs/metric-definitions.md): business definition of every metric (formula, cancel/refund treatment, currency, date attribution, additivity, known limits) with the integration tests that prove it, and physical vs reserved vs salable quantities.
- [Time and currency](docs/time-and-currency.md): timezone used for filtering, grouping, comparison and display, DST, the fallback without timezone tables, the currency of every financial metric, rejection rules and rounding.
- [Metric inventory](docs/metric-inventory.md): generated list of every metric, its joins, fan-out risk and dependencies.
- [Performance](docs/performance.md): statements per report, safeguards, benchmark procedure and results table, bottlenecks, async evaluation.
- [Cache](docs/cache.md): cache key (including the result config), TTLs, invalidation, label staleness, failures, stampede lock and freshness per report category.
- [Indexes](docs/indexes.md): rationale of every index, removable indexes, installation time and maintenance window.
- [Security](docs/security.md): ACL model per entry point and dataset, data classification, cache and logging.
- [Risk register](docs/risk-register.md): known risks with severity and owner sub-plan.
- [Compatibility](docs/compatibility.md): supported Magento, PHP and database versions and the CI job enforcing each.

## Roadmap

An OpenSearch data source (`Magento_OpenSearch`) behind the same `DataSourceInterface` is planned as a separate
plan: datasets already declare their sources per engine, so presets and the UI will not change.

## Contributing

Issues and pull requests are welcome on GitHub. Please follow the Magento coding standard, keep the semantic layer
rules described above (no SQL in metrics, bound parameters only) and include unit tests for new classes.

## License

This module is licensed under the [Open Software License 3.0](https://opensource.org/licenses/OSL-3.0) and the
[Academic Free License 3.0](https://opensource.org/licenses/AFL-3.0).

## Support

For bugs or feature requests, please open an issue in the GitHub repository.
