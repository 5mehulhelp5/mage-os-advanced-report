# Result cache

Report results are stored in the `advanced_report` cache type (`Model/Cache/Type`, tag `CACHE_TAG`). Freshness is TTL-only
by decision (R21); this page lists what is in the key, how long entries live, how they are invalidated and how fresh each
report category is. Costs and safeguards are in [performance](performance.md).

## Key

`ResultCache::buildKey()` hashes (sha256) a recursively key-sorted payload of:

| Component | Detail |
| --- | --- |
| Request | the normalized request (dataset, metrics, dimensions, filters, sort, paging, comparison) without `bypassCache`, `source` and `storeIds` |
| Source | the resolved data source code |
| Store ids | sorted list |
| Config version | `DefinitionRegistry::getConfigVersion()`: hash of the semantic layer (datasets, metrics, presets) |
| Counted statuses | sorted list from `sales/counted_statuses` |
| Resolved dates | the main and comparison ranges after resolution ("last 30 days" changes key every day) |
| Locale | the admin locale |
| ACL context | `AclContext::getFingerprint()`: users with different permissions never share an entry, see [security](security.md#cache) |
| Scope fingerprint | the timezone(s) of the scope, the first day of week and the base currencies of the stores, so changing a store timezone, first day or base currency produces a new key |
| Result config | `ResultConfigFingerprint`: report attributes (sorted), dead stock days, max rows, report connection name, `cataloginventory/item_options/min_qty`, `carts/abandoned_after_hours` and `carts/recent_cart_hours` |

The result-config component holds the settings that change the result itself. The query timeout and the two cache TTLs are
left out on purpose: they change how a report runs or how long an entry lives, never its content, so changing them keeps the
existing entries valid (`ResultConfigKeyTest::testItKeepsServingTheCachedEntryWhenOnlyTheQueryTimeoutChanges`). The component
is global: a `min_qty` change gives every report, sales reports included, a new key.

Each setting is proven to produce a new key by `ResultConfigKeyTest` (report attributes, dead stock days, max rows,
inventory `min_qty`, connection name).

Cached results go through a JSON round trip: a float without fractional part comes back as an integer (`10.0` becomes `10`).
Values are equal, types may differ; tests compare cached and fresh rows with `assertEquals` for this reason.

## What is not in the key

The key covers the merged XML definitions, not the PHP and SQL behind them. A change that lives only in a field expression
provider or in `di.xml` (for example the cart email fallback of `CartConditionBuilder`, the dynamic bundle rollup, the first
order expression, the credit memo excluded states or the timezone fallback branch) does not change the key: after upgrading
the module, run `bin/magento cache:clean advanced_report`, or stale entries keep being served until their TTL.

## TTL

| Setting | Default | Applies to |
| --- | --- | --- |
| `mageos_advancedreport/performance/cache_ttl_open_period` | 300 s | the main or comparison range includes today |
| `mageos_advancedreport/performance/cache_ttl_closed_period` | 86 400 s | every other result, **including reports without a date range** (stock datasets and presets have no range, so they use the closed TTL) |

Exception: the cart datasets (`carts`, `cart_items`) always use the **open-period** TTL, whatever the date range. Their
abandoned and recent thresholds move with the clock, so even a closed range changes (`ReportRunner` `timeRelativeDatasets`
in `etc/di.xml`). The two cart settings (`carts/abandoned_after_hours`, `carts/recent_cart_hours`, the latter capped at 120)
are part of the result-config key, so changing them gives new entries.

## Invalidation

- Saving the `mageos_advancedreport` configuration section cleans the whole cache type (`CleanReportCacheObserver`). The
  result-changing settings (report attributes, dead stock days, counted statuses, connection) are already part of the key, so
  the clean only frees the entries that would otherwise wait for their TTL.
- Changes made outside the admin (`bin/magento config:set`, `app:config:import`) do not run the observer, but they produce a
  new key through the result-config component, so the old entries are no longer read and expire by TTL (R45). The admin
  save still cleans the type.
- `bin/magento cache:clean advanced_report` (or `cache:flush`).
- The Refresh button sends `bypassCache`: the report is executed and the entry is overwritten (the lock is not taken).
- `advanced-report:benchmark --concurrency=N` cleans the whole cache type before starting the workers.
- There is no event-driven invalidation on order, refund or shipment events (R21, accepted).

## Labels

Labels are part of the cached result. Renames of products, categories, stores, customer groups, attribute options and status
labels stay stale until Refresh or the TTL (R44, accepted); `CatalogInvalidationTest::testItKeepsServingThePreviousProductNameLabelUntilTheCacheIsBypassed`
proves it for a product name.

## Lock

On a miss the runner takes a per-key lock (`ResultCacheLock`, `LockManagerInterface`, lock name
`mageos_advancedreport_<key>`), re-reads the cache once it holds the lock, and executes and saves only if the entry is still
missing: concurrent identical misses run the report once and the waiting requests are served from the cache.

- Wait time: the query timeout rounded up to whole seconds plus a 2 second margin, so a waiter outlasts the run it waits for; 60 seconds when the timeout is 0 (unlimited). Long waits inside an admin request can hit PHP-FPM or proxy timeouts. The lock is skipped altogether when the `advanced_report` cache type is disabled.
- If the lock cannot be taken (wait timeout, or any exception from the lock backend, for example a MySQL user lock deadlock
  with the nested run of `low_stock_coverage`), the report is executed without the lock and the request never fails
  because of it (this is the lock fallback under contention: duplicated work, still correct).
- A cache hit takes no lock. Refresh (`bypassCache`) executes and saves without the lock.
- The default backend is the database (`GET_LOCK`), which is re-entrant per DB session: contention only exists across processes.
- Evidence for the stampede protection is the stub-based lock tests plus the `advanced-report:benchmark --concurrency=N`
  results in [performance](performance.md#results). There is no cross-process test, by decision.
- The CSV export neither takes the lock nor saves; it reads an existing entry or executes once.

## Failures are never cached

The result is saved only after a successful execution. A timeout, a connection error, a validation error or any exception
leaves no entry; the next request executes again. `FailureCachingTest` proves it for:

- a comparison period that fails (`testItNeverCachesAResultWhoseComparisonPeriodFails`);
- the query timeout (`testItNeverCachesAReportThatHitsTheQueryTimeout`);
- the mixed base currency rejection (`testItNeverCachesAReportRejectedBecauseTheOrdersOfThePeriodMixBaseCurrencies`). The
  rejection of the store configuration happens in validation, before the cache is consulted; the one proven on the save path
  is the order data probe, so the test pins `storeIds` to `[1]` to keep the store validation from firing first;
- a preset whose nested report run fails (`testItNeverCachesAPresetWhoseNestedReportRunFails`).

## Truncated results

A result truncated by `max_rows` is a successful execution: it is cached together with its truncation warning, and a cache
hit returns the same warning (`FailureCachingTest::testItKeepsTheTruncationWarningOnACachedTruncatedResult`).

## Freshness per report category

| Report category | Worst-case staleness | Notes |
| --- | --- | --- |
| Sales and orders, period including today | up to the open TTL (5 min) | new orders appear after expiry or Refresh |
| Sales and orders, closed period | up to the closed TTL (24 h) | refunds and shipments change past periods: returns, net sales and units to ship are attributed to the order date (R04, R05), so a closed period can change after it was cached |
| Stock (physical, salable, dead stock, never sold, low stock coverage) | up to the closed TTL (24 h) | the underlying data is live; only the cache delays it. Stock presets with an order based part (30-day sales) are bounded by the same TTL |
| Carts (abandoned, recent, converted) | up to the open TTL (5 min) | always the open-period TTL; a cart can become abandoned or converted after it was cached |
| Customers (`days_since_last_order`) | up to 24 h | relative to today, so a cached closed period can be a day stale (R18) |

Proof by integration test: `SalesInvalidationTest` (cancellation, refund and shipment keep the cached values until bypass; open
period expiry; closed period TTL) covers the sales rows, `CatalogInvalidationTest` (stock quantity and product name stay
until bypass; stock without a date range uses the closed TTL) covers the stock rows. `ResultCacheTest` covers the generic
behaviour.

Use Refresh when current data is needed, or lower `cache_ttl_closed_period` if 24 h is too long for stock reports.
