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

   Estimated duration (estimate, not measured): small a few minutes, medium tens of minutes to about an hour, large several
   hours and a large increase of the database size. Disk space and time depend on the machine.

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
   bin/magento advanced-report:benchmark --concurrency=8 --preset=sales_overview --range=last_30_days
   ```

   The concurrency mode **cleans the whole `advanced_report` cache type** (no confirmation) and needs filters that select
   exactly one scenario. See [`advanced-report:benchmark`](../README.md#advanced-reportbenchmark) for the options.

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

## Results

No numbers are shipped: they depend on the hardware and must be measured. Fill the tables with the output of
`bin/magento advanced-report:benchmark --format=markdown`.

Environment: DB server and version `to be filled by the maintainer`, CPU/RAM `to be filled by the maintainer`, seed day `to be filled by the maintainer`.

| Profile | Scenario | Cold median (ms) | Cold p95 (ms) | SQL statements | Rows examined | Warm median (ms) | Warm SQL statements | Peak memory (MB) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| small | to be filled by the maintainer | | | | | | | |
| medium | to be filled by the maintainer | | | | | | | |
| large | to be filled by the maintainer | | | | | | | |

Concurrency (`--concurrency=N`): workers, statements executed, served from cache, late workers, max and median wall time
`to be filled by the maintainer`.

## Known bottlenecks

- **First-order `NOT EXISTS`** (new vs returning, customer type): one correlated lookup per order, served by
  `SALES_ORDER_CUSTOMER_ID_CREATED_AT` and `SALES_ORDER_CUSTOMER_EMAIL_CREATED_AT`.
- **`COUNT(DISTINCT)` customer metrics**: cannot be pre-aggregated or re-aggregated; they need a full pass over the period.
- **Category fan-out** (`category_id`): one row per product category multiplies the rows to aggregate (R01).
- **Anti-joins** (`dead_stock`, `never_sold`): one lookup per stock row into `sales_order_item`, served by `SALES_ORDER_ITEM_PRODUCT_ID_ORDER_ID`.
- **Currency probe** (R37): one indexed `DISTINCT base_currency_code ... LIMIT 2` per period on monetary requests; its cost
  on very large order tables is part of the benchmark (statement counted in `sql_query_count`), results pending the maintainer run.
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
