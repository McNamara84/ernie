# Issue #765: Title Language Assistant (Canonical Implementation Notes)

## Purpose

This document is the canonical reference for Issue #765 and the related title-language assistant behavior in this repository.

It replaces earlier exploratory draft documents that were split across multiple files and could contain outdated assumptions.

## Scope Covered

- Title-language suggestion discovery for titles without language values.
- Acceptance validation and stale-data protection.
- Concurrency hardening of the accept path.
- Focused test coverage for discovery and acceptance behavior.

## Current Implementation (Code-Aligned)

### Assistant module

- Module ID: `title-language-suggestion`
- Main class: `modules/assistants/TitleSuggestion/Assistant.php`

### Discovery behavior

Discovery iterates title rows where:

- `language` is null or empty
- `value` is non-null and non-empty

Language detection is performed via ELD and suggestions are only created for:

- `de`
- `en`
- `fr`

Stored suggestion metadata includes source verification fields:

- `source_hash`
- `source_snapshot` with `title_id`, `title_text`, `current_language`, `resource_id`

### Accept behavior

The accept flow validates:

- target type is `title`
- `target_id` and `resource_id` are valid positive integers
- `suggested_value` is one of `de|en|fr`
- `metadata.source_hash` exists and is non-empty
- optional snapshot consistency (`title_id`, `resource_id`)

The accept write path is concurrency hardened:

- wrapped in `DB::transaction(...)`
- title row loaded with `lockForUpdate()`

Stale handling:

- stale check compares `metadata.source_hash` against a freshly computed title hash
- missing `metadata.source_hash` is rejected earlier by accept validation
- stale suggestions are rejected with a refresh message

Conflict handling:

- if title already has a different language, accept is rejected
- if title already has the same language, accept returns success without rewriting

## Test Coverage (Code-Aligned)

### Feature tests

File: `tests/pest/Feature/TitleLanguageSuggestionAssistantTest.php`

Coverage includes:

- assistant registration
- real discovery-path execution via `runDiscovery()`
- metadata contract assertions for discovery output (`source_hash`, `source_snapshot`)
- negative acceptance tests for:
  - unsupported target type
  - invalid references
  - unsupported language
  - missing source verification metadata
  - inconsistent snapshot metadata
  - stale suggestions after title mutation

### Browser tests

File: `tests/pest/Browser/TitleLanguageSuggestionTest.php`

Coverage includes:

- assistance page rendering with pending suggestion
- accept endpoint through authenticated flow
- title language persistence after accept
- no immediate reappearance for accepted suggestion in the tested flow

Note: Browser tests intentionally remain UI-flow focused. Core discovery and metadata-contract validation is covered at feature-test level.

## Out of Scope for Issue #765

- DataCite XML export format changes beyond using already-persisted title language values.
- Broader assistance module refactors unrelated to title-language assistant behavior.

## Documentation Consolidation Map

The following draft documents are superseded by this canonical file and now only exist as pointers:

- `docs/issue-781-discovery.md`
- `docs/issue-782-discovery.md`
- `docs/issue-783-discovery.md`
- `docs/issue-783-code-suggestions.md`
- `docs/task1_selection_logic.md`
- `docs/task2_language_discovery.md`
- `docs/task3_discovery_tests.md`

## Definition of Done Check (Sprint 1)

- Changes manually tested locally in browser: Yes.
- Self-review of code changes performed: Yes.
- Existing relevant tests still pass: Yes (focused feature and browser checks run for title-language area).
- README updated if needed: Not required for this issue (no setup/workflow contract changes for general project onboarding).
- Dynamic application documentation updated if needed: Not required for this issue (no new end-user workflow in `/docs` page).

## References

- Developer guide: `modules/assistants/DEVELOPER_GUIDE.md`
- Local development: `docs/local-development.md`
- Testing guide: `docs/testing.md`
