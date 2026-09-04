# Production runtime performance

This document covers the runtime settings introduced for the resource, PHP/Laravel, and public-portal cache optimizations. The values in Compose are conservative starting points. Validate them on Stage with a production-sized data set before changing Production.

## Service profiles

F-UJI is excluded from a normal Stage or Production start. Start it only when assessments are enabled in the application:

```bash
FUJI_ENABLED=true docker compose -f docker-compose.stage.yml --profile assessment up -d
FUJI_ENABLED=true docker compose -f docker-compose.prod.yml --profile assessment up -d
```

Without `--profile assessment`, Compose does not create the F-UJI container. Setting `FUJI_ENABLED=true` without starting the profile leaves the application configured for an unavailable service and is therefore not a valid deployment configuration.

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
