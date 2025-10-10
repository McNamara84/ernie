# Testing Fixtures

This directory contains minimal test fixtures for GCMD vocabularies used in Playwright E2E tests.

## Files

- `gcmd-science-keywords.json` - Minimal science keywords hierarchy (EARTH SCIENCE > AGRICULTURE > SOILS)
- `gcmd-platforms.json` - Minimal platforms list
- `gcmd-instruments.json` - Minimal instruments list

## Usage

These files are **committed to the repository** to ensure they are available in CI environments.

During Playwright test runs, the GitHub Actions workflow copies these fixtures to `storage/app/` so they can be served by the `VocabularyController`.

## Why committed?

Unlike production GCMD data (which is fetched via Artisan commands), these test fixtures are:
- Minimal (only contain necessary data for tests)
- Static (don't change)
- Required for CI (where full GCMD data is not available)

## Updating

If you need to modify the test data:
1. Edit the JSON files in this directory
2. Ensure the data structure matches the production GCMD format
3. Update tests in `tests/playwright/curation-controlled-vocabularies.spec.ts` if needed
