# Bugs Found During Stage E2E Testing

## Test File: `full-workflow-stage.spec.ts`
## Date: 2026-01-06
## Test XML: `tests/pest/dataset-examples/datacite-example-dataset-v4.xml`

---

## BUG 1: Date Fields Not Properly Validated/Formatted (CRITICAL - Blocks Save)

**Severity:** Critical (blocks saving to database)

**Error Messages:**
```
The dates.0.startDate field must be a valid date.
The dates.1.startDate field must be a valid date.
The dates.2.startDate field must be a valid date.
The dates.0.endDate field must be a valid date.
The dates.1.endDate field must be a valid date.
```

**XML Source Data:**
```xml
<dates>
  <date dateType="Collected">2010/2020</date>
  <date dateType="Other" dateInformation="Coverage">2010/2020</date>
  <date dateType="Issued">2022</date>
</dates>
```

**Root Cause Hypothesis:**
The XML uses DataCite date ranges format (`YYYY/YYYY`) and single year format (`YYYY`). 
The frontend may not be properly parsing these into `startDate` and `endDate` fields 
that the backend validation expects (likely expects `YYYY-MM-DD` format).

**Files to Investigate:**
- XML Parser/Upload Handler
- Date form component state management
- Backend validation rules for dates

---

## BUG 2: GCMD Keywords Missing Required Fields (CRITICAL - Blocks Save)

**Severity:** Critical (blocks saving to database)

**Error Messages:**
```
The gcmdKeywords.0.text field is required.
The gcmdKeywords.1.text field is required.
... (9 keywords total)
The gcmdKeywords.0.path field must be a string.
The gcmdKeywords.1.path field must be a string.
... (9 keywords total)
```

**XML Source Data:**
The XML contains GCMD Science Keywords, Platforms, and Instruments:
- 2 Science Keywords
- 3 Platforms  
- 4 Instruments

**Root Cause Hypothesis:**
When GCMD keywords are loaded from XML, the `text` and `path` properties are not 
being properly populated in the form state. The keywords might be displayed visually 
but the underlying data structure is incomplete.

**Files to Investigate:**
- GCMD keyword parsing from XML
- Controlled Vocabularies form component
- Form state initialization for keywords

---

## BUG 3: Authors Section - Institutional Selector Not Working

**Severity:** Medium (verification fails but doesn't block save)

**Error:** Cannot find input field for organizational author verification

**Expected Data:**
```
Creator: National Gallery (Organizational/ResearchGroup)
```

**Root Cause Hypothesis:**
The selector for institutional/organizational authors uses a different 
input structure than personal authors.

---

## BUG 4: Spatial & Temporal Coverage Not Displaying

**Severity:** Medium (verification fails)

**Expected Data:**
```
Place: Roof of National Gallery, London UK
Latitude: 51.50872
Longitude: -0.12841
```

---

## BUG 5: Related Work Not Visible

**Severity:** Medium (verification fails)

**Expected Related Identifiers:**
- http://www.nationalgallery.org.uk/paintings/glossary/stretcher (IsDocumentedBy)
- 10.1007/978-3-319-67026-3 (References)
- https://research.ng-london.org.uk/scientific/specdocs/?page=spec-doc-viewer&specsId=42 (HasMetadata)
- https://research.ng-london.org.uk/scientific/ (IsPartOf)

---

## BUG 6: Funding Information Not Visible

**Severity:** Medium (verification fails)

**Expected Data:**
```
Funder: European Commission
Award Number: 871034
Award Title: Integrating Platforms for the European Research Infrastructure ON Heritage Science
```

---

## Console Errors Observed

1. **Google Maps API Error:**
   ```
   Google Maps JavaScript API error: ApiProjectMapError
   ```
   This is a configuration issue with the Maps API key, not related to form saving.

2. **HTTP 422 Error:**
   ```
   Failed to load resource: the server responded with a status of 422 ()
   ```
   This is the validation error from the backend when trying to save.

---

## Recommendations

### Immediate Priority (Critical):
1. Fix date parsing from XML to properly convert `YYYY/YYYY` ranges and `YYYY` single years
2. Fix GCMD keyword loading to include `text` and `path` properties

### Secondary Priority:
3. Review Spatial/Temporal, Related Work, and Funding sections for XML parsing issues
4. Consider adding better error messages in the UI for validation failures

---

## Test Status

The Stage E2E test currently **CANNOT COMPLETE** due to Bugs 1 and 2 preventing 
the save operation. Once these are fixed, the remaining verification steps can be validated.
