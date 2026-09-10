# Production runtime performance

This document covers the runtime settings introduced for the resource, PHP/Laravel, and public-portal cache optimizations. The values in Compose are conservative starting points. Validate them on Stage with a production-sized data set before changing Production.

## FAIR assessment services

F-UJI and the dedicated `assessment-queue` workers are standard services in the Stage and Production Compose files. A normal Portainer stack deployment therefore creates them without a Compose profile. Before deploying an environment in which assessments should be available, configure `FUJI_ENABLED=true`, `FUJI_USERNAME`, and `FUJI_PASSWORD` in the Portainer stack environment. `FUJI_BASE_URL` normally remains at its internal default, `http://fuji:1071`.

The equivalent CLI starts do not require `--profile`:

```bash
docker compose -f docker-compose.stage.yml up -d
docker compose -f docker-compose.prod.yml up -d
```

Compose profiles remain a local-development concern in `docker-compose.dev.yml`; do not depend on `COMPOSE_PROFILES` to make runtime services appear in a Portainer deployment. Setting `FUJI_ENABLED=false` disables new assessments in the application but does not remove the runtime containers from the Stage or Production stack.

The workers process one resource per job and share a Redis-backed limiter. The conservative defaults are concurrency `2`, at most `80` request starts per rolling minute, and at least `750` ms between starts. `FUJI_ASSESSMENT_ITEM_TIMEOUT=150` must remain above the F-UJI HTTP timeout; `FUJI_ASSESSMENT_LEASE_SECONDS=210` and `FUJI_ASSESSMENT_QUEUE_RETRY_AFTER=210` must remain above the item timeout. Validate F-UJI latency, 429 responses, errors, CPU, and memory on Stage before changing these values. Reduce concurrency to `1` first when F-UJI is under pressure.

After updating a Portainer stack, verify that `fuji` is healthy and that the configured number of `assessment-queue` containers is running. The application caches the F-UJI health result for up to 30 seconds, and the scheduler recovers active persistent runs every minute. Leave an existing `preparing`, `queued`, or `running` run in place: once the workers are available, it resumes without deleting its snapshot or results. If progress does not advance after those two windows, inspect the F-UJI and assessment-worker logs for authentication failures, HTTP 429 responses, timeouts, restarts, or memory pressure.

## Initial runtime budgets

| Setting | Stage default | Production default | Validation signal |
| --- | ---: | ---: | --- |
| PHP-FPM `pm.max_children` | 3 | 6 | p95 worker RSS, listen queue, `max children reached` |
| PHP-FPM `pm.max_requests` | 500 | 500 | worker RSS trend |
| InnoDB buffer pool | 1 GiB | 3 GiB | physical reads, MySQL RSS, host free memory |
| MySQL `max_connections` | 40 | 60 | peak connected/running threads |
| Free host reserve | at least 15% target | at least 15% target | RSS peaks, filesystem cache, no swap/OOM |

Override the starting values through `PHP_FPM_MAX_CHILDREN`, `PHP_FPM_START_SERVERS`, `PHP_FPM_MIN_SPARE_SERVERS`, `PHP_FPM_MAX_SPARE_SERVERS`, `PHP_FPM_MAX_REQUESTS`, `MYSQL_INNODB_BUFFER_POOL_SIZE`, and `MYSQL_MAX_CONNECTIONS`. The entrypoint accepts positive integers only for FPM settings and validates the generated pool with `php-fpm -tt` before startup. Queue and scheduler containers do not apply FPM overrides.

The PHP memory limit remains 2 GiB per process. It is a safety ceiling, not the memory budget used to calculate worker count.

## Production caches

The production image enables OPcache with timestamp validation and JIT disabled. Each application, queue, and scheduler container owns its own `bootstrap/cache`; restarting one container cannot delete another container's Laravel caches. Only the app role runs migrations. Every production container then runs `php artisan optimize --no-interaction`, and a failure aborts startup.

Useful checks after deployment:

```bash
docker compose -f docker-compose.prod.yml exec app php --ri "Zend OPcache"
docker compose -f docker-compose.prod.yml exec app php-fpm -tt
docker compose -f docker-compose.prod.yml exec app ls -la bootstrap/cache
docker compose -f docker-compose.prod.yml exec db mysql -u root -p -e "SHOW VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size','max_connections');"
```

Do not expose `opcache_get_status()` through a web route. All long-running PHP containers must be recreated during deployment so their immutable OPcache state matches the application image.

## Host VM resource history

The administrator Logs page records aggregate CPU and RAM utilization for the
entire Linux VM. Production and Stage default
`SYSTEM_METRICS_ENABLED` to `true`; local development defaults it to `false`.
Only the scheduler mounts `/proc/stat` and `/proc/meminfo`, both read-only and
with `create_host_path: false`. Do not replace these narrow mounts with the
Docker socket, a privileged container, or the host PID namespace.

The collector runs once per minute. CPU usage is calculated from consecutive
aggregate counter deltas, while RAM usage is `MemTotal - MemAvailable`. Raw
samples remain for `SYSTEM_METRICS_RETENTION_DAYS` (30 by default) and a daily
task removes older rows. The UI treats samples older than three minutes as
stale and leaves scheduler gaps visible.

After recreating the services, validate the mounts and collect two consecutive
samples:

```bash
docker compose -f docker-compose.prod.yml exec scheduler test -r /host/proc/stat
docker compose -f docker-compose.prod.yml exec scheduler test -r /host/proc/meminfo
docker compose -f docker-compose.prod.yml exec scheduler php artisan system-metrics:collect
docker compose -f docker-compose.prod.yml exec app php artisan tinker --execute="dump(App\\Models\\SystemMetricSample::query()->latest('recorded_at')->first()?->only(['recorded_at', 'cpu_usage_percent', 'memory_usage_percent']));"
```

The first CPU value is intentionally empty because it establishes the baseline.
After the next scheduled minute, compare the displayed percentages with `top`
and `free` on the VM. Never expose raw CPU counters or host paths through a web
endpoint.

## Anonymous public traffic history

The Logs page aggregates estimated unique signed-out visitors for published
landing pages and both public portals. Production and Stage default
`PUBLIC_TRAFFIC_ENABLED` to `true`. Visitor deduplication uses short-lived Redis
keys scoped to the current UTC hour. MySQL receives only three hourly counters:
landing pages, portal, and a separately deduplicated combined value. The raw
source IP and user agent are never stored or logged by the analytics recorder.
The HMAC identifier exists only in short-lived Redis key names and expires
shortly after its UTC hour; it is never written to MySQL or application logs.

The scheduler requests `PUBLIC_TRAFFIC_HEALTH_URL` once per minute and only
marks that minute after the public endpoint, Redis, and MySQL path succeed. A
completed hour must have all 60 observations before it enters the Berlin-time
heatmap. Missing observations are excluded rather than interpreted as zero
traffic. `PUBLIC_TRAFFIC_RETENTION_DAYS` defaults to 400, which covers the
52-week view with operational margin; pruning runs daily.

Validate collection after deployment:

```bash
docker compose -f docker-compose.prod.yml exec scheduler php artisan public-traffic:observe-availability
docker compose -f docker-compose.prod.yml exec app php artisan tinker --execute="dump(App\\Models\\PublicTrafficHourlyStatistic::query()->latest('bucket_started_at')->first()?->only(['bucket_started_at', 'observed_minute_count']));"
```

If `/logs` reports gaps, check the scheduler, the externally routed health URL,
Redis, and MySQL. Changing `APP_URL`, DNS, TLS, reverse-proxy routing, or
`/health` can invalidate availability observations even when the scheduler
container itself is running.

## Public portal caches

Portal page payloads use a configurable fresh/stale window and an atomic cold-miss lock:

- `BOT_PROTECTION_PORTAL_CACHE_FRESH_TTL` (default 60 seconds)
- `BOT_PROTECTION_PORTAL_CACHE_TTL` (total stale lifetime, default 120 seconds)
- `BOT_PROTECTION_PORTAL_CACHE_LOCK_SECONDS` (default 15 seconds)
- `BOT_PROTECTION_PORTAL_CACHE_LOCK_WAIT_SECONDS` (default 10 seconds)

Explicit model invalidation increments a version per portal scope and cache area immediately after commit. This makes old stale values unreachable after publish, depublish, or relevant published-metadata changes. Page, count, map payload, and map extent namespaces are independent.

Warm all standard DOI and IGSN entries after deployment:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan portal:cache-warm
```

Limit warm-up when only one area or portal was invalidated:

```bash
php artisan portal:cache-warm --scope=igsn --area=page --area=map-extent
php artisan portal:cache-warm --scope=doi --area=count --area=facets
```

The command calls application services directly and performs no HTTP request to the public domain. It is idempotent and is intentionally not scheduled periodically.

## Stage measurement and rollout

For cold and warm runs, record p50/p95/p99 TTFB, SQL time, query count, response size, service CPU/RSS, FPM queue state, MySQL buffer-pool reads, Redis hits/misses/evictions, restarts, swap, and OOM events. Use the same approximately 75,000-resource data set and the same request mix for every comparison.

Increase either FPM workers or the InnoDB buffer pool only one step at a time. Keep at least 15% host memory free under peak load. Before Production, confirm the database backup and restore path, retain the previous image and Compose settings, and repeat the smoke tests listed in `post-merge-testing.md` and `pre-release-testing.md`.
