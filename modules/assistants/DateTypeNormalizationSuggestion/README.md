# Date Type Normalization & Plausibility Assistant Documentation

This documentation covers the user workflows, parsing rules, safety guards, and chronological checks implemented for the **Date Type Normalization Assistant** in Ernie, reflecting the v1.0 milestone features.

---

## 1. Overview
The Date Type Normalization Assistant scans research data records to fix incorrect temporal metadata labels, discover missing registration milestones, and flag logical chronological errors within legacy database imports. Every automated suggestion goes through a manual review flow where curators preview changes before final acceptance.

---

## 2. Core Curation Workflows

### Collected vs. Coverage Correction
The assistant identifies records where the `Collected` date type is incorrectly used for broad geospatial or thematic observation periods instead of literal field collection intervals. In these scenarios, it proposes changing the label to `Coverage`.

### Missing Created & Issued Discovery
The assistant checks local records against external footprints to find missing metadata milestones. It automatically proposes adding missing `Created` (creation date) or `Issued` (publication date) fields when valid evidence exists.

---

## 3. Plausibility & Chronological Swap Checks (v1.0)
To clean up corrupt legacy records (`Altbestand`), the assistant includes a **Plausibility Check Service** that detects impossible chronological combinations and automatically suggests a field swap (`Tausch`):
* **Issued vs. Created:** If a record's `Date Issued` predates its `Date Created`, a swap suggestion is triggered.
* **Submitted vs. Created:** If a record's `Date Submitted` predates its `Date Created`, a swap suggestion is triggered.

### Local Testing & Data Verification
The chronological plausibility rules are validated using a custom DataCite migration loop script. Curators can test the pipeline using the following production reference DOIs extracted from the legacy dataset:
* `110.1594/GFZ.TR32.2`
* `10.14470/8I254008`
* `10.14470/7T7561754109`
* `10.14470/6I800592`
* `10.14470/4U7568470291`
* `10.14470/K47560642124`
* `10.14470/1P035555`
* `10.14470/1N134371`
* `10.14470/7I253999`
* `10.14470/2O097102`
* `10.14470/0E165378`
* `10.14470/L9180569`
* `10.5880/GFZ.GFYB.2025.003`
* `10.5880/ICGEM.2025.001`

---

## 4. Technical Parsing & Implementation Notes

### Type-Resilient Scraper (Integer vs. String)
The metadata extraction layer uses defensive parsing to resolve irregular schemas in `schema.org` source files. The pipeline safely processes the `datePublished` property whether it arrives as an expected ISO string (e.g., `"2024"`) or a raw numerical integer (e.g., `2024`), which is critical for specialized cross-agency nodes (e.g., fidgeo DOIs `10.5880/fidgeo.2026.047` and `10.5880/fidgeo.2024.014`).

### Safety Guards & Accept Flow
* **Duplicate Guard:** Prevents database pollution by throwing a hard block if a suggested type/value combination already exists on the target resource.
* **Placeholder Alert:** Flags legacy fallback dates ending systematically in generic `-01-01` values, warning the curator to verify data integrity before final validation.