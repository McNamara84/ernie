# ERNIE project instructions

## Database version

- Local development and every MySQL-backed test must use the MySQL 9.7 server pinned in `docker-compose.dev.yml`. Do not downgrade that service to MySQL 8 to make an older local volume boot.
- The development data volume is versioned for MySQL 9.7. Preserve an older volume and use the documented dump/restore or reset path when its data is needed; never solve an incompatible data directory by changing the server image.
- The MySQL 8.4 build stage in `Dockerfile` and `Dockerfile.dev` supplies only the target-specific `mysql-legacy-mysqldump` binary for exporting the external legacy MySQL 5.6 IGSN database. It is not the ERNIE database server and must never be substituted for the MySQL 9.7 development or test server.

## Test execution

- Use the repository npm wrappers. Do not invoke Pest or PHPStan directly from the host.
- The canonical complete backend suite is `npm run test:php`. It prepares a Linux-native Docker workspace, runs serial and architecture tests separately, and runs the remaining Unit/Feature tests with eight workers on the standard 16-CPU local Docker setup.
- Every local PHP validation process needs a 2 GB memory limit. Do not retry with 512 MB or another lower limit. The wrappers enforce 2 GB for Pest workers, PHPStan, type coverage, and MySQL-sensitive tests.
- Override parallelism only for a measured reason with `ERNIE_PEST_PROCESSES=<n>`; the default is half the available CPUs rounded down, with a minimum of one and a maximum of eight.
- Use `npm run test:php:tia` for the affected-test feedback loop and `npm run test:php -- <path-or-filter>` for focused Pest verification. Complete line coverage is a CI responsibility unless the user explicitly requests it.
- If a complete run fails or is interrupted, reproduce and fix the failing test path first. Do not immediately restart the complete suite from zero. Run the complete suite once after the focused failure passes.
- Use `npm run check:backend` for final PHP validation because it runs the optimized Pest suite followed by PHPStan.

Frontend tests stay host-side. Use the exact Node version declared in `.node-version`; if `npm ls` reports invalid or extraneous packages, restore `node_modules` with `npm ci` before drawing conclusions from JavaScript test results. Local Vitest defaults to half the available CPUs rounded down, with a minimum of one and a maximum of eight; override it only for a measured reason with `ERNIE_VITEST_WORKERS=<n>`. Blank overrides are treated as unset, and CI ignores this local override. Use `npm run test:run` for Vitest, `npm run check:frontend` for the complete frontend validation set, and the documented Playwright wrapper appropriate to the target stack.
