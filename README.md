# StackNuts StackGaugePerformance

Runtime performance signals for [StackNuts StackGauge](https://github.com/StackNuts/magento-stackgauge),
split into their own module.

## Why a separate module

Not every StackGauge install wants this. A lot of agencies already have dedicated APM tooling
in place - New Relic, Blackfire, their own dashboards - and running a second, overlapping set
of database, Redis and page-timing probes on every client site is wasted cost for no benefit.
StackGauge's own extension architecture already supports third-party reporters cleanly (see its
README's "Pluggable reporters" section, and `StackNuts_StackGaugeCloudflareCache` for a working
example), so splitting this out costs nothing architecturally and lets anyone who doesn't want
it just not install it.

## Installation

```bash
composer require stacknuts/magento-stackgauge-performance
bin/magento module:enable StackNuts_StackGaugePerformance
bin/magento setup:upgrade
```

Requires `stacknuts/magento-stackgauge` (StackGauge core) to already be installed - this
module only contributes reporters to its `ReporterPool`, it has no functionality of its own
outside that.

## What it contributes

All reporters are filed under StackGauge's **Performance** category.

| Reporter | Payload key | Cadence | What it covers |
|---|---|---|---|
| `PhpRuntimeReporter` | `php_runtime` | Hourly | OPcache hit rate, memory, wasted memory, key-slot and interned-string usage, out-of-memory restarts, realpath cache usage, plus PHP version, SAPI, memory limit and JIT state |
| `MysqlRuntimeReporter` | `mysql_runtime` | Hourly | MySQL/MariaDB connection headroom, new slow queries, InnoDB buffer pool size and hit rate, on-disk temp table ratio, uptime |
| `RedisPerformanceReporter` | `redis_perf` | Hourly | Per-instance memory pressure, evicted keys, hit rate, eviction policy and connected clients for the Redis instances behind cache and session |
| `CronPerformanceReporter` | `cron_perf` | Hourly | Longest and average cron job duration, start lateness, stuck, missed and errored jobs |
| `PageSpeedReporter` | `page_speed` | Hourly | Time to first byte for a category, product, search and cart page, timed from the server itself |
| `PerfConfigReporter` | `perf_config` | Daily | Deploy mode, full-page-cache backend and TTL, file-based cache or session storage, HTML minification, critical CSS |
| `ModuleHygieneReporter` | `module_hygiene` | Daily | Enabled and third-party module counts, plus a short list of extensions with a documented tendency to add per-request cost |
| `DbFootprintReporter` | `db_footprint` | Daily | Database size, largest tables, and the size of log and housekeeping tables that grow without bound |

`PerfConfigReporter` is display-only - these are one-off setup decisions, not values that drift
hourly. JS/CSS minification is covered by the security module's config hygiene reporter, not
repeated here.

## Alertable metrics

These numeric fields are declared via `MetricCatalogInterface`, so StackGauge's dashboard syncs
each into an ordinary, editable alert rule with these defaults. A critical threshold is a
stricter limit on the same condition, escalating the same alert.

| Metric key | Condition | Warning | Critical |
|---|---|---|---|
| `php_runtime.opcache_hit_rate` | min below | 95% | 80% |
| `php_runtime.opcache_memory_used_pct` | max above | 90% | 98% |
| `php_runtime.opcache_oom_restarts` | delta above | 0 | - |
| `php_runtime.opcache_wasted_pct` | max above | 20% | - |
| `php_runtime.opcache_keys_used_pct` | max above | 90% | 98% |
| `php_runtime.opcache_interned_strings_used_pct` | max above | 90% | 98% |
| `php_runtime.realpath_cache_used_pct` | max above | 90% | - |
| `mysql_runtime.connection_usage_pct` | max above | 80% | 95% |
| `mysql_runtime.slow_queries` | delta above | 100 | - |
| `mysql_runtime.buffer_pool_hit_pct` | min below | 95% | 90% |
| `redis_perf.memory_used_pct` | max above | 90% | 98% |
| `redis_perf.evicted_keys` | delta above | 0 | - |
| `redis_perf.hit_rate` | min below | 80% | 50% |
| `cron_perf.max_job_duration_seconds` | max above | 900 s | 3600 s |
| `cron_perf.stuck_jobs` | max above | 0 | - |
| `cron_perf.schedule_lag_seconds` | max above | 900 s | 3600 s |
| `cron_perf.error_jobs` | max above | 5 | 20 |
| `db_footprint.housekeeping_tables_mb` | max above | 1024 MB | 5120 MB |
| `page_speed.{category,product,search}_ttfb_ms` | average above | 1000 ms | 3000 ms |
| `page_speed.cart_ttfb_ms` | average above | 1500 ms | 4000 ms |

Every other field is shown on its reporter's page but doesn't alert on its own.

## How some of it works

- **PHP runtime** - cron runs under the CLI, which would report a different, irrelevant
  OPcache, so `PhpRuntimeReporter` reads a snapshot written by the web SAPI instead. A small
  random sample of storefront requests (about 1 in 25, refreshed at most every 30 minutes)
  refreshes it via an observer on `controller_front_send_response_before`; every other request
  pays only a single `random_int()` call. Until a sampled request has written a snapshot, the
  reporter reports `snapshot_available: false`.
- **MySQL runtime** - counters from `SHOW GLOBAL STATUS` are cumulative since server start, so
  ratios are lifetime figures and slow queries are tracked as a delta.
- **Redis** - instances are de-duplicated by host and port, so two cache databases on one
  instance aren't double counted. Reachability and version stay with StackGauge core's own
  Redis reporter.
- **Cron** - only as deep as Magento's `cron_schedule` history retention (about an hour for
  successes by default), so these are recent-window figures.
- **Database footprint** - row counts come from `information_schema` and are InnoDB estimates:
  good for trend and order of magnitude, not exact.
- **Page speed** - each page is requested once, so a cold full-page-cache entry shows up as a
  slow miss rather than being hidden by a warm-up hit. The homepage is already timed by
  StackGauge core's uptime reporter. The probe's search request carries an
  `X-StackGauge-Probe` header, and a plugin on `Magento\Search\Model\Query` (frontend only)
  keeps it out of your store's search term statistics.

## Configuration

Stores > Configuration > Advanced > StackGauge > **Page Speed Pages**
(`stacknuts_stackgauge/page_speed/*`, default scope only):

| Field | Config path | Notes |
|---|---|---|
| Category Page | `category_path` | Optional path (e.g. `women/tops.html`) or full URL on the same host |
| Product Page | `product_path` | Optional path or full URL on the same host |
| Search Page | `search_path` | Optional path (e.g. `catalogsearch/result/?q=shirt`) or full URL on the same host |
| Cart Page | `cart_path` | Optional path (e.g. `checkout/cart/`) or full URL on the same host |

All four are empty by default, in which case the busiest category and first product are picked
automatically. Pin a page for a stable comparison over time. The URLs actually timed, and
whether each was pinned or automatic, are included in every report.

## Toggling individual checks

Every reporter above shows up automatically in StackGauge's own **Disabled Reporters** admin
field (Stores > Configuration > Advanced > StackGauge) - there's nothing to enable or disable in
this module specifically. That field's options are derived from whatever's actually registered
with `ReporterPool`, built-in or contributed.

## Uninstall

```bash
bin/magento module:disable StackNuts_StackGaugePerformance
composer remove stacknuts/magento-stackgauge-performance
```

This module has no database schema - nothing for `module:uninstall` to clean up. The only thing
it persists is the small PHP-runtime snapshot file under `var/`, which can be deleted freely.

## License

PolyForm Shield License 1.0.0 - see [LICENSE](LICENSE).
