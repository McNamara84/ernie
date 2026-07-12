# Date Type Normalization & Plausibility Assistant Documentation

This documentation describes the functionality of the Date Type Normalization and Completion Assistant in Ernie, including its discovery workflows and plausibility checks.

---

## 1. Overview
The Date Type Normalization and Completion Assistant helps curators identify missing, incorrect, or implausible DataCite date metadata. It suggests corrections for misused date types (e.g. `Collected` to `Coverage`), discovers missing `Created` and `Issued` dates from external metadata sources (schema.org) and generates review hints for implausible chronological relationships between date types. All suggestions require manual review before they are applied.

---

## 2. Core Curation Workflows

### Collected vs. Coverage Correction
The assistant checks resources that contain `Collected` date entries and geolocations. If the number of `Collected` dates matches the number of geolocations and both counts are greater than zero, it creates a suggestion to change the resource's current `Collected` date entries to `Coverage`.

Before the correction is applied, the assistant verifies that the relevant date and geolocation counts have not changed since discovery. If the current state no longer matches the discovered state, the suggestion is treated as stale and is not applied.

### Missing Created & Issued Discovery
The assistant checks whether a resource already contains `Created` or `Issued` date types. Missing values are looked up in available schema.org metadata.

The extraction supports both ISO date strings and numeric year values to handle different schema.org representations.

A suggestion is created only when:

- the corresponding date type is missing
- the extracted target date type is supported,
- a non-empty normalized date value is available

Accepted suggestions are normalized again before storage. They are not applied if the resource already contains the proposed date type or if the value or target type is invalid.

### Plausibility Checks
The assistant checks existing date values against the expected chronological order:

`Collected → Created → Submitted → Accepted → Issued → Available`

If a value belonging to an earlier date type occurs after a value belonging to a later date type, the assistant creates a review hint. Plausibility hints do not modify metadata automatically.

The plausibility check supports:

- single date values
- multiple values of the same date type
- and date ranges

For ranges, the end of the earlier value is compared with the start of the later value. Date values are normalized before comparison, and the order in which database rows are returned does not affect the result.

---

## 3. Technical Parsing & Implementation Notes


### Schema.org Date Extraction
The assistant extracts `Created` and `Issued` dates from schema.org metadata. The extraction supports both ISO date strings and numeric year values to handle different schema.org representations.

