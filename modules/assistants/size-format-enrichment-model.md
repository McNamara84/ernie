# Size & Format Enrichment Model

Status: concise design and reviewer-facing contract for the Size & Format
assistant module on the Assistance page.

This document describes what the Size & Format assistant probes, how it derives
suggestions, what reviewers see in preview, and what happens when a suggestion
is accepted or declined.

## Purpose

The Size & Format assistant proposes missing resource `Format` and `Size`
metadata for datasets that do not already have those values in ERNIE.

Its goal is to help curators fill obvious gaps from approved download sources
without turning the assistant into a general web crawler.

## Eligible Sources

The assistant only probes approved download sources:

- approved resource URLs on GFZ landing pages
- DOI-derived download URLs reached through the resource DOI
- approved download directory listings and file URLs extracted from those pages

The intended DOI path is:

`https://doi.org/{doi}` -> approved landing page -> approved download URL

If no eligible download URL can be found, the assistant skips the resource
instead of probing arbitrary external links.

## Safe Probing Policy

The assistant uses bounded probing rules:

- no uncontrolled crawling
- HTTP(S) only
- request limits per probe flow
- request timeouts for connect and total request duration
- bounded redirect handling
- bounded retries for supported transient failures
- skip rules for blocked access, unsupported protocols, unreachable sources,
  missing download links, and other unsafe or unhelpful cases
- limited directory exploration inside the original approved download tree only

By default, probing prefers lightweight metadata requests over full content
downloads.

## Format Inference

Format suggestions may be inferred from:

- MIME or content-type headers
- filename extensions
- approved directory-listing evidence
- other approved response metadata gathered during bounded probing

Common probe methods include:

- `CONTENT_TYPE_HEADER`
- `FILENAME_EXTENSION`
- `FILENAME_EXTENSION_FALLBACK`
- `RANGED_GET_CONTENT_TYPE`
- `DIRECTORY_LISTING`

ZIP format suggestions are valid but should be treated carefully because one ZIP
archive may contain many files and mixed internal formats.

## Size Inference

Size suggestions may be inferred from:

- `Content-Length`
- `Content-Range`
- approved displayed file-size values in a directory listing
- equivalent response metadata

The assistant does not download whole files by default just to estimate size.
It prefers headers, directory metadata, or a small ranged request when needed.

## Suggestion Payload

Each Size or Format suggestion should preserve enough context for review:

- inferred value
- source URL
- probe method
- evidence
- confidence
- warning or skip context when available

Typical examples:

- format value: `pdf`, `csv`, `zip`, or a MIME-derived value
- size value: `8.1M`, `2M`, or another normalized byte-size label
- probe method: `DIRECTORY_LISTING`, `CONTENT_LENGTH_HEADER`,
  `CONTENT_TYPE_HEADER`, `RANGED_GET_CONTENT_RANGE`

## Preview Behavior

On the Assistance page, the preview should show:

- the inferred value
- the source URL
- the probe method
- the evidence used to justify the suggestion
- confidence and any warning context when present

ZIP suggestions may be visually highlighted to make curator review easier.

## Accept / Decline Behavior

Accepting a suggestion applies the proposed metadata to the resource:

- a format suggestion creates the corresponding `Format` record
- a size suggestion creates the corresponding `Size` record

Acceptance must be idempotent. The assistant should avoid duplicate stored
values when the same suggestion is accepted more than once.

Declining a suggestion dismisses it without changing existing resource
metadata.

## Duplicate Prevention

Duplicate prevention applies at two levels:

- probing should deduplicate repeated file and suggestion evidence where
  possible
- acceptance should avoid creating duplicate `Format` or `Size` rows for the
  same resource

This keeps the preview list smaller and prevents repeated acceptance from
creating duplicate metadata records.

## ZIP Archive Handling

ZIP archives need curator attention because they may:

- contain multiple files
- mix several internal file formats
- expose only partial evidence from filenames or directory listings

For that reason, ZIP suggestions may carry lower confidence and should be
reviewed carefully before acceptance.

## Related Tests

Current related test coverage includes:

- `tests/pest/Feature/SizeFormatAssistantTest.php`
  - preview payload exposure on `/assistance`
  - accept behavior for format and size suggestions
  - duplicate prevention on repeated acceptance
  - unknown target-type handling
- `tests/pest/Unit/SizeFormatDiscoverytest.php`
  - safe skip behavior for unreachable or unsupported sources
  - metadata inference from HTTP headers
- `tests/pest/Unit/SizeFormatFileProbeServiceTest.php`
  - bounded directory traversal inside the approved download tree
  - size aggregation from nested directory listings
  - refusal to explore external or sibling download trees
