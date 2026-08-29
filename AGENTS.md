# ERNIE project instructions

## Test execution

- Use the repository npm wrappers. Do not invoke Pest or PHPStan directly from the host.
- The canonical complete backend suite is `npm run test:php`. It prepares a Linux-native Docker workspace, runs serial and architecture tests separately, and runs the remaining Unit/Feature tests with eight workers on the standard 16-CPU local Docker setup.
- Every local PHP validation process needs a 2 GB memory limit. Do not retry with 512 MB or another lower limit. The wrappers enforce 2 GB for Pest workers, PHPStan, type coverage, and MySQL-sensitive tests.
- Override parallelism only for a measured reason with `ERNIE_PEST_PROCESSES=<n>`; the default is half the available CPUs capped at eight.
- Use `npm run test:php:tia` for the affected-test feedback loop and `npm run test:php -- <path-or-filter>` for focused Pest verification. Complete line coverage is a CI responsibility unless the user explicitly requests it.
- If a complete run fails or is interrupted, reproduce and fix the failing test path first. Do not immediately restart the complete suite from zero. Run the complete suite once after the focused failure passes.
- Use `npm run check:backend` for final PHP validation because it runs the optimized Pest suite followed by PHPStan.

Frontend tests stay host-side. Use the exact Node version declared in `.node-version`; if `npm ls` reports invalid or extraneous packages, restore `node_modules` with `npm ci` before drawing conclusions from JavaScript test results. Use `npm run test:run` for Vitest, `npm run check:frontend` for the complete frontend validation set, and the documented Playwright wrapper appropriate to the target stack.
