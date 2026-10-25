# Indexes

Every statement is `EXPLAIN`ed by `bin/magento advanced-report:explain --format=json`; `Test/Integration/Schema/IndexUsageTest.php`
asserts that each index below is listed in `possible_keys` of the statement it serves (not in `key`: the integration data is tiny).

Indexes are declared in `etc/db_schema.xml` on core tables (`sales_order`, `sales_order_item`) and whitelisted in
`etc/db_schema_whitelist.json`. Nothing is ever dropped by the module.

## Module indexes

### SALES_ORDER_CUSTOMER_ID_CREATED_AT

- Table / columns: `sales_order (customer_id, created_at)`
- Serves: the "is first order" expression (`FirstOrderExpression`), registered customers branch: `NOT EXISTS (... p.customer_id = o.customer_id AND p.created_at <= o.created_at ...)`; presets `new_vs_returning`, `sales_trend` (new/returning metrics), `customer_type` dimension, customer cohort metrics.
- Rationale: the correlated sub select is one range lookup per order instead of a scan over the customer's orders.
- Write overhead: one extra B-tree entry per order insert and per `customer_id`/`created_at` update; orders are append-mostly, so negligible.

### SALES_ORDER_CUSTOMER_EMAIL_CREATED_AT

- Table / columns: `sales_order (customer_email, created_at)`
- Serves: the guest branch of the same expression (`p.customer_id IS NULL AND p.customer_email = o.customer_email`).
- Rationale: guests have no `customer_id`; core has no index on `customer_email`, so without it each guest order triggers a full scan.
- Write overhead: one entry (email is a varchar(255) prefix-sized key) per order insert; the largest of the module indexes.

### SALES_ORDER_ITEM_PRODUCT_ID

- Table / columns: `sales_order_item (product_id)`
- Serves: item level product joins and the stock anti joins (`dead_stock`, `never_sold`): `WHERE i.product_id = s.product_id`.
- Rationale: core only indexes `order_id` and `store_id` on items.
- Status: superseded by `SALES_ORDER_ITEM_PRODUCT_ID_ORDER_ID` (leftmost prefix), see "Removable indexes".

### SALES_ORDER_ITEM_PRODUCT_ID_ORDER_ID

- Table / columns: `sales_order_item (product_id, order_id)`
- Serves: `AbstractStockAntiJoinQuery::orderItemsOf()` (`dead_stock` recent-sale `NOT EXISTS`, `days_since_last_sale`, `never_sold`): `FROM sales_order_item i JOIN sales_order o ON o.entity_id = i.order_id WHERE i.product_id = s.product_id`.
- Rationale: the join column `order_id` is in the index, so the item side is read from the index alone (covering) and only the order primary key is then probed.
- Write overhead: one extra entry per order item insert (two integers plus the primary key).

### MAGEOS_ADVANCEDREPORT_SAVED_ADMIN_USER_ID

- Table / columns: `mageos_advancedreport_saved (admin_user_id)`
- Serves: listing the saved reports of an admin user.
- Rationale: the saved reports grid and the share check filter by owner.
- Write overhead: negligible, the table holds a few rows per admin.

### MAGEOS_ADVANCEDREPORT_SAVED_IS_SHARED

- Table / columns: `mageos_advancedreport_saved (is_shared)`
- Serves: listing the reports shared with all admins.
- Rationale: shared reports are looked up independently of the owner.
- Write overhead: negligible.

## Native indexes relied on

- `sales_order.created_at`, `(store_id, state, created_at)`, `store_id`, `status`, `customer_id`: date scope and store/status filters of every sales preset.
- `sales_order_item.order_id`: order to item joins.
- `sales_order_payment.parent_id`, `sales_order_address.parent_id`, `sales_creditmemo.order_id`, `sales_shipment.order_id`: payment, address, refund and shipment joins.
- EAV value tables: unique key `(entity_id, attribute_id, store_id)` serves the attribute joins of `ReportAttribute` fields.
- `catalog_category_product.product_id` and `inventory_source_item (source_code, sku)` unique key: category and source joins.

## Evaluated and not added

- `sales_order (created_at, store_id)`: a range on `created_at` already reads the rows needed by the aggregates; the extra column does not make the scan covering, and native `(store_id, state, created_at)` serves store-first access.

## Removable indexes

These are not needed by any shipped statement. They are documented only: the module never drops an index, and removing them is an operator decision.

- `SALES_ORDER_ITEM_PRODUCT_ID` (module): redundant, its columns are the leftmost prefix of `SALES_ORDER_ITEM_PRODUCT_ID_ORDER_ID`. Removing it saves one B-tree entry per order item insert.
- `SALES_ORDER_CUSTOMER_ID` (core): prefix of `SALES_ORDER_CUSTOMER_ID_CREATED_AT`. Core code (foreign key on `customer_id`) relies on it; keep it.

## Installation time and maintenance window

Module indexes are created by `setup:upgrade` from `etc/db_schema.xml`. Magento's declarative schema builds the index with
`ALTER TABLE ... ADD INDEX`; on MySQL 8 and MariaDB (InnoDB) this is an online operation (`ALGORITHM=INPLACE, LOCK=NONE`): reads
and writes continue during the build, and the table is rebuilt only in the sense of reading the clustered index to sort the
new key. The cost is I/O, temporary sort files in the tmp directory and replication lag on replicas, growing with the
table size (`sales_order`, `sales_order_item`).

Guidance for large shops:

- Measure first: `mysql <db> < <module-dir>/dev/benchmark/index-timing.sql` builds every module index on **copies** of `sales_order` and `sales_order_item` in
  the seeded database (see the [benchmark procedure](performance.md#benchmark-procedure)) with `ALGORITHM=INPLACE, LOCK=NONE`, prints the seconds per index and drops the
  copies. It needs free disk space for the copies and is user-run only. The result is a lower bound: production has
  concurrent writes.
- Run `setup:upgrade` outside peak traffic (a maintenance window is recommended, even though the build does not block writes).
- Check free disk space and the temporary directory size before the upgrade; watch replica lag.
- If the engine cannot do the change online, the statement fails or waits for metadata locks; do not run it while long
  transactions hold the order tables.

Results of `index-timing.sql` on the medium and large profiles (seconds per index, MySQL 8.4.4 in Docker, one machine, no concurrent writes):

| Index | Medium (50 000 orders) | Large (500 000 orders) |
| --- | --- | --- |
| `SALES_ORDER_CUSTOMER_ID_CREATED_AT` | 0.1296 | 1.4773 |
| `SALES_ORDER_CUSTOMER_EMAIL_CREATED_AT` | 0.2074 | 1.3776 |
| `SALES_ORDER_ITEM_PRODUCT_ID` | 0.1693 | 1.6457 |
| `SALES_ORDER_ITEM_PRODUCT_ID_ORDER_ID` | 0.1972 | 1.1390 |

An integration test (`Test/Integration/Schema/InstallationTest.php`) asserts the installed index definitions (columns, order, non-unique) and that the declarative schema is up to date after install; a unit test requires a `### <referenceId>` section above for every declared index.
