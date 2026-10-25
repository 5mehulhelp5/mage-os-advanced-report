# Performance

How much a report costs, which safeguards bound it, and how to measure it. Cache behaviour is in [cache](cache.md), index
rationale in [indexes](indexes.md). Risks are tracked in the [risk register](risk-register.md) (R16, R21, R37 and the p1e rows).

## Statements per report shape

Reports are live queries on the order tables. The runner counts every module-issued SQL statement of a result in
`meta.sql_query_count` (and their time in `meta.sql_time_ms`). Statement counts, as asserted by
`Test/Integration/Performance/QueryCountTest.php`:

| Shape | Statements | Notes |
| --- | --- | --- |
| Non-date request, first page incomplete (fewer rows than the limit) | 2 | page + totals. The row count is skipped: `totalRowCount = offset + rows of the page` (an empty later page still runs the count). |
| Full page (rows = limit) | 3 | page + totals + `SELECT COUNT(*) FROM (grouped select)`. |
| Monetary request (money metric or filter), incomplete first page | 3 | adds the currency probe (`SELECT DISTINCT base_currency_code ... LIMIT 2`), one statement per period. |
| Comparison period (non-monetary, incomplete pages) | 4 | main page + totals, comparison page + totals. The comparison never runs a row count. |
| Date bucket request (`order_date` dimension, merged in PHP) | 2 | page + totals; the page is unpaged: the whole range up to `max_rows` rows. |
| `dead_stock`, `never_sold` (anti-join presets) | 2 | page + totals (the row count comes from the totals statement). |
| `low_stock_coverage` | stock execution + the nested sales run | the nested run reports its own count. |
| Warm result (served from cache) | 0 | `sql_query_count = 0`; the cold values are kept in `meta.cached_sql_query_count` / `meta.cached_sql_time_ms`. |

What pagination does and does not limit: the page `LIMIT` bounds the rows transferred to PHP, not the work of the
database. The grouped aggregation still reads and groups every row of the period; page 2 costs as much as page 1 (plus the
count when the page is full). Date bucket requests are unpaged by design (the buckets are merged in PHP).

## Safeguards

- **Timeout per statement**: `performance/query_timeout_ms` (default 30 000) is applied as `MAX_EXECUTION_TIME` (MySQL) or
  `max_statement_time` (MariaDB) to every statement on the report path: page, count, totals, comparison, currency probe,
  stock queries, order status labels, customer names, stock labels, the salable stock lookup. A timeout surfaces as
  HTTP 504 with a hint to shorten the period or reduce dimensions.
  Known exceptions: the label lookups that go through core repositories (product names, customer groups, attribute option
  labels) and memoized detector probes (database flavour, timezone table support) do not carry the hint.
- **`max_rows`** (default 10 000): hard cap on the rows of a query; larger results are truncated with a warning. The CSV export is capped by it too.
- **Granularity limits** (`RequestValidator`): `day` granularity needs a range of at most 400 days, `hour` at most 31 days.
- **First-order lookup**: new vs returning reports accept a range of at most 12 months (`FIRST_ORDER_MAX_MONTHS`).
- **Timezone fallback row cap**: without database timezone tables the UTC slot query is capped at `max_rows x 50` rows
  (`SelectBuilder::FALLBACK_ROW_CAP_FACTOR`); more rows produce the truncation warning (R17).
- **Export**: one runner execution (limit `max_rows`, offset 0), written row by row to a temporary file under
  `var/export/mageos_advancedreport/` and sent with `FileFactory` (the file is removed after the download; a partial file is
  deleted when writing fails). The export never stores its result in the cache and takes no cache lock; it reads a cached
  result if one exists. Memory is bounded by one runner result (at most `max_rows` rows held in PHP); no full CSV string is
  built. Memory is not constant: it grows with the number of rows.
- **Unreachable or unknown report connection**: HTTP 503 with an explicit message and a log entry (export: admin error).
  There is no silent fallback to the default connection.
- **Failures are never cached**: the result is saved only after a successful execution.
- **Stampede lock**: concurrent identical misses run the report once (see [cache](cache.md#lock)).

Product name labels on export load up to `max_rows` products through `ProductRepositoryInterface::getList()`; this is a known
bottleneck of large exports.

## Benchmark procedure

Tooling only ships with the module; the maintainer runs the measurements. Commands run from the Magento root with the
standard Magento CLI and the MySQL client. `<module-dir>` is the module directory (`app/code/MageOS/AdvancedReport`, or
`vendor/mageos/module-advanced-report` when installed with Composer) and `<db>` the Magento database (`db/connection/default/dbname`
in `app/etc/env.php`). The seed modifies the **main** database: use a development copy, never a real shop, and back it up first.

1. Back up:

   ```bash
   mysqldump --single-transaction --routines --triggers <db> | gzip > advanced-report-benchmark-backup.sql.gz
   ```

2. Seed with the Magento performance toolkit (module profiles in `dev/benchmark/profiles/`), then apply the deterministic
   spread (`dev/benchmark/spread-orders.sql`) and clean the report cache:

   ```bash
   bin/magento setup:performance:generate-fixtures <module-dir>/dev/benchmark/profiles/medium.xml
   mysql <db> < <module-dir>/dev/benchmark/spread-orders.sql
   bin/magento cache:clean advanced_report
   ```

   Profiles: `small.xml` (1 000 orders, 200 customers), `medium.xml` (50 000 orders, 20 000 customers, 24 000 products),
   `large.xml` (500 000 orders, 25 000 customers, 50 000 products). Run the spread only if the toolkit command succeeded.

   Measured durations (see [Results](#results)): small about 1 minute; medium 7 min 22 s for the fixtures plus about 55 min
   for the spread; large 28 min 48 s for the fixtures, while the shipped spread did not finish in 20 hours (an indexed copy of
   the script finished in 304 s). A fix to the shipped `spread-orders.sql` is planned. Disk space and time depend on the machine.

   Caveats:
   - `<orders>` in the profiles is a **total target**, not an increment (seeding small then medium adds the difference).
   - The spread dates are anchored on the seed day (`@anchor = CURDATE()`): they cover the 24 months before that day. Re-run
     `spread-orders.sql` before benchmarking on a later day so "last 30 days" still contains orders.
   - Toolkit orders are in USD, whatever the base currency of the shop; currencies are not rewritten.
   - The spread assigns about 60 % of the orders to registered repeat customers and mixes statuses (70 % complete, 15 %
     processing, 10 % pending, 5 % canceled); the rest are guests, some with repeated emails.
   - No refunds or credit memos are generated: return metrics are zero.
   - The module profiles strip the toolkit `<configs>` and `<indexers>` sections, so configuration and indexer modes are untouched.

3. Benchmark:

   ```bash
   bin/magento advanced-report:benchmark --profile-label=medium --repeat=3 --format=markdown
   bin/magento advanced-report:benchmark --profile-label=medium --repeat=3 --range=last_30_days --budget-ms=3000
   bin/magento advanced-report:benchmark --concurrency=8 --preset=sales_by_category
   ```

   The concurrency mode **cleans the whole `advanced_report` cache type** (no confirmation) and needs filters that select
   exactly one scenario. Only `sales_by_category` (the `category_12m` scenario) is a single scenario today: the dated matrix
   presets (for example `sales_overview`) build 2 store options x 2 compare modes and cannot currently be isolated for the
   concurrency mode with `--store` / `--compare` (see [R43](risk-register.md)). See [`advanced-report:benchmark`](../README.md#advanced-reportbenchmark) for the options.

4. Inspect the plans of the same presets:

   ```bash
   bin/magento advanced-report:explain --preset=sales_overview --from=2026-01-01 --to=2026-01-31
   ```

5. Optionally time the index builds on copies of the order tables (needs free disk space, never alters the real tables; see
   [indexes](indexes.md#installation-time-and-maintenance-window)):

   ```bash
   mysql <db> < <module-dir>/dev/benchmark/index-timing.sql
   ```

6. Restore the database:

   ```bash
   gunzip < advanced-report-benchmark-backup.sql.gz | mysql <db>
   bin/magento cache:flush
   ```

   The dump contains `DROP TABLE IF EXISTS` for every table it holds; tables created after the backup are not removed.

### Authorization of the benchmark command

A CLI process has no admin session, so `advanced-report:benchmark` runs under a trusted CLI authorization
(`TrustedCliContext` enabled by the command only, `TrustedCliAuthorization` injected into `DatasetAccess`, `AclContext` and
`RequestValidator`). It skips the dataset ACL gates like the other CLI commands (R32). See [security](security.md#trusted-cli-authorization-benchmark).

### Reading the output

Each scenario is run `--repeat` times cold (cache bypassed) and then `--repeat` times warm. Columns: cold median and p95,
SQL statements (cold), rows examined (sum of `Handler_read_*` deltas, `n/a` when the server does not expose them),
warm median, warm SQL statements (expected 0), peak PHP memory. Scenario codes are `preset:range:store:compare`.

## Budgets

- Cold median below **3 000 ms on the medium profile** for the 30-day presets (`--budget-ms=3000`: scenarios over the budget are
  listed and the command exits with 1).
- The large profile is recorded only, with no budget.

Outcome on the medium profile (`--range=last_30_days --budget-ms=3000`, seed day 2026-10-24): none over budget. The slowest
30-day scenario is `sales_overview:last_30_days:all_stores:previous_period` with a cold median of 438.9 ms.

## Results

The numbers below come from one machine on the seed day; they depend on the hardware and must be measured again on the
target environment with `bin/magento advanced-report:benchmark --format=markdown`. Each profile was run with
`--profile-label=<profile> --repeat=3 --format=markdown`.

Environment: MySQL 8.4.4 in Docker, AMD Ryzen 7 5700G (8 cores / 16 threads), 27 GB RAM. Seed day: small and medium
2026-10-24; large fixtures 2026-10-24, spread anchored on 2026-10-25, benchmark run on 2026-10-25.

Profile sizes: small 1 000 orders, 2 000 order items; medium 50 000 orders, 100 000 order items; large 500 000 orders,
1 000 000 order items.

Large seed deviation: the shipped `dev/benchmark/spread-orders.sql` stalled on the large profile. Its multi-table `UPDATE`
joins temporary tables that have no index, so MySQL runs a nested loop. The customer `UPDATE` ran about 20.8 hours and was
killed (clean rollback). The large data was spread with a copy of the script that adds
`ALTER TABLE tmp_ar_customers ADD INDEX (position)` and `ALTER TABLE tmp_ar_guest_emails ADD INDEX (entity_id)`, which
finished in 304 s. The medium spread with the shipped script took about 55 minutes. A fix to the shipped script is planned.

Large timeouts: `sales_overview` (12 months), `new_vs_returning` (12 months) and `dead_stock` aborted on the 30 s statement
timeout (`performance/query_timeout_ms`) and are recorded as "timeout (> 30 s)". The benchmark aborts the whole preset run
on a timeout, so the other store and compare variants of those presets have no numbers either. `three_dimensions_12m` and
`new_vs_returning_12m` have no preset and were not measured on large (a full run aborts on the timeouts). Timeouts are an
accepted result and were not investigated. The large results come from one run per preset and range. A cold median can exceed
30 s, because one scenario runs several statements and each one stays under the timeout.

| Profile | Scenario | Cold median (ms) | Cold p95 (ms) | SQL statements | Rows examined | Warm median (ms) | Warm SQL statements | Peak memory (MB) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| small | sales_overview:last_30_days:all_stores:none | 4.0 | 9.3 | 3 | 519 | 0.3 | 0 | 0.0 |
| small | sales_overview:last_30_days:all_stores:previous_period | 7.0 | 7.3 | 6 | 1036 | 0.5 | 0 | 0.0 |
| small | sales_overview:last_30_days:store_1:none | 3.7 | 3.9 | 3 | 519 | 0.3 | 0 | 0.0 |
| small | sales_overview:last_30_days:store_1:previous_period | 7.1 | 7.2 | 6 | 1036 | 0.4 | 0 | 0.0 |
| small | sales_overview:last_12_months:all_stores:none | 24.1 | 24.9 | 3 | 7808 | 0.9 | 0 | 2.0 |
| small | sales_overview:last_12_months:all_stores:previous_period | 51.4 | 66.5 | 6 | 17259 | 2.8 | 0 | 4.0 |
| small | sales_overview:last_12_months:store_1:none | 24.1 | 24.9 | 3 | 7808 | 0.9 | 0 | 0.0 |
| small | sales_overview:last_12_months:store_1:previous_period | 53.0 | 59.9 | 6 | 17259 | 2.8 | 0 | 2.0 |
| small | new_vs_returning:last_30_days:all_stores:none | 2.1 | 2.7 | 3 | 216 | 0.2 | 0 | 0.0 |
| small | new_vs_returning:last_30_days:all_stores:previous_period | 3.6 | 3.7 | 6 | 431 | 0.2 | 0 | 0.0 |
| small | new_vs_returning:last_30_days:store_1:none | 2.0 | 2.2 | 3 | 216 | 0.2 | 0 | 0.0 |
| small | new_vs_returning:last_30_days:store_1:previous_period | 3.6 | 3.7 | 6 | 431 | 0.2 | 0 | 0.0 |
| small | new_vs_returning:last_12_months:all_stores:none | 8.9 | 9.5 | 3 | 4031 | 0.2 | 0 | 0.0 |
| small | new_vs_returning:last_12_months:all_stores:previous_period | 17.0 | 22.6 | 6 | 8878 | 0.2 | 0 | 0.0 |
| small | new_vs_returning:last_12_months:store_1:none | 8.0 | 8.3 | 3 | 4031 | 0.2 | 0 | 0.0 |
| small | new_vs_returning:last_12_months:store_1:previous_period | 15.6 | 17.3 | 6 | 8878 | 0.2 | 0 | 0.0 |
| small | product_performance:last_30_days:all_stores:none | 2.2 | 2.9 | 3 | 479 | 0.2 | 0 | 0.0 |
| small | product_performance:last_30_days:all_stores:previous_period | 3.9 | 3.9 | 6 | 958 | 0.3 | 0 | 0.0 |
| small | product_performance:last_30_days:store_1:none | 2.2 | 2.6 | 3 | 479 | 0.2 | 0 | 0.0 |
| small | product_performance:last_30_days:store_1:previous_period | 3.7 | 4.0 | 6 | 958 | 0.2 | 0 | 0.0 |
| small | product_performance:last_12_months:all_stores:none | 4.0 | 4.5 | 3 | 6011 | 0.2 | 0 | 0.0 |
| small | product_performance:last_12_months:all_stores:previous_period | 7.3 | 7.4 | 6 | 12022 | 0.2 | 0 | 0.0 |
| small | product_performance:last_12_months:store_1:none | 3.9 | 4.1 | 3 | 6011 | 0.2 | 0 | 0.0 |
| small | product_performance:last_12_months:store_1:previous_period | 7.3 | 7.3 | 6 | 12022 | 0.2 | 0 | 0.0 |
| small | customer_value:last_30_days:all_stores:none | 4.4 | 6.3 | 3 | 1087 | 0.3 | 0 | 0.0 |
| small | customer_value:last_30_days:all_stores:previous_period | 8.3 | 8.5 | 6 | 1776 | 0.4 | 0 | 0.0 |
| small | customer_value:last_30_days:store_1:none | 4.4 | 4.5 | 3 | 896 | 0.3 | 0 | 0.0 |
| small | customer_value:last_30_days:store_1:previous_period | 8.5 | 8.5 | 6 | 1776 | 0.4 | 0 | 0.0 |
| small | customer_value:last_12_months:all_stores:none | 19.9 | 20.5 | 3 | 8157 | 0.6 | 0 | 0.0 |
| small | customer_value:last_12_months:all_stores:previous_period | 40.5 | 40.6 | 6 | 16311 | 1.3 | 0 | 0.0 |
| small | customer_value:last_12_months:store_1:none | 19.7 | 20.3 | 3 | 8157 | 0.6 | 0 | 0.0 |
| small | customer_value:last_12_months:store_1:previous_period | 40.0 | 40.0 | 6 | 16311 | 1.3 | 0 | 0.0 |
| small | stock_value:all_stores | 26.7 | 26.8 | 2 | 16746 | 1.5 | 0 | 0.0 |
| small | stock_value:store_1 | 24.2 | 24.3 | 2 | 16665 | 1.5 | 0 | 2.0 |
| small | low_stock_coverage:all_stores | 20.6 | 23.0 | 4 | 16984 | 1.3 | 0 | 0.0 |
| small | low_stock_coverage:store_1 | 22.7 | 26.6 | 4 | 16984 | 1.3 | 0 | 0.0 |
| small | dead_stock:all_stores | 30.3 | 31.2 | 2 | 25311 | 1.2 | 0 | 0.0 |
| small | dead_stock:store_1 | 30.4 | 30.8 | 2 | 25311 | 1.2 | 0 | 0.0 |
| small | three_dimensions_12m | 18.7 | 26.8 | 3 | 8437 | 0.2 | 0 | 0.0 |
| small | category_12m | 3.5 | 4.2 | 3 | 6011 | 0.2 | 0 | 0.0 |
| small | new_vs_returning_12m | 7.9 | 8.0 | 3 | 4031 | 0.2 | 0 | 0.0 |
| medium | sales_overview:last_30_days:all_stores:none | 72.8 | 255.6 | 3 | 24011 | 0.3 | 0 | 0.0 |
| medium | sales_overview:last_30_days:all_stores:previous_period | 438.9 | 447.1 | 6 | 48752 | 0.5 | 0 | 2.0 |
| medium | sales_overview:last_30_days:store_1:none | 46.7 | 90.0 | 3 | 15956 | 0.3 | 0 | 0.0 |
| medium | sales_overview:last_30_days:store_1:previous_period | 99.7 | 107.2 | 6 | 32075 | 0.5 | 0 | 0.0 |
| medium | sales_overview:last_12_months:all_stores:none | 903.8 | 1027.1 | 3 | 386093 | 1.0 | 0 | 0.0 |
| medium | sales_overview:last_12_months:all_stores:previous_period | 1542.5 | 1562.3 | 6 | 788169 | 3.4 | 0 | 6.0 |
| medium | sales_overview:last_12_months:store_1:none | 476.5 | 484.5 | 3 | 204499 | 0.9 | 0 | 0.0 |
| medium | sales_overview:last_12_months:store_1:previous_period | 956.1 | 957.4 | 6 | 417296 | 2.9 | 0 | 0.0 |
| medium | new_vs_returning:last_30_days:all_stores:none | 30.3 | 34.5 | 3 | 10260 | 0.2 | 0 | 0.0 |
| medium | new_vs_returning:last_30_days:all_stores:previous_period | 62.7 | 64.2 | 6 | 20827 | 0.2 | 0 | 0.0 |
| medium | new_vs_returning:last_30_days:store_1:none | 22.2 | 24.4 | 3 | 8350 | 0.2 | 0 | 0.0 |
| medium | new_vs_returning:last_30_days:store_1:previous_period | 47.2 | 47.5 | 6 | 16871 | 0.2 | 0 | 0.0 |
| medium | new_vs_returning:last_12_months:all_stores:none | 333.5 | 335.6 | 3 | 207436 | 0.2 | 0 | 0.0 |
| medium | new_vs_returning:last_12_months:all_stores:previous_period | 666.0 | 666.0 | 6 | 422644 | 0.2 | 0 | 0.0 |
| medium | new_vs_returning:last_12_months:store_1:none | 227.6 | 230.7 | 3 | 108584 | 0.2 | 0 | 0.0 |
| medium | new_vs_returning:last_12_months:store_1:previous_period | 452.0 | 454.7 | 6 | 221180 | 0.2 | 0 | 0.0 |
| medium | product_performance:last_30_days:all_stores:none | 45.4 | 50.5 | 3 | 23030 | 0.2 | 0 | 0.0 |
| medium | product_performance:last_30_days:all_stores:previous_period | 87.3 | 88.8 | 6 | 46756 | 0.2 | 0 | 0.0 |
| medium | product_performance:last_30_days:store_1:none | 31.8 | 33.5 | 3 | 15407 | 0.2 | 0 | 0.0 |
| medium | product_performance:last_30_days:store_1:previous_period | 61.8 | 62.0 | 6 | 30979 | 0.2 | 0 | 0.0 |
| medium | product_performance:last_12_months:all_stores:none | 130.9 | 131.4 | 3 | 300011 | 0.2 | 0 | 0.0 |
| medium | product_performance:last_12_months:all_stores:previous_period | 260.3 | 261.5 | 6 | 600022 | 0.2 | 0 | 0.0 |
| medium | product_performance:last_12_months:store_1:none | 300.4 | 303.0 | 3 | 190787 | 0.2 | 0 | 0.0 |
| medium | product_performance:last_12_months:store_1:previous_period | 602.7 | 605.6 | 6 | 382060 | 0.2 | 0 | 0.0 |
| medium | customer_value:last_30_days:all_stores:none | 123.4 | 233.4 | 3 | 31574 | 2.9 | 0 | 4.0 |
| medium | customer_value:last_30_days:all_stores:previous_period | 260.2 | 275.8 | 6 | 63814 | 10.5 | 0 | 16.0 |
| medium | customer_value:last_30_days:store_1:none | 77.1 | 78.2 | 3 | 19468 | 1.5 | 0 | 2.0 |
| medium | customer_value:last_30_days:store_1:previous_period | 159.2 | 163.3 | 6 | 39093 | 5.0 | 0 | 2.0 |
| medium | customer_value:last_12_months:all_stores:none | 965.7 | 975.0 | 4 | 453842 | 22.8 | 0 | 29.1 |
| medium | customer_value:last_12_months:all_stores:previous_period | 1888.7 | 1946.0 | 7 | 778073 | 60.0 | 0 | 77.9 |
| medium | customer_value:last_12_months:store_1:none | 670.4 | 671.5 | 3 | 194114 | 21.5 | 0 | 8.5 |
| medium | customer_value:last_12_months:store_1:previous_period | 1444.7 | 1445.0 | 6 | 388688 | 59.2 | 0 | 18.3 |
| medium | stock_value:all_stores | 1114.9 | 1270.6 | 3 | 793089 | 22.7 | 0 | 9.0 |
| medium | stock_value:store_1 | 1111.7 | 1169.1 | 3 | 793008 | 22.5 | 0 | 7.0 |
| medium | low_stock_coverage:all_stores | 951.8 | 1074.8 | 5 | 808361 | 18.9 | 0 | 4.0 |
| medium | low_stock_coverage:store_1 | 972.7 | 1003.3 | 5 | 803279 | 18.8 | 0 | 6.0 |
| medium | dead_stock:all_stores | 1010.0 | 1280.6 | 2 | 886577 | 19.0 | 0 | 6.0 |
| medium | dead_stock:store_1 | 1009.7 | 1015.1 | 2 | 886577 | 19.1 | 0 | 6.0 |
| medium | three_dimensions_12m | 332.7 | 496.0 | 3 | 292211 | 0.2 | 0 | 0.0 |
| medium | category_12m | 120.6 | 214.9 | 3 | 300011 | 0.2 | 0 | 0.0 |
| medium | new_vs_returning_12m | 340.0 | 346.4 | 3 | 207436 | 0.2 | 0 | 0.0 |
| large | sales_overview:last_30_days:all_stores:none | 18142.0 | 20585.8 | 3 | 350580 | 0.3 | 0 | 0.0 |
| large | sales_overview:last_30_days:all_stores:previous_period | 31488.9 | 32738.1 | 6 | 713622 | 0.5 | 0 | 2.0 |
| large | sales_overview:last_30_days:store_1:none | 10855.5 | 10869.3 | 3 | 272620 | 0.3 | 0 | 0.0 |
| large | sales_overview:last_30_days:store_1:previous_period | 22279.2 | 22519.3 | 6 | 551470 | 0.5 | 0 | 0.0 |
| large | new_vs_returning:last_30_days:all_stores:none | 7146.4 | 7295.2 | 3 | 217699 | 0.3 | 0 | 0.0 |
| large | new_vs_returning:last_30_days:all_stores:previous_period | 15874.6 | 15889.8 | 6 | 442879 | 0.2 | 0 | 0.0 |
| large | new_vs_returning:last_30_days:store_1:none | 6798.5 | 6866.4 | 3 | 200224 | 0.2 | 0 | 0.0 |
| large | new_vs_returning:last_30_days:store_1:previous_period | 13956.5 | 14196.7 | 6 | 406378 | 0.2 | 0 | 0.0 |
| large | product_performance:last_30_days:all_stores:none | 11445.3 | 11501.7 | 3 | 349367 | 0.2 | 0 | 0.0 |
| large | product_performance:last_30_days:all_stores:previous_period | 23574.6 | 23600.6 | 6 | 709984 | 0.2 | 0 | 0.0 |
| large | product_performance:last_30_days:store_1:none | 8564.1 | 8725.6 | 3 | 271949 | 0.2 | 0 | 0.0 |
| large | product_performance:last_30_days:store_1:previous_period | 18090.2 | 18090.2 | 6 | 549514 | 0.3 | 0 | 0.0 |
| large | product_performance:last_12_months:all_stores:none | 5752.7 | 5771.6 | 3 | 3000011 | 0.2 | 0 | 0.0 |
| large | product_performance:last_12_months:all_stores:previous_period | 11497.9 | 11502.6 | 6 | 6000022 | 0.3 | 0 | 0.0 |
| large | product_performance:last_12_months:store_1:none | 23058.6 | 23063.4 | 3 | 1875947 | 0.2 | 0 | 0.0 |
| large | product_performance:last_12_months:store_1:previous_period | 46110.7 | 46138.3 | 6 | 3757060 | 0.3 | 0 | 0.0 |
| large | customer_value:last_30_days:all_stores:none | 11342.3 | 11980.7 | 4 | 792252 | 22.2 | 0 | 53.2 |
| large | customer_value:last_30_days:all_stores:previous_period | 21647.3 | 21732.4 | 7 | 1457467 | 60.9 | 0 | 77.9 |
| large | customer_value:last_30_days:store_1:none | 13814.8 | 13833.2 | 4 | 1061923 | 23.8 | 0 | 9.1 |
| large | customer_value:last_30_days:store_1:previous_period | 26635.9 | 26861.4 | 7 | 2028727 | 64.2 | 0 | 19.8 |
| large | customer_value:last_12_months:all_stores:none | 35259.0 | 35390.1 | 4 | 4780594 | 21.8 | 0 | 53.1 |
| large | customer_value:last_12_months:all_stores:previous_period | 65534.9 | 65907.6 | 7 | 8304514 | 64.4 | 0 | 77.7 |
| large | customer_value:last_12_months:store_1:none | 31306.8 | 31544.2 | 4 | 2829866 | 27.9 | 0 | 9.1 |
| large | customer_value:last_12_months:store_1:previous_period | 56953.4 | 57592.2 | 7 | 4964868 | 72.5 | 0 | 18.4 |
| large | category_12m | 5718.3 | 5719.6 | 3 | 3000011 | 0.2 | 0 | 0.0 |
| large | stock_value:all_stores | 2451.2 | 2872.3 | 3 | 1830785 | 22.1 | 0 | 39.0 |
| large | stock_value:store_1 | 2443.0 | 2464.0 | 3 | 1830785 | 21.2 | 0 | 9.0 |
| large | low_stock_coverage:all_stores | 1852.6 | 10466.1 | 5 | 1864377 | 18.4 | 0 | 36.0 |
| large | low_stock_coverage:store_1 | 1828.9 | 8399.3 | 5 | 1812765 | 19.5 | 0 | 6.0 |
| large | sales_overview:last_12_months:all_stores:none | timeout (> 30 s) | | | | | | |
| large | sales_overview:last_12_months:all_stores:previous_period | timeout (> 30 s) | | | | | | |
| large | sales_overview:last_12_months:store_1:none | timeout (> 30 s) | | | | | | |
| large | sales_overview:last_12_months:store_1:previous_period | timeout (> 30 s) | | | | | | |
| large | new_vs_returning:last_12_months:all_stores:none | timeout (> 30 s) | | | | | | |
| large | new_vs_returning:last_12_months:all_stores:previous_period | timeout (> 30 s) | | | | | | |
| large | new_vs_returning:last_12_months:store_1:none | timeout (> 30 s) | | | | | | |
| large | new_vs_returning:last_12_months:store_1:previous_period | timeout (> 30 s) | | | | | | |
| large | dead_stock:all_stores | timeout (> 30 s) | | | | | | |
| large | dead_stock:store_1 | timeout (> 30 s) | | | | | | |
| large | three_dimensions_12m | not measured | | | | | | |
| large | new_vs_returning_12m | not measured | | | | | | |

Concurrency (`bin/magento advanced-report:benchmark --concurrency=8 --preset=sales_by_category --profile-label=<profile>`,
scenario `category_12m`):

| Profile | Workers | SQL executed | Served from cache | Late workers | Max wall (ms) | Median wall (ms) |
| --- | --- | --- | --- | --- | --- | --- |
| medium | 8 | 1 | 7 | 0 | 236.4 | 234.0 |
| large | 8 | 8 | 0 | 0 | 23016.9 | 23015.8 |

On large, worker 1 took 12.7 s and the other seven about 23.0 s. This matches the lock fallback in [cache](cache.md#lock):
the cold `category_12m` query on large (about 12.7 s) is longer than the 10 s lock wait, so the waiting workers time out and
each runs the report itself without the lock.

## Known bottlenecks

- **First-order `NOT EXISTS`** (new vs returning, customer type): one correlated lookup per order, served by
  `SALES_ORDER_CUSTOMER_ID_CREATED_AT` and `SALES_ORDER_CUSTOMER_EMAIL_CREATED_AT`.
- **`COUNT(DISTINCT)` customer metrics**: cannot be pre-aggregated or re-aggregated; they need a full pass over the period.
- **Category fan-out** (`category_id`): one row per product category multiplies the rows to aggregate (R01).
- **Anti-joins** (`dead_stock`, `never_sold`): one lookup per stock row into `sales_order_item`, served by `SALES_ORDER_ITEM_PRODUCT_ID_ORDER_ID`.
- **Currency probe** (R37): one indexed `DISTINCT base_currency_code ... LIMIT 2` per period on monetary requests; its cost
  on very large order tables is part of the benchmark (statement counted in `sql_query_count`). Observed: the benchmark does
  not time the probe separately, so its cost is included in the cold medians. The date presets run 3 statements per period
  with a monetary request (page, totals, probe) and 6 with a previous-period comparison, on every profile.
- **Row count over the grouped subquery**: when the page is full the aggregation runs a second time to count the groups.
- **Product name labels on export**: up to `max_rows` products loaded through the product repository.

## Asynchronous execution (evaluated, not built)

Options considered: a message queue consumer that computes the report and a client that polls for the result; a cron job
that pre-warms the cache of the common presets. Both add moving parts (consumer process, result storage, status API, ACL
snapshot of the requesting user, stale pre-warmed data) and live queries with a 30 s timeout, the cache and the lock are enough
while reports stay within the interactive budget. Trigger to revisit: reports that regularly exceed the budget on production
data, as shown by the benchmark.

## After upgrading

Constructors of controllers and services changed. Regenerate the generated code (`setup:di:compile` in production, clear
`generated/` in developer mode) and run `setup:upgrade` for the new indexes (R33).
