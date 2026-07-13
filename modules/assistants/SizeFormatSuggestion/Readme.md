# Documentation: Epic #935 – Traceability for Size and Format Assistants

### 1. Objective (User Story)
When presented with suggestions for file sizes and formats, curators need to quickly understand why the system is making a specific recommendation. These suggestions require additional context (origin, reliability, direct links) without cluttering the user interface.

---

### 2. Technical Implementation & Architecture
We addressed the requirements using two new, isolated service classes to avoid compromising the existing core code:

#### A. Reliability Logic (`SizeFormatConfidenceExplainer`)
This class evaluates backend signals and translates them into human-readable sentences for the UI:
* Confirmed directly from the repository's directory structure.
* Extracted from incomplete server metadata.

#### B. Data Preparation (`SizeFormatDataTransformer`)
This class prepares the raw suggestion data for the frontend, resolves record links (DOI/title), and groups additional technical information.

* **Important for the frontend:** The transformer preserves the exact original structure to ensure the existing UI card design remains stable and does not crash.

---

### 3. Quality Assurance & Test Coverage
To verify 100% data accuracy and backward compatibility, a new integration test was written:

* **File:** `tests/Feature/SizeFormatTraceabilityTest.php`
* **Content:** The test simulates a raw suggestion from the database and processes it through the transformer. It uses assertions to verify that the legacy mandatory fields remain unchanged and that the new traceability fields are correctly populated in the resulting payload.