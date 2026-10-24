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

## TTL

| Setting | Default | Applies to |
| --- | --- | --- |
| `mageos_advancedreport/performance/cache_ttl_open_period` | 300 s | the main or comparison range includes today |
| `mageos_advancedreport/performance/cache_ttl_closed_period` | 86 400 s | every other result, **including reports without a date range** (stock datasets and presets have no range, so they use the closed TTL) |

## Invalidation

- Saving the `mageos_advancedreport` configuration section cleans the whole cache type (`CleanReportCacheObserver`): it covers
  settings that change results without being in the key (report attributes, dead stock days, counted statuses, connection).
- `bin/magento cache:clean advanced_report` (or `cache:flush`).
- The Refresh button sends `bypassCache`: the report is executed and the entry is overwritten (the lock is not taken).
- `advanced-report:benchmark --concurrency=N` cleans the whole cache type before starting the workers.
- There is no event-driven invalidation on order, refund or shipment events (R21, accepted).

## Lock

On a miss the runner takes a per-key lock (`ResultCacheLock`, `LockManagerInterface`, lock name
`mageos_advancedreport_<key>`), re-reads the cache once it holds the lock, and executes and saves only if the entry is still
missing: concurrent identical misses run the report once and the waiting requests are served from the cache.

- Wait time: 10 seconds (constructor default), below the query timeout.
- If the lock cannot be taken (wait timeout, or any exception from the lock backend, for example a MySQL user lock deadlock
  with the nested run of `low_stock_coverage`), the report is executed without the lock and the request never fails
  because of it (this is the lock fallback under contention: duplicated work, still correct).
- A cache hit takes no lock. Refresh (`bypassCache`) executes and saves without the lock.
- The default backend is the database (`GET_LOCK`), which is re-entrant per DB session: contention only exists across processes.
- The CSV export neither takes the lock nor saves; it reads an existing entry or executes once.

## Failures are never cached

The result is saved only after a successful execution. A timeout, a connection error, a validation error or any exception
leaves no entry; the next request executes again.

## Freshness per report category

| Report category | Worst-case staleness | Notes |
| --- | --- | --- |
| Sales and orders, period including today | up to the open TTL (5 min) | new orders appear after expiry or Refresh |
| Sales and orders, closed period | up to the closed TTL (24 h) | refunds and shipments change past periods: returns, net sales and units to ship are attributed to the order date (R04, R05), so a closed period can change after it was cached |
| Stock (physical, salable, dead stock, never sold, low stock coverage) | up to the closed TTL (24 h) | the underlying data is live; only the cache delays it. Stock presets with an order based part (30-day sales) are bounded by the same TTL |
| Customers (`days_since_last_order`) | up to 24 h | relative to today, so a cached closed period can be a day stale (R18) |

Use Refresh when current data is needed, or lower `cache_ttl_closed_period` if 24 h is too long for stock reports.
