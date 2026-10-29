# Metric definitions

Single source of truth for the semantics of every business-critical metric of the semantic layer
(`etc/report_datasets.xml`). Each metric lists its formula, how cancellations and refunds are treated, additivity and
null/zero behaviour, the known limits (risk register IDs, see [risk register](risk-register.md)) and the integration
tests that prove it with hand-computed values. The generated [metric inventory](metric-inventory.md) lists the raw SQL
expressions; this document explains the business meaning.

Test references use the form `Folder/ClassTest::method` and are relative to `Test/Integration/`
(for example `Sales/RefundsTest::testItSubtractsRefundedShippingFromShipping` is
`Test/Integration/Sales/RefundsTest.php`). Every metric has at least one test asserting a hand-computed value (risks R03 and R24 are closed). Tests marked
**execution only** (if any remain) run the metric without asserting its value.

## Conventions shared by all datasets

- **Currency**: every amount is in the **base currency of the order's store** (`base_*` columns). No conversion is
  applied; monetary reports over several base currencies are rejected. See
  [time and currency](time-and-currency.md#currency).
- **Counted statuses**: the order datasets (`sales_orders`, `customer_orders`, `sales_items`) apply the implicit
  `counted_status` filter. Config `mageos_advancedreport/sales/counted_statuses` is a comma-separated list of order
  status codes; empty (default) means every status except `canceled`. Pinned per test method with `#[Config]`.
  Counting `canceled` is possible: the order still counts in `orders`, but its monetary and unit components are
  netted to 0 by the cancel-aware formulas below.
- **Date attribution**: the report date is the order `created_at`, converted to the store timezone before bucketing
  (rules in [time and currency](time-and-currency.md#timezone)). Refunds, shipments and credit memos are attributed to the **order date**, not their own
  date (R04, R05): a past period changes when an order is later refunded or shipped.
- **Grain**: one row per order (`sales_orders`, `customer_orders`), per top-level order item (`sales_items`), per
  customer account (`customers`), per source item (`catalog_stock`), per SKU and stock (`catalog_salable`).
- **Additivity**: `SUM` and `COUNT` metrics are additive across rows. `COUNT DISTINCT` metrics (`unique_customers`,
  `sales_items.orders`) are not: the total is not the sum of the rows. Derived metrics are always recomputed from the
  aggregated components (ratio of sums, never the average of row ratios), on rows, totals and comparisons alike.
- **Null / zero**: `SUM` aggregates are `COALESCE(SUM(x), 0)`, so an empty period yields 0 for counts and sums
  (`Sales/DerivedRatiosTest::testItReturnsZeroCountsZeroSumsAndNoRowsForAnEmptyPeriodInEveryDataset`). A derived ratio
  with a zero denominator (including `aov` with no orders) is **null**
  (`Sales/DerivedRatiosTest::testItReturnsNullAverageOrderValueForAPeriodWithoutOrders`).
- **Filters**: first and last day of the range are inclusive; store and website filters restrict the totals
  (`Sales/FiltersTest::testItIncludesOrdersCreatedOnTheFirstAndLastDayOfTheRange`,
  `Sales/FiltersTest::testItExcludesOrdersCreatedAfterTheEndOfTheRange`,
  `Sales/FiltersTest::testItRestrictsTotalsToTheSelectedStore`,
  `Sales/FiltersTest::testItGroupsOrdersByWebsiteWithOneRowPerWebsite`,
  `Sales/FiltersTest::testItReportsFilteredTotalsEqualToTheSumOfTheMatchingOrders`).
- **Test environment note**: the integration install charges flat-rate shipping of 5.00 **per ordered unit** even when
  the carrier type is pinned to per order (`O`); expected shipping values in the tests use this. Only method-level
  `#[Config]` and method-level `#[DataFixture]` attributes are honoured by this Magento version (class-level
  `#[Config]` is ignored, method-level fixtures replace class-level ones), see R25.

## `sales_orders` (Orders)

Grain: one row per order. Dimensions worth knowing: `order_date`, `status` (added in p1b so that orders can be grouped
by order status), website/store, payment, shipping method, customer group, country, hour, weekday.

| Metric | Formula (business terms / SQL field) | Cancel / refund treatment | Additivity, null / zero | Known limits | Tests |
|---|---|---|---|---|---|
| `orders` | `COUNT(order_id)` | Canceled orders are counted only when `canceled` is a counted status. | Additive; 0 on an empty period. | | `Sales/OrderStatusTest::testItCountsEveryStatusExceptCanceledWithTheDefaultCountedStatuses`, `Sales/OrderStatusTest::testItCountsOnlyListedStatusesWithACustomCountedStatusList`, `Sales/OrderStatusTest::testItGroupsOrdersByStatusDimensionWithOneRowPerCountedStatus`, `OrderScenarioTest::testItCountsOnlyOrdersWithCountedStatusesInTheScenario` |
| `gross_sales` | Order subtotal excluding tax: `base_subtotal - base_subtotal_canceled`. | Net of cancellations; refunds are NOT subtracted here (see `returns`). | Additive; 0 when empty. | | `Sales/MonetaryComponentsTest::testItReportsGrossSalesAsTheSumOfOrderSubtotals`, `Sales/OrderStatusTest::testItSumsGrossSalesOfPendingProcessingCompleteAndClosedOrdersByDefault`, `Sales/OrderStatusTest::testItReportsZeroGrossSalesShippingAndTaxesForACanceledOrderWhenCanceledIsCounted`, `OrderScenarioTest::testItSumsGrossSalesOfCountedScenarioOrders` |
| `discounts` | Absolute discount applied: `ABS(base_discount_amount) - ABS(base_discount_canceled)`. Reported as a positive amount. | Net of cancellations; refunded discount is handled in `returns`. | Additive; 0 when empty. | | `Sales/MonetaryComponentsTest::testItReportsDiscountsAsPositiveAmountsFromTheCouponOrderOnly` |
| `returns` | Refunded subtotal net of refunded discount: `base_subtotal_refunded - ABS(base_discount_refunded)`. | Credit memo refunds (partial or full). | Additive; 0 when empty. | R04: attributed to the order date, not the credit memo date. | `Sales/RefundsTest::testItReportsPartialRefundAmountNetOfRefundedDiscountAsReturns`, `Sales/RefundsTest::testItReportsAFullRefundAsReturnsEqualToGrossSalesOfThatOrder`, `Sales/RefundsTest::testItAttributesRefundsToTheOrderDateRange`, `Sales/OrderStatusTest::testItReportsClosedOrderGrossSalesFullyOffsetByReturns`, `OrderScenarioTest::testItReportsThePartialRefundAmountOfOrderOneAsReturns` |
| `net_sales` | Derived: `gross_sales - discounts - returns`. | Both cancellations and refunds are netted. | Ratio of sums; recomputed on totals. | R04 | `Sales/MonetaryComponentsTest::testItComputesNetSalesAsGrossMinusDiscountsMinusReturns` |
| `shipping` | Shipping charged: `base_shipping_amount - base_shipping_canceled - base_shipping_refunded`. | Net of cancellations and refunded shipping. | Additive; 0 when empty. | Test env charges 5.00 per unit. | `Sales/MonetaryComponentsTest::testItReportsShippingAsFlatRatePerOrder`, `Sales/RefundsTest::testItSubtractsRefundedShippingFromShipping` |
| `taxes` | Tax charged: `base_tax_amount - base_tax_canceled - base_tax_refunded`. | Net of cancellations and refunded tax. | Additive; 0 when empty. | | `Sales/MonetaryComponentsTest::testItReportsTaxesNetOfNothingWhenNoRefundOrCancellationExists` |
| `adjustments` | Credit memo adjustments: `base_adjustment_positive - base_adjustment_negative` (extra refunded amount minus refund fee). | Only exists on refunded orders. | Additive; 0 when empty. | R04 | `Sales/RefundsTest::testItReportsAdjustmentsAsRefundAdjustmentMinusAdjustmentFee` |
| `total_sales` | Derived: `net_sales + shipping + taxes - adjustments`. | All the netting above. | Ratio of sums; recomputed on totals. | | `Sales/MonetaryComponentsTest::testItComputesTotalSalesAsNetPlusShippingPlusTaxesMinusAdjustments` |
| `aov` | Derived: `net_sales / orders` (total net sales over total orders). | As `net_sales`. | **Null** when there are no orders; the total is NOT the average of row values. | | `Sales/MonetaryComponentsTest::testItComputesAverageOrderValueAsTotalNetSalesDividedByOrderCountNotAsAverageOfRowValues`, `Sales/DerivedRatiosTest::testItReturnsNullAverageOrderValueForAPeriodWithoutOrders` |
| `units` | Quantity ordered on parent items, net of canceled quantity: `total_qty_ordered - SUM(qty_canceled)` (provider `units_net_of_canceled`, parent items only). Consistent with `gross_sales`, which subtracts canceled subtotal. | A partially invoiced then canceled order, or a counted canceled order, subtracts the canceled quantity (fixed in p1b). | Additive; 0 when empty or fully canceled. | Refunded units are not subtracted (see `sales_items.units_refunded`). | `Sales/UnitsCanceledTest::testItSubtractsCanceledQuantitiesFromUnitsOfAPartiallyCanceledOrder`, `Sales/UnitsCanceledTest::testItReportsZeroUnitsForAFullyCanceledOrderWhenCanceledIsCounted`, `Sales/UnitsCanceledTest::testItMatchesSalesItemsUnitsSoldForTheSameOrders`, `Sales/UnitsCanceledTest::testItKeepsUnitsUnchangedForOrdersWithoutCancellations` |
| `new_customer_orders` | `SUM(is_first_order)`: orders for which no earlier **counted** order exists for the same identity (registered customer id, else guest email, case-insensitive through the column collation). | A canceled earlier order (not counted) does not make the customer returning. | Additive; 0 when empty. | R08: guest then registered counts as new twice; the 12 months is the **maximum report range** for new-vs-returning (`RequestValidator::FIRST_ORDER_MAX_MONTHS`), not a lookback window: all earlier counted orders are checked. | `Customers/CustomerMetricsTest::testItCountsACustomerSecondOrderAsReturningEvenWhenTheFirstIsOutsideTheRange`, `Customers/CustomerMetricsTest::testItIgnoresCanceledOrdersWhenDecidingAFirstOrder`, `Customers/CustomerMetricsTest::testItTreatsGuestEmailsDifferingOnlyByCaseAsOneCustomer`, `Customers/CustomerMetricsTest::testItCountsAGuestWhoLaterRegistersAsNewTwiceAsDocumented`, `Customers/CustomerMetricsTest::testItRejectsANewVersusReturningRequestWhoseRangeExceedsTwelveMonths`, `OrderScenarioTest::testItClassifiesBothCountedScenarioOrdersAsNewCustomers` |
| `returning_customer_orders` | Derived: `orders - new_customer_orders`. | As above. | Recomputed on totals. | R08 | `Customers/CustomerMetricsTest::testItCountsACustomerSecondOrderAsReturningEvenWhenTheFirstIsOutsideTheRange` |

## `customer_orders` (Customer orders)

Grain: one row per order, grouped by customer. Customer identity is `customer_key = LOWER(TRIM(customer_email))`:
guest and registered orders of the same email are merged, a customer who changes email is split in two (R14). The
helper metrics `gross_sales`, `discounts`, `returns`, `shipping`, `taxes`, `adjustments` are hidden and have exactly the
formulas of `sales_orders` (same tests, see above); they only feed the derived metrics below.

| Metric | Formula (business terms / SQL field) | Cancel / refund treatment | Additivity, null / zero | Known limits | Tests |
|---|---|---|---|---|---|
| `orders` | `COUNT(order_id)` | As `sales_orders.orders`. | Additive. | | `Customers/CustomerMetricsTest::testItTreatsGuestEmailsDifferingOnlyByCaseAsOneCustomer` |
| `unique_customers` | `COUNT DISTINCT(customer_key)`. | Only counted orders. | **Not additive** (a customer ordering in two groups is in both rows). | R14, R17 | `Customers/CustomerMetricsTest::testItCountsARegisteredCustomerOrderingOnTwoStoreViewsAsOneUniqueCustomer`, `Customers/CustomerMetricsTest::testItTreatsGuestEmailsDifferingOnlyByCaseAsOneCustomer` |
| `total_spent` | Derived lifetime value of the period: `gross_sales - discounts - returns + shipping + taxes - adjustments`. | Net of cancellations, refunds and adjustments. | Ratio of sums. | R04 | `Customers/CustomerMetricsTest::testItReportsTotalSpentPerCustomerAsLifetimeValueNetOfReturnsPlusShippingAndTaxes` |
| `avg_spent` | Derived: `total_spent / orders`. | As `total_spent`. | Null when no orders. | | `Customers/CustomerMetricsTest::testItReportsTotalSpentPerCustomerAsLifetimeValueNetOfReturnsPlusShippingAndTaxes` |
| `avg_shipping_cost` | Derived: `shipping / orders`. | Net shipping. | Null when no orders. | Value asserted. | `Sales/SalesDocumentsTest::testItComputesAverageShippingCostAsShippingAmountOverCountedOrders` |
| `days_since_last_order` | `MIN(days_since_order)`: days from the most recent order in the period to today (UTC). | Counted orders only. | Not additive (minimum). | R18: relative to today, closed periods cached 24h may be a day stale. | `Customers/CustomerMetricsTest::testItReportsTotalSpentPerCustomerAsLifetimeValueNetOfReturnsPlusShippingAndTaxes` (asserts `days_since_last_order`) |
| `avg_wait_days` | `AVG(wait_days)`: days between the order and its **first** shipment (provider `first_shipment_wait`); unshipped orders excluded. | n/a | Not additive (average). | R03, R17. Value asserted (R24 closed). | `Sales/SalesDocumentsTest::testItComputesAverageWaitDaysFromOrderDateToFirstShipmentDate`, `testItIgnoresLaterShipmentsWhenComputingWaitDays`, `testItExcludesOrdersWithoutShipmentFromAverageWaitDays` |

## `customers` (Customers)

| Metric | Formula (business terms / SQL field) | Cancel / refund treatment | Additivity, null / zero | Known limits | Tests |
|---|---|---|---|---|---|
| `registrations` | `COUNT(customer_id)`: customer accounts created in the range (account `created_at`). | n/a (not order based, no counted statuses). | Additive; 0 when empty. | R15: admin-created accounts (store 0) and accounts of a deleted store are excluded when specific stores are selected. | `Customers/CustomerMetricsTest::testItCountsRegistrationsCreatedInTheRange` |

## `sales_items` (Order items)

Grain: one row per top-level order item (implicit `top_level_item` filter). A configurable product is reported **once on
the parent line** (the SKU dimension shows the child SKU, `parent_sku` groups to the configurable) with the **cost taken
from the child**. A bundle is reported on the **parent line only** with cost 0 (R06). `category_id` is a fan-out
dimension (a product in several categories appears in each, R01): totals are not the sum of the rows and a warning is
shown. The sum of `sales_items.gross_sales` over the same orders equals `sales_orders.gross_sales`.

| Metric | Formula (business terms / SQL field) | Cancel / refund treatment | Additivity, null / zero | Known limits | Tests |
|---|---|---|---|---|---|
| `units_sold` | `SUM(qty_ordered - qty_canceled)`. | Net of cancellations. | Additive. | | `OrderScenarioTest::testItSumsUnitsOrderedPerProductInSalesItemsDataset`, `Sales/UnitsCanceledTest::testItMatchesSalesItemsUnitsSoldForTheSameOrders`, `Catalog/ProductTypesTest::testItReportsAConfigurableSaleOnceOnTheParentLine` |
| `units_refunded` | `SUM(qty_refunded)`. | Credit memo quantities; independent of refunded amounts. | Additive. | R04 | `Sales/RefundsTest::testItReportsRefundedUnitsPerProductIndependentlyOfRefundedAmounts` |
| `units_shipped` | `SUM(qty_shipped)`. | n/a | Additive. | R05: order date; bundles shipped separately count 0. Value asserted (R24 closed). | `Sales/ShipmentsTest::testItCountsUnitsShippedAcrossPartialShipmentsOfOneOrder`, `testItAttributesShippedUnitsToTheOrderDateNotTheShipmentDate` |
| `units_to_ship` | `SUM(qty_ordered - qty_shipped - qty_refunded - qty_canceled)` for shippable items (virtual items and separately shipped bundles excluded). | Canceled and refunded quantities are removed from the backlog. | Additive. | R05. Value asserted (R24 closed). | `Sales/ShipmentsTest::testItReportsTheRemainingBacklogAsUnitsToShipAfterAPartialShipment`, `testItExcludesCanceledOrdersFromUnitsToShip` |
| `gross_sales` | `SUM(base_row_total)` excluding tax (item level). | Net of canceled quantity. | Additive. | R06 | `Catalog/ProductTypesTest::testItReportsItemGrossSalesSummingToOrderGrossSalesForTheSameOrders`, `Catalog/ProductTypesTest::testItReportsAConfigurableSaleOnceOnTheParentLine` |
| `discounts` | `SUM(ABS(base_discount_amount))` of the item. | Item level. | Additive. | | `Catalog/ProductTypesTest::testItReportsItemGrossSalesSummingToOrderGrossSalesForTheSameOrders` (net sales consistency) |
| `returns` | Amount refunded for the item net of refunded discount. | Credit memo refunds. | Additive. | R04 | `Sales/RefundsTest::testItComputesReturnRatePerProductFromSummedReturnsAndGrossSales` |
| `net_sales` | Derived: `gross_sales - discounts - returns`. | | Ratio of sums. | | `Catalog/ProductTypesTest::testItReportsAConfigurableSaleOnceOnTheParentLine` |
| `cost` | `SUM(cost x qty)` over rows with a known cost (`has_cost`); the child cost for configurables. | Net of canceled quantity. | Additive; rows without cost contribute 0. | R06 (bundle parent cost 0); cost basis is the order item `base_cost`. | `Catalog/ProductTypesTest::testItUsesTheChildProductCostForConfigurableMargin` |
| `gross_margin` | Derived: `net_sales - cost`. | | Ratio of sums. | A row without cost has margin equal to net sales; read with `cost_coverage`. | `Catalog/ProductTypesTest::testItUsesTheChildProductCostForConfigurableMargin`, `Sales/DerivedRatiosTest::testItComputesMarginPercentTotalsFromSummedMarginAndSummedNetSales` |
| `margin_pct` | Derived ratio (not x100): `gross_margin / net_sales`. | | Total is margin total over net sales total, not the mean of the row percentages. Null when net sales is 0. | | `Sales/DerivedRatiosTest::testItComputesMarginPercentTotalsFromSummedMarginAndSummedNetSales` |
| `return_rate` | Derived ratio (not x100): `returns / gross_sales`. | | Ratio of sums. Null when gross sales is 0. | R04 | `Sales/DerivedRatiosTest::testItComputesReturnRateTotalsFromSummedReturnsAndSummedGrossSales`, `Sales/RefundsTest::testItComputesReturnRatePerProductFromSummedReturnsAndGrossSales` |
| `orders` | `COUNT DISTINCT(order_id)`. | | **Not additive.** | R01 (not flagged as fan-out). | `Catalog/ProductTypesTest::testItCountsDistinctOrdersPerProduct` |
| `shipments` | Number of shipments containing the product (provider `shipment_count`). | n/a | Additive over rows. | R03, R05. Value asserted (R03, R24 closed). | `Sales/ShipmentsTest::testItCountsTwoShipmentsForOneOrderLineShippedTwice` |
| `credit_memos` | Number of non-canceled credit memos containing the product, with at least one item of qty > 0 (provider `credit_memo_count`). | Adjustment-only memos and canceled memos are not counted. | Additive over rows. | R03, R04. Value asserted (R03, R24 closed). | `Sales/SalesDocumentsTest::testItCountsTwoCreditMemosForOneOrderRefundedInTwoSteps` (plus `Sales/RefundsTest`) |
| `cost_coverage` | Derived: `has_cost_rows / rows` (hidden helpers: item rows with known cost, all item rows). | | Ratio of sums; null with no rows. | | `Sales/DerivedRatiosTest::testItComputesCostCoverageFromRowsWithCostOverAllRows` |

## `catalog_stock` (Stock), physical quantity

Grain: one row per source item (MSI, `inventory_source_item`) or per legacy stock item (without MSI). Dateless
dataset: not filtered by the order date. See [Inventory quantities](#inventory-quantities-physical-reserved-and-salable)
for how it relates to salable stock.

| Metric | Formula (business terms / SQL field) | Cancel / refund treatment | Additivity, null / zero | Known limits | Tests |
|---|---|---|---|---|---|
| `qty_on_hand` | `SUM(quantity)` of the **enabled** sources (`inventory_source.enabled = 1`). Source items of a disabled source are excluded; a source item with status 0 (out of stock at the source) still counts its physical quantity. | Physical quantity: orders and cancellations affect it only once shipped / returned to stock. | Additive. | Fixed in p1b: disabled sources were counted. | `Inventory/PhysicalStockTest::testItSumsPhysicalQuantityAcrossEnabledSourcesOnly`, `Inventory/PhysicalStockTest::testItGroupsQuantityBySourceCode` |
| `stock_value_cost` | `SUM(quantity x cost)`; cost is the global (store 0) `cost` attribute; products without cost count 0. | n/a | Additive; 0 when empty. | R10: no website/store price or cost overrides. | `Inventory/PhysicalStockTest::testItValuesStockAtCostAsQuantityTimesGlobalCostAttribute`, `Inventory/PhysicalStockTest::testItValuesStockWithoutCostAsZeroCostValueAndFullRetailValue` |
| `stock_value_retail` | `SUM(quantity x price)` with the global `price` attribute. | n/a | Additive. | R10 | `Inventory/PhysicalStockTest::testItValuesStockWithoutCostAsZeroCostValueAndFullRetailValue` |
| `skus_in_stock` | `COUNT DISTINCT(product_id)` where the quantity on hand is greater than 0. | n/a | **Not additive.** | Value asserted (R24 closed). | `Inventory/StockCountMetricsTest::testItCountsSkusWithPositiveQuantityAsInStockEvenWhenTheSourceItemStatusIsOutOfStockAndExcludesZeroQuantity`, `testItDoesNotCountASkuTwiceWhenItHasQuantityOnTwoSources`, `testItExcludesSkusOnDisabledSourcesFromSkusInStock` |
| `units_sold_30d`, `avg_daily_sales`, `coverage_days`, `days_since_last_sale` | Virtual metrics produced by the stock presets (anti-joins and 30-day sales on `sales_order_item`): units sold over 30 days, divided by 30, days of coverage, days since last counted sale. Empty when there are no sales. | Counted statuses, net of cancellations. | Not additive. | Disabled sources are excluded in the presets too. Values asserted (R24 closed). | `Preset/PresetMetricsTest::testItSumsUnitsSoldInTheLast30DaysAndIgnoresOlderOrders`, `testItComputesAverageDailySalesAsUnitsSoldIn30DaysDividedBy30`, `testItComputesCoverageDaysAsQuantityOnHandDividedByAverageDailySales`, `testItReturnsZeroSalesAndNullCoverageDaysForASkuWithoutSales`, `testItComputesDaysSinceLastSaleFromTheMostRecentCountedOrder`, `testItIgnoresCanceledOrdersWhenComputingDaysSinceLastSale`; `Inventory/PhysicalStockTest::testItExcludesDisabledSourcesFromStockPresets` |

## `catalog_salable` (Salable stock), MSI only

Grain: one row per SKU and stock. Available only when the MSI modules are enabled (`Magento_InventoryApi`,
`Magento_InventoryIndexer`, `Magento_InventoryReservations`, `Magento_InventoryCatalog`); otherwise the dataset and its
presets are dropped from the registry at load time (info log per dropped dataset). Configurable, bundle and grouped
products (no source items of their own) are excluded.

| Metric | Formula (business terms / SQL field) | Cancel / refund treatment | Additivity, null / zero | Known limits | Tests |
|---|---|---|---|---|---|
| `stock_qty` | Quantity in the stock index (`inventory_stock_<id>`), before pending reservations. Follows source quantity, shipments. | A shipment lowers it; a cancel does not change it. | Additive across SKUs / stocks. | R26: non-default stocks unit-test only. | `Inventory/SalableStockTest::testItReportsPhysicalQuantityReducedAndReservationReleasedAfterShipment`, `Inventory/SalableStockTest::testItReflectsASourceQuantityAdjustmentInSalableQuantity` |
| `reserved_qty` | Quantity reserved by pending orders: `-SUM(inventory_reservation.quantity)`; positive when reserved. | Placing an order reserves; shipping or canceling releases (compensating reservation). | Additive. | R26 | `Inventory/SalableStockTest::testItReportsReservedQuantityAfterAnOrderIsPlaced`, `Inventory/SalableStockTest::testItReleasesTheReservationWhenTheOrderIsCanceled`, `Inventory/SalableStockTest::testItReportsPhysicalQuantityReducedAndReservationReleasedAfterShipment` |
| `salable_qty` | `stock_qty - reserved_qty - effective min qty` when the stock index marks the product salable, else 0. Mirrors core `GetProductSalableQty`; **no clamping**, so it can be negative. | As above. | Additive. | R26 | `Inventory/SalableStockTest::testItMatchesCoreSalableQuantityAtEveryStep`, `Inventory/SalableStockTest::testItReflectsASourceQuantityAdjustmentInSalableQuantity` |
| `skus_salable` | `COUNT DISTINCT(product_id)` where `salable_qty > 0`. | | **Not additive.** | Value asserted (R24 closed). | `Inventory/StockCountMetricsTest::testItCountsSkusWithPositiveSalableQuantityAfterReservationsAsSalable`, `testItExcludesASkuFullyReservedByPlacedOrdersFromSkusSalable` |

## `carts` and `cart_items` (Carts)

Grain: one row per quote (`carts`), per quote item (`cart_items`). Area `carts`; ACL `MageOS_AdvancedReport::carts`.
Amounts are in the base currency of the cart's store. Implicit filter `non_empty_cart`: only quotes with
`items_count > 0` are read.

Definitions shared by both datasets (`cart_items` evaluates them on the cart the item belongs to):

| Term | Definition |
|---|---|
| Date | `quote.updated_at` (last activity), converted to the store timezone. Carts are not dated by creation. |
| Abandoned | `is_active = 1`, `items_count > 0`, non-empty `customer_email` (guest carts with an email included) and `updated_at` older than `mageos_advancedreport/carts/abandoned_after_hours` (default 24). |
| Recent | `is_active = 1`, `items_count > 0` and `updated_at` within `mageos_advancedreport/carts/recent_cart_hours` (default 24, maximum 120 because the recent presets use `last_7_days`). |
| Converted | At least one order references the cart through `sales_order.quote_id`, in any status (canceled included). A multi-shipping checkout creates several orders for one cart and counts as one converted cart. |
| `cart_state` | Active cart updated within the abandoned threshold: `active`; active and idle beyond it: `abandoned` (with email) or `abandoned without email`; inactive cart with an order: `converted`; any other inactive cart: `inactive`. Evaluated in this order. |

Thresholds are computed in UTC from `ClockInterface::now()`, not from the database clock.

Known limits:

- **Dating**: a cart is dated by its last activity. A converted cart is dated near its order time, so `conversion_rate`
  and `abandonment_rate` of a period compare carts active in that period, not carts created in it.
- **Any order status**: a canceled order still marks its cart as converted.
- **Multi-shipping**: several orders of one cart count once.
- **PII**: `customer_email` is exposed; see [security](security.md#data-classification).
- **Cache**: the result changes as time passes, so cart datasets always use the open-period TTL ([cache](cache.md#ttl)).

### `carts` metrics

| Metric | Formula | Notes |
|---|---|---|
| `carts` | `COUNT(entity_id)` | Carts with items. |
| `cart_value` | `SUM(base_grand_total)` | |
| `avg_cart_value` | `cart_value / carts` | Null when there are no carts. |
| `items_in_carts` | `SUM(items_qty)` | Total quantity. |
| `abandoned_carts` | `SUM(is_abandoned)` | Lower is better. |
| `abandoned_value` | `SUM(base_grand_total)` of abandoned carts | Lower is better. |
| `recent_carts` | `SUM(is_recent)` | |
| `converted_carts` | `SUM(is_converted)` | |
| `converted_value` | `SUM(base_grand_total)` of converted carts | Cart total, not the order total. |
| `abandonment_rate` | `abandoned_carts / (abandoned_carts + converted_carts)` | Lower is better; null when both are 0. |
| `conversion_rate` | `converted_carts / carts` | Null when there are no carts. |

### `cart_items` metrics

Configurable products are counted on the parent line (the simple child is ignored); bundles are a single line. Items are
dated by the last activity of their cart. `category_id` is a fan-out dimension: totals may not equal the sum of the rows (R01).

| Metric | Formula | Notes |
|---|---|---|
| `units_in_carts` | `SUM(qty)` | |
| `cart_items_value` | `SUM(base_row_total)` | Excluding tax. |
| `carts` | `COUNT DISTINCT(quote_id)` | Not additive across rows. |
| `abandoned_units` | `SUM(qty)` in abandoned carts | Lower is better. |
| `abandoned_items_value` | `SUM(base_row_total)` in abandoned carts | Lower is better. |
| `abandoned_carts` | `COUNT DISTINCT(quote_id)` of abandoned carts | Not additive; lower is better. |
| `converted_units` | `SUM(qty)` in converted carts | |
| `item_conversion_rate` | `converted_units / units_in_carts` | Null when there are no units. |

Carts are verified by the unit tests of the cart providers and the integration tests under `Test/Integration/`; the raw
expressions are in the [metric inventory](metric-inventory.md).

## Inventory quantities: physical, reserved and salable

| Term | Meaning | Where it is reported |
|---|---|---|
| **Physical** (on hand) | Quantity physically held in the **enabled** sources (`inventory_source_item.quantity`). Unchanged by an order until it is shipped. | `catalog_stock.qty_on_hand`, per source |
| **Stock** (indexed) | Physical quantity of the sources assigned to a stock, as aggregated by the MSI stock index; after a shipment it is lower. | `catalog_salable.stock_qty`, per stock |
| **Reserved** | Quantity committed to pending orders and not yet shipped, stored as negative rows in `inventory_reservation`; shown as a positive number. A shipment or cancellation adds the compensating row, so reserved returns to 0. | `catalog_salable.reserved_qty` |
| **Salable** | What can still be sold: `stock - reserved - minimum quantity`, mirroring core `GetProductSalableQty` (0 when the stock index marks the product not salable; negative values are shown as-is). | `catalog_salable.salable_qty` |
| **Available** | In this module, "available" means **salable**. There is no separate available metric. | `catalog_salable.salable_qty` |

Differences without MSI (legacy `cataloginventory_stock_item`, MSI modules disabled): `catalog_stock` uses the legacy
provider (a single stock, no sources, no disabled-source filter, no source dimension); there is **no** `catalog_salable`
dataset and no reserved quantity (the legacy stock item quantity already is decremented at order placement, so physical
and salable are not distinguishable). Reports and presets that depend on `catalog_salable` are dropped, not shown empty.

Order events seen from the reports (default stock, covered by `Inventory/SalableStockTest`):

| Event | `stock_qty` | `reserved_qty` | `salable_qty` |
|---|---|---|---|
| Order placed | unchanged | + order qty | - order qty |
| Order shipped | - shipped qty | back to 0 for the shipped qty | unchanged by the shipment itself |
| Order canceled | unchanged | back to 0 | back to the previous value |
| Source quantity adjusted by n | + n | unchanged | + n |
