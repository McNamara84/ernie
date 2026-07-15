# Feature Documentation: Format & Size Assistant Usability Improvements (#935)

## 1. Overview & User Intent
The purpose of this epic is to enhance the usability of the **Format & Size Assistant** within the curation dashboard. Curators need to evaluate automated file size and format recommendations efficiently without being overwhelmed by technical metadata. This implementation bridges that gap by providing intuitive confidence indicators, interactive details, direct resource linking, and a clean structural layout. Additionally, the discovery pipeline was refined to preserve raw file extension boundaries, which aligns backend storage formats directly with frontend layout needs.


---

## 2. Implemented Tasks & Architecture

### Task 1: Contextual Confidence Explanations
*   **Objective:** Help curators understand *why* a suggestion was assigned a specific confidence level (`low`, `medium`, `high`).
*   **Implementation Details:**
    *   Created a native engine function `sizeFormatConfidenceExplanation()` that evaluates combinations of the probe method (`DIRECTORY_LISTING`, `CONTENT_LENGTH_HEADER`, etc.) and inner file evidence parameters (`parsed_file_count`, `total_file_count`, `filename`).
    *   Integrated an accessible `<Tooltip>` wrapper from our design system. When a curator hovers over the confidence badge (which now includes an `Info` icon and a `cursor-help` style), a clear human-readable explanation appears.
*   **Confidence Matrix Mapping:**
    *   *High:* Derived from complete server metadata or absolute directory listings where all files were successfully accounted for.
    *   *Medium:* Placed on file name fallback regex detections or partial/ranged server responses that require quick visual verification.
    *   *Low:* Triggered on incomplete download streams or low-evidence extension fallbacks.

### Task 2: Provenance Traceability & Collapsible Details 
*   **Objective:** Provide underlying extraction evidence and a path to resolve datasets without cluttering the default UI layout.
*   **Implementation Details:**
    *   **Collapsible Metadata:** Implemented a native HTML `<details>` and `<summary>` element. Technical metadata keys—such as the exact probing method, target filenames used, file counters, raw evidence copy, and discovery timestamps—are grouped in a clean tabular `<dl>` grid layout that remains completely hidden unless clicked.
    *   **Dynamic Source Linking:** Refactored the raw source action using a dynamic string generator (`sizeFormatSourceLinkLabel()`). It transforms technical labels into contextual anchor texts (e.g., changing a `DIRECTORY_LISTING` action into an `"Open download page"` link).
    *   **DOI Resolver Integration:** Reconfigured the resource group header to check for valid `doiHref` strings. Both the DOI string and the resource title are directly hyperlinked to the external DOI registration endpoint, allowing curators to jump straight to the live record in a single click.

### Task 3: Structural Layout & Backend Discovery Streamlining 
*   **Objective:** Optimize card topography and grouping for rapid scannability while standardizing stored suggestion formats.
*   **Backend Refactoring (`SizeFormatSuggestionDiscoveryService.php`, `SizeFormatFileProbeService.php` & `SizeFormatSizeParserService.php`):**
    *   Isolated format normalization strategies via the new `extractFormatSuggestionValue()` method.
    *   Instead of persisting complex MIME types directly during discovery, the assistant checks the array structure for an `extension` flag, parses the `source_url` path using `PATHINFO_EXTENSION`, or safely falls back to stripping down slash segments to extract and store raw string representations like `"zip"` or `"pdf"`.
    *   Preserved directory-listing provenance for format suggestions and normalized discovered size units into the compact standardized format expected by the frontend assistant flow (e.g., automatically standardizing file listings into values like `12.5 MB`).
*   **Frontend Layout:**
    *   Isolated formatting rules within a dedicated `isSizeFormatGroup` block inside `assistance.tsx`.
    *   Rearranged padding, margins, and section borders (`border-t pt-3` on card actions) to separate high-priority suggestion labels from structural secondary data points.

---

## 3. Test Coverage Strategy
Comprehensive automated test suites across both environments lock down these visual transitions and structural backend enhancements.

### Backend Integration Tests (`SizeFormatAssistantTest.php` - Pest PHP)
*   **Discovery Engine Validation:** Uses `Http::fake()` to mock multi-step network resolution pipelines (302 redirects to landing pages and nested directory downloads containing `test.zip 12.5M`).
*   **Strong Database Assertions:** Explicitly queries the `AssistantSuggestion` Eloquent state post-discovery to guarantee strict traceability and correctness:
    *   **Format Verification:** Asserts that the suggestion correctly persists the raw extension (`zip`) as the `suggested_value`, while validating that the inner metadata safely stores the `inferred_value` (`application/zip`), the `probe_method` (`DIRECTORY_LISTING`), and the specific evidence extension boundaries.
    *   **Size Verification:** Asserts that the stored value and labels are perfectly normalized to the system's expected standard (`12.5 MB` / `SIZE: 12.5 MB`), while verifying the nested JSON array values (`numeric_value => '12.5'`, `unit => 'M'`), the `source_url`, and the probing method.


### Frontend Vitest Coverage (`assistance-links.test.tsx`)
*   **Topography & Badges:** Validates that review-sensitive packages (like ZIP archives) apply custom highlight classes (`bg-orange-600`) dynamically.
*   **Interactive Tooltips:** Utilizes an exhaustive matrix test (`it.each([...])`) to verify that the tooltip rendering engine prints the correct contextual copy across every combination of `high`, `medium`, and `low` confidence states.
*   **Collapsible State Integrity:** Simulates user interaction events (`user.click(summary)`) to assert that the `<details>` node properly handles the `open` state toggling, and checks that internal technical constants remain strictly invisible to the user until expanded.
*   **Link Mapping Validation:** Asserts that both the generated page links and the grouped resource header URLs properly construct target attributes (`target="_blank" rel="noreferrer"`) and resolve accurately to the expected external destinations.

---

## 4. Changelog Registry (`changelog.json`)
*   **Version 1.0.1 (2026-07-08):** Officially registered the behavioral enhancement. Size and format discovery pipelines now preserve raw file extensions during discovery stages, while the underlying acceptance workflows seamlessly handle mapping to standard MIME types and target system metadata records.