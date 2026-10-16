# Risk register

Single source of truth for the known risks of the reporting semantic layer. Seeded from the generated
[metric inventory](metric-inventory.md) (fan-out sources), the README "Known limits" and the p1a discovery findings.
Owner sub-plans: p1b (correctness tests), p1c (security and cache context), p1d (currency), p1e (cache freshness),
p1f (documentation and limits).

| ID | Area | Risk | Affected metrics/datasets | Severity | Owner sub-plan | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R01 | Fan-out | `category_id` is a fan-out dimension (a product in several categories appears in each, join `category` is many): totals may not equal the sum of the rows (README "category_id non-additive" limit; a warning is shown). | `sales_items` x `category_id`: all additive metrics (`units_sold`, `units_refunded`, `units_shipped`, `units_to_ship`, `gross_sales`, `discounts`, `returns`, `net_sales`, `cost`, `gross_margin`, `margin_pct`, `return_rate`, `shipments`, `credit_memos`, `cost_coverage`, hidden `has_cost_rows`, `rows`). `orders` (count_distinct) is not flagged. No metric uses a fan-out join. | medium | p1b | open, warning shown |
| R02 | Fan-out | No fan-out join (cardinality `many`) is used by any metric in the shipped definitions; the `child` join is `one`. Re-check with the inventory when definitions change. | all datasets | low | p1b | none today |
| R03 | Verification | Expression providers (`first_order`, `first_shipment_wait`, `shipment_count`, `credit_memo_count`) are reported as `provider:<name>` and their subqueries are not analysed by the inventory; verify by integration test. | `sales_orders.new_customer_orders`, `customer_orders.avg_wait_days`, `sales_items.shipments`, `sales_items.credit_memos` | medium | p1b | open |
| R04 | Attribution | Returns are attributed to the order date, not the credit memo date; past periods change on refund (closed periods cached 24h). | `returns`, `net_sales`, `units_refunded`, `return_rate` (all datasets) | medium | p1f | documented |
| R05 | Attribution | Units shipped and to ship are attributed to the order date: `units_to_ship` is the backlog of orders of the period, past periods change as shipments are created; bundles shipped separately count 0 shipped and 0 to ship on the bundle line. | `units_shipped`, `units_to_ship`, `shipments` (`sales_items`) | medium | p1f | documented |
| R06 | Product types | Bundle products count as a single line in `sales_items` (parent row; children not reported separately). | `sales_items` metrics | low | p1f | documented |
| R07 | Dimensions | A multiselect attribute used as dimension is bucketed by its raw stored value (for example `12,15`), not split per option. | `sales_items` and `catalog_stock` attribute dimensions | low | p1f | documented |
| R08 | Customers | New vs returning analysis is limited to 12 months on MySQL; a guest checkout later followed by a registered account for the same person is counted as new twice. | `new_customer_orders`, `returning_customer_orders` (`sales_orders`) | medium | p1b | documented |
| R09 | Dimensions | A gender value without a matching option (legacy or removed option id) is shown raw. | `customers` gender dimension | low | p1f | documented |
| R10 | Inventory | Stock value uses the global (default scope) product price and cost, not website or store overrides. | `catalog_stock` stock value metrics | medium | p1f | documented |
| R11 | Compatibility | `Magento_Reports` is a hard dependency (parent admin menu entry). | module | low | p1f | documented |
| R12 | Compatibility | Magento Open Source / Mage-OS only: EAV joins use `entity_id`, Adobe Commerce `row_id` (staging) is unsupported. | EAV-based dimensions, all datasets | medium | p1f | documented |
| R13 | Currency | When selected stores use different base currencies, amounts are summed without conversion and only a warning is shown. Decision: reject the request instead. | all currency metrics (`gross_sales`, `discounts`, `returns`, `net_sales`, `shipping`, `taxes`, `adjustments`, `total_sales`, `aov`, `cost`, ...) | critical | p1d | open |
| R14 | Customers | A customer who changes email is split into two customers. | `customer_orders.unique_customers`, customer dimensions | medium | p1f | documented |
| R15 | Customers | Customer accounts with `store_id` 0 (admin-created) or NULL (deleted store) are excluded when specific stores are selected, and from "All stores" when stores span several timezones; counted in a single-timezone install. | `customers` dataset, customer analysis | medium | p1b | documented |
| R16 | Performance | Large customer lists are capped by `max_rows`; results are truncated with a warning. | `customers`, `customer_orders` | low | p1f | documented |
| R17 | Timezone | Without database timezone tables, `avg_wait_days` and `unique_customers` (non-additive) come back empty with a warning when grouped by order date in a non-UTC timezone; hourly reports on non whole hour offsets may be inaccurate (timezone tables fallback). | `customer_orders.avg_wait_days`, `customer_orders.unique_customers`, hourly reports | medium | p1b | documented |
| R18 | Cache | `days_since_last_order` is relative to today, so cached closed periods (24h TTL) may be up to one day stale. | `customers.days_since_last_order` | low | p1e | documented |
| R19 | Security | Cache key lacks the admin ACL context: users with different permissions may share cached results. | all datasets (cache) | high | p1c | open |
| R20 | Security | PII dimensions are gated only by the dataset ACL. Decision: require `Magento_Customer::manage`. | `customers`, `customer_orders` PII dimensions | high | p1c | open |
| R21 | Cache | Cache freshness is TTL-only (no invalidation on order/refund events); documented by decision. | all datasets (cache) | low | p1e | accepted, documented |
| R22 | Testing | No integration coverage existed before p1a. Now covered by the order scenario (`Test/Integration`, `make integration`) with hand-computed values, no metric discrepancies found; the scenario covers a subset of datasets. | all datasets | medium | p1b | partly mitigated |
| R23 | Compatibility | Magento 2.4.7 compatibility is unverified: 2.4.7-p10 is out of the CI matrix because Composer blocks its league/flysystem 2.x (advisory PKSA-w9tt-7782-78jx) and no fixed 2.x exists. | module on 2.4.7-p10 / PHP 8.3 | medium | p1b | open |
