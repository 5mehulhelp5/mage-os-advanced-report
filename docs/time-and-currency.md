# Time and currency

Semantics of dates, timezones and currencies in the reports. Every rule links to the integration test that proves it
(paths relative to `Test/Integration/`, run with the Magento integration framework (`cd dev/tests/integration && ../../../vendor/bin/phpunit -c phpunit.xml ../../../app/code/MageOS/AdvancedReport/Test/Integration`)). Risks are tracked in the
[risk register](risk-register.md) (R13, R17, R34 to R37); metric semantics are in the
[metric definitions](metric-definitions.md).

## Timezone

### Source

The timezone of a store is `general/locale/timezone` read at **website scope** (`StoreTimezoneMap`). Stores of the same
website share it. "All stores" means every store view (the admin store is excluded).

### Filtering

The date range is a list of inclusive **local** dates. It is converted to half-open UTC ranges
(`[local midnight of the first day, local midnight after the last day)`), one range per group of stores sharing a
timezone, and applied to `created_at` as plain range conditions, so the index on `created_at` stays usable.

| Rule | Test |
| --- | --- |
| An order at local midnight of the first day is included; one at local midnight after the last day is excluded | `Time/TimezoneTest::testItIncludesAnOrderAtLocalMidnightOfTheFirstDayAndExcludesOneAtLocalMidnightAfterTheLastDayInEuropeRome` |
| The last second of the last local day is included | `Time/TimezoneTest::testItIncludesTheLastSecondOfTheLastLocalDayInEuropeRome` |
| Non whole-hour offsets (Asia/Kolkata +5:30, Asia/Kathmandu +5:45) | `Time/TimezoneTest::testItPlacesOrdersInLocalDaysForUtcAsiaKolkataStores`, `::testItPlacesOrdersInLocalDaysForUtcAsiaKathmanduStores` |

### Carts

For `carts` and `cart_items` the date range applies to the last activity (`quote.updated_at`) in the store timezone, with
the same conversion as above. The abandoned and recent thresholds are not store local: they are computed in UTC from
`ClockInterface::now()` minus the configured hours and compared with the UTC `updated_at`.

### Grouping

Buckets (hour, day, week, month, year; there is no quarter granularity) are computed on the local time: `CONVERT_TZ(created_at, 'UTC', <tz>)`.
When the scope spans several timezones, each store is bucketed in **its own local time** (a `CASE store_id` per
timezone), so an order belongs to the local day of the store that took it. This is intentional (R35).

| Rule | Test |
| --- | --- |
| A month boundary order goes to the local month | `Time/TimezoneTest::testItGroupsAMonthBoundaryOrderIntoTheLocalMonth` |
| Several timezones in scope: each store bucketed in its own timezone | `Time/TimezoneTest::testItBucketsEachStoreInItsOwnTimezoneWhenTheScopeSpansSeveralTimezones` |

### Report scope: "today" and week start

The **report scope** (`ReportScopeResolver`) is derived from the stores of the request:

- If every store in scope (the selected ones, or all stores for "All stores") shares one timezone, that timezone is
  the scope timezone and the first day of the week is `general/locale/firstday` of the **first selected store**
  (the default store view for "All stores").
- If the timezones differ, the scope timezone and the first day come from the **default config scope**.

"Today" for relative ranges (today, yesterday, last 30 days, month to date, ...) is `Clock::today($timezone)` in the
scope timezone. The week start of the scope (`ResolvedQuery::$scope->firstDayOfWeek`) is used everywhere: SQL
grouping, the fallback re-aggregation, the empty bucket filler and comparison periods, so week keys always match.

| Rule | Test |
| --- | --- |
| "Today" is resolved in the scope store timezone | `Time/TimezoneTest::testItResolvesTodayInTheReportScopeStoreTimezone` |
| Weeks start on the configured first day (Monday, Sunday) | `Time/TimezoneTest::testItGroupsWeeksStartingOnTheStoreConfiguredFirstDay`, `::testItStartsWeeksOnSundayWhenSundayIsTheConfiguredFirstDay` |

### Comparison periods

A comparison period is built from the same **local dates** logic and shares the scope of the main period:

- Previous period: the range of the same length immediately before the main range. The length is counted in calendar
  dates, so a daylight saving change inside the range never shifts it by a fraction of a day. When the main range is made
  of whole calendar months (including whole years), the previous period is the preceding N whole months instead (March
  compared with February, not with the last 31 days).
- Previous year: the same calendar dates one year earlier; 29 February is clamped to 28 February.

Both are filtered and grouped like the main period, and the currency policy is shared: the comparison period is probed
too (see [Currency](#currency)).

How comparison rows are matched to the current rows:

- With **one date dimension** (hour, day, week, month or year), the current bucket is shifted and looked up in the
  comparison result: hour and day buckets by the day offset of the comparison (previous period) or by one calendar year
  (previous year); week, month and year buckets by the calendar: the same bucket one year earlier, or N buckets earlier
  (N being the number of buckets of the current range) for the previous period. A week of the current year can map onto
  the same previous-year week as its neighbour in rare cases.
- The comparison rows are **not gap filled**: the current rows drive the table, so a bucket that has no match in the
  comparison period shows a null comparison value (and a null delta), and comparison buckets without a current row are
  dropped.
- When a date dimension is **combined with another dimension**, the buckets are not aligned: the raw date keys are
  compared as they are, so the comparison values are null for every date row. Use a single date dimension, or a
  dimension without a date, for comparisons.
- Dimensions are matched by their raw values (ids), never by labels.
- `delta` is current minus previous; `delta_pct` is `delta / ABS(previous)` as a ratio (0.25 means +25 %), and null when
  the previous value is zero or missing.
- Metrics can not be filtered: filters apply to fields only (a derived metric such as `aov` is not a field).

### Display

Dates are shown as the **local bucket keys**: hour `Y-m-d H:i`, day and week `Y-m-d` (week = first day of the week),
month `Y-m`. They are not converted back to the viewer timezone. `generated_at` in the result meta is the generation
instant of the result, independent of the report timezone. Empty buckets of the current period are zero filled.

### Daylight saving time

Hourly reports use local wall-clock hours (`DateBucketSequence`, union across the timezones in scope):

- Spring forward: the day has **23** hourly buckets (the non-existent hour is not generated).
- Fall back: the day has 24 buckets for the repeated hour, which is **merged** into one bucket (the two real hours
  with the same wall-clock time are summed, R34). Hourly comparison across a DST day may leave the comparison's
  extra repeated-hour bucket without a current-period row; totals are unaffected.

| Rule | Test |
| --- | --- |
| 23 hourly buckets on the spring forward day | `Time/TimezoneTest::testItReturns23HourlyBucketsOnTheSpringForwardDay` |
| The repeated hour is merged on the fall back day | `Time/TimezoneTest::testItMergesTheRepeatedHourOnTheFallBackDay` |

### Fallback without database timezone tables

If the MySQL timezone tables are not loaded, `CONVERT_TZ` cannot be used. The module then groups by **UTC time slots**
in SQL and re-aggregates them into local buckets in PHP (`UtcHourReaggregator`):

- Slot size: **60 minutes** when every UTC offset in scope is a whole hour over the report range (transitions
  included), **15 minutes** otherwise (every real offset is a multiple of 15 minutes).
- The result is **exact for all offsets**, daily, weekly, monthly and hourly, and equal to the `CONVERT_TZ` result.
- Only metrics that cannot be merged from slots (`avg_wait_days`, `unique_customers`, other non additive metrics)
  come back `null` with a warning ("The ... metric is not available because the database has no timezone tables").
  Additive metrics (sum, count, min, max, count distinct of the order id) and derived ratios stay exact.
- The row cap is the absolute `max_rows * 50` (unchanged). 15-minute slots produce up to four times more rows than
  hourly ones, so reports in non whole-hour timezones reach the truncation warning ("too many time slots") sooner than
  the former hourly grouping. Narrow the date range or add filters.
- The strategy in use is reported in the result meta and in the diagnostics panel.

| Rule | Test |
| --- | --- |
| The fallback strategy is reported in the meta | `Time/TimezoneFallbackTest::testItReportsTheFallbackTimezoneStrategyInTheResultMeta` |
| Same daily buckets as `CONVERT_TZ` (Kolkata, Kathmandu) | `Time/TimezoneFallbackTest::testItReturnsTheSameDailyBucketsAsConvertTzForAsiaKolkataStores`, `::testItReturnsTheSameDailyBucketsAsConvertTzForAsiaKathmanduStores` |
| Same hourly buckets across both DST changes | `Time/TimezoneFallbackTest::testItReturnsTheSameHourlyBucketsAsConvertTzAcrossTheSpringForwardChangeInEuropeRome`, `::testItReturnsTheSameHourlyBucketsAsConvertTzAcrossTheFallBackChangeInEuropeRome` |
| Same weekly buckets with a store specific first day | `Time/TimezoneFallbackTest::testItReturnsTheSameWeeklyBucketsAsConvertTzWithAStoreSpecificFirstDay` |
| Non mergeable metrics are null with a warning | `Time/TimezoneFallbackTest::testItReturnsNonMergeableMetricsAsNullWithAWarning` |

### Detection and diagnostics

`TimezoneSupportDetector` checks once whether `CONVERT_TZ` works with named timezones and caches the outcome.
The diagnostics panel shows the strategy in use (row `Timezone strategy`: `convert_tz` or `utc_hour_fallback`).
To leave the fallback, load the MySQL timezone tables.

## Currency

### Currency of every financial metric

Every financial metric is in the **base currency** (`base_*` columns), never in the order, display or customer
currency. There is no conversion.

| Dataset | Money metrics | Source column(s) | Currency |
| --- | --- | --- | --- |
| `sales_orders` | `gross_sales`, `discounts`, `returns`, `net_sales`, `shipping`, `taxes`, `adjustments`, `total_sales`, `aov` | `base_subtotal`, `base_discount_amount`, `base_subtotal_refunded`, `base_shipping_amount`, `base_tax_amount`, `base_adjustment_*` (and their `_canceled`/`_refunded` parts) | `sales_order.base_currency_code` (base currency of the store) |
| `customer_orders` | same components, `total_spent`, `avg_shipping_cost` | same `base_*` columns | `sales_order.base_currency_code` |
| `sales_items` | `gross_sales`, `discounts`, `returns`, `net_sales`, `cost`, `gross_margin` (`margin_pct` is a ratio) | `base_row_total`, `base_discount_amount`, `base_amount_refunded`, `base_cost` | `sales_order.base_currency_code` of the order (join `order`) |
| `catalog_stock` | `stock_value_cost`, `stock_value_retail` | global (default scope) product `cost` and `price` | base currency of the **default scope** (R10) |

`catalog_salable` and `customers` have no money metric. Proof that base amounts are used even when the order was
placed in another currency: `Currency/BaseCurrencyAndPrecisionTest::testItReportsBaseCurrencyAmountsForAnOrderPlacedInAnotherDisplayCurrency`
and `::testItReportsTheBaseCurrencyCodeForAnOrderPlacedInAnotherDisplayCurrency`.

### Rejection of mixed base currencies

Amounts in different currencies are never added. A **monetary** request over several base currencies is rejected with
HTTP 400 (`InvalidRequestException`) instead of the former "mixed base currencies" warning, which was removed (R13).

A request is **monetary** when it contains any of:

- a metric with `money` format;
- a derived metric depending on a money aggregate (`margin_pct`, `return_rate`);
- a filter on a money field or metric.

Non monetary requests (orders, units, customers) across currencies stay allowed.

Two checks apply:

1. **Configuration** (`RequestValidator`, before the cache): the websites of the stores in scope (the selected stores,
   or every store for "All stores") have different `currency/options/base`. Only datasets with a `store_id` field are
   checked. The check also runs when **saving** a monetary report (the same 400 message is returned) and in the
   `advanced-report:explain` CLI. Message: "The selected stores use different base currencies (%1): select stores that
   share one base currency to report amounts."
2. **Data** (`ReportRunner`, MySQL): a probe `SELECT DISTINCT base_currency_code ... LIMIT 2` (with the guardrails
   timeout) over the orders in scope of the **main and the comparison** period. It catches historical base currency
   changes. More than one currency gives the 400 "The orders in the report period use different base currencies
   (%1): amounts cannot be added without conversion." The probe is not run for preset queries, non monetary requests or
   datasets without `base_currency_code`.

A `base_currency_code` filter with a single value does **not** bypass the configuration check. Export (CSV) runs the
same validation and returns its generic error on rejection. "All stores" on an install with several base currencies is
rejected for monetary reports; users must select stores sharing a currency (R36).

| Rule | Test |
| --- | --- |
| "All stores" money report, websites with different base currencies | `Currency/CurrencyRejectionTest::testItRejectsAMoneyReportForAllStoresWhenWebsitesHaveDifferentBaseCurrencies` |
| HTTP 400 from the data endpoint | `Currency/CurrencyRejectionTest::testItReturns400FromTheReportDataEndpointForAMixedCurrencyMoneyReport` |
| Orders and units reports still run | `Currency/CurrencyRejectionTest::testItRunsAnOrdersAndUnitsReportAcrossWebsitesWithDifferentBaseCurrencies` |
| Data probe on orders in the period | `Currency/CurrencyRejectionTest::testItRejectsAMoneyReportWhenOrdersInThePeriodCarryDifferentBaseCurrencies` |
| Comparison period with another currency | `Currency/CurrencyRejectionTest::testItRejectsAMoneyReportWhenTheComparisonPeriodUsesAnotherBaseCurrency` |

### `currency_code` of the result

- The single currency found by the data probe, when it ran.
- Otherwise `BaseCurrencyChecker::resolveCurrencyCode()`: the base currency of the stores in scope; the **default
  scope** for datasets without `store_id`; an empty string when currencies are mixed and the request is non monetary.

Test: `Currency/CurrencyRejectionTest::testItReportsTheWebsiteBaseCurrencyCodeForASingleWebsiteMoneyReport`.

### Rounding

The server does not round. `SUM` over `DECIMAL` columns is exact, derived ratios (`aov`, `margin_pct`, ...) are
unrounded floats, and the CSV export writes the raw values. Rounding is a display concern: the admin grid formats
with the browser `Intl` API using `currency_code`.

| Rule | Test |
| --- | --- |
| DECIMAL sums are exact | `Currency/BaseCurrencyAndPrecisionTest::testItSumsDecimalAmountsExactly` |
| Derived ratios are unrounded | `Currency/BaseCurrencyAndPrecisionTest::testItReturnsDerivedRatiosUnrounded` |
| CSV values equal the report result | `Currency/BaseCurrencyAndPrecisionTest::testItExportsMoneyValuesMatchingTheReportResult` |
