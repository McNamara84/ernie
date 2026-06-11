# Issue #782 Test Notes: Title Language Discovery by Maryia Balonnaikava

## Goal

Prepare testing for the discovery part of the Title Language Attribute Enrichment Assistant.

The discovery should identify title fields that do not yet have a language attribute and create suggestions for the detected language.

## Current Status

The implementation is not fully available or not verified yet.

For now, this document collects expected behavior, possible test scenarios, and open questions. Once the implementation is available, these notes can be used to validate the discovery flow.

## Current Understanding

This issue is not about changing or translating the title text itself. It is about enriching the title metadata with a language suggestion.

Example current DataCite XML:

```xml
<titles>
  <title>Airborne Wind and Eddy Covariance Dataset - Recorded with the ASK-16 EC Platform between 2017 – 2022</title> # example 
</titles>
```

Expected discovery result:

* The title has no language attribute.
* The assistant detects the title language as English, German or French.
* The assistant creates a suggestion for language code `en`, `de` or `fr`.

Expected metadata after accepting the suggestion, depending on ERNIE’s internal model and export logic:

```xml
<titles>
  <title xml:lang="en">Airborne Wind and Eddy Covariance Dataset - Recorded with the ASK-16 EC Platform between 2017 – 2022</title>
</titles>
```

The exact storage location in ERNIE’s data model still needs to be verified.

## Expected Behavior to Verify Later

* Titles without a language attribute should be detected.
* Titles with an existing language attribute should be ignored (or tested if they are correct).
* Multiple titles without language attributes should result in separate suggestions.
* Running discovery multiple times should not create duplicate suggestions.
* Created suggestions should be visible in the Assistance UI.
* Discovery should not overwrite existing language metadata.
* If no missing language attributes exist, the assistant should show an appropriate empty state.

## Possible Test Scenarios

### Scenario 1: Title without language attribute

Given a resource has a title without a language attribute,
when discovery is executed,
then the assistant should create a language suggestion for that title.

### Scenario 2: Title with existing language attribute

Given a resource has a title with an existing language attribute,
when discovery is executed,
then the assistant should not create a suggestion for that title.

### Scenario 3: Multiple missing title languages

Given a resource has multiple title fields without language attributes,
when discovery is executed,
then the assistant should create separate suggestions for each affected title field.

### Scenario 4: Duplicate prevention

Given discovery has already created a suggestion for a title,
when discovery is executed again,
then no duplicate suggestion should be created.

### Scenario 5: No missing language attributes

Given all title fields already have language attributes,
when discovery is executed,
then no suggestions should be created and the UI should show an appropriate empty state.

## Open Questions

* Where is the language attribute stored in ERNIE’s data model?
* Which language detection library or heuristic will be used?
* How should multilingual titles be handled?
