# Issue #783: Apply Accepted Title Languages and Protect Export Behavior

## Purpose

This issue defines how accepted title language suggestions are applied to title records and how the resulting language values are handled during XML export.

The goal is to improve xml:lang coverage while preventing unintended metadata changes.

---

# User Story

As a curator, I want accepted title language suggestions to update exports cleanly so that DataCite XML gains xml:lang coverage without side effects.

---

# Position Within the Overall Workflow

This issue builds on the previous title language issues.

## Issue #781

Defined how title languages can be inferred.

Topics included:

- language detection signals
- multilingual edge cases
- title-level language inference rules
- confidence handling

Result:

- rules for determining title languages

---

## Issue #782

Defined how titles are selected for review.

Topics included:

- missing language values
- suspicious language mismatches
- suggestion generation
- duplicate suppression

Result:

- language suggestions can be generated for curator review

---

## Issue #783

Defines what happens after a curator accepts a language suggestion.

Topics include:

- reviewer preview
- acceptance workflow
- metadata update
- XML export behavior
- regression testing

Result:

- accepted language suggestions become part of the title metadata and are exported correctly

---

# Acceptance Criteria

## Accepted Suggestions

Accepting a suggestion should:

- update the title language field
- remove the pending suggestion

Example:

Before:

Title:
Groundwater Recharge

Language:
NULL

Suggestion:
en

After:

Title:
Groundwater Recharge

Language:
en

Suggestion:
removed

---

## Existing Language Values

Existing language values must not be overwritten automatically.

Example:

Current Language:
de

Suggested Language:
en

Expected Behaviour:

- display overwrite warning
- require explicit confirmation
- do not overwrite silently

---

## XML Export

Accepted language values should appear as xml:lang attributes in exported XML.

Example:

Before:

```xml
<title>
Groundwater Recharge
</title>
```

After:

```xml
<title xml:lang="en">
Groundwater Recharge
</title>
```

---

## Duplicate Prevention

Duplicate suggestions must not be recreated.

Example:

Existing suggestion:

- Groundwater Recharge → en

Expected Behaviour:

- no additional identical suggestion created

---

## Mixed-Language Title Sets

Each title should preserve its own language assignment.

Example:

Main Title:

Groundwater Recharge

Alternative Title:

Grundwasserneubildung

Expected Export:

```xml
<title xml:lang="en">
Groundwater Recharge
</title>

<title xml:lang="de">
Grundwasserneubildung
</title>
```

---

# Task 1: Build the Reviewer Preview

The reviewer should be able to inspect a suggestion before accepting it.

The preview should display:

- title text
- current language
- proposed language
- confidence score
- evidence summary

Example:

Title:
Groundwater Recharge

Current Language:
NULL

Suggested Language:
en

Confidence:
95%

Evidence:
Detected title language with high confidence.

---

## Overwrite Warning

If a title already contains a language value, a warning should be displayed.

Example:

Current Language:
de

Suggested Language:
en

Warning:

Existing language value will be overwritten only after explicit approval.

---

# Task 2: Implement the Accept Flow

## Accept Suggestion

When a reviewer accepts a suggestion:

1. update Title.language
2. remove pending suggestion
3. persist changes

Example:

Before:

Language:
NULL

Suggestion:
en

After:

Language:
en

Suggestion:
removed

---

## Existing Language Protection

Existing language values should not be replaced automatically.

Expected Behaviour:

- require explicit confirmation
- preserve metadata integrity

---

## Stale Suggestion Handling

Suggestions may become outdated if the title changes after discovery.

Example:

Day 1:

Title:
Groundwater Recharge

Suggestion:
en

Day 2:

Title modified

Expected Behaviour:

- invalidate suggestion
- require new discovery process

---

## Duplicate Prevention

Accepted suggestions should not be recreated.

Example:

Title:
Groundwater Recharge

Language:
en

Expected Behaviour:

- no new suggestion for the same title-language combination

---

# Task 3: Documentation Updates

Update:

- resources/js/pages/docs.tsx

README updates are only required if implementation changes:

- developer setup
- operations
- workflow guidance

---

# Task 4: Browser Workflow and Regression Coverage

## Browser Workflow Test

Verify:

1. suggestion appears
2. reviewer opens preview
3. reviewer accepts suggestion
4. title language is updated
5. suggestion is removed

---

## Export Regression Test

Verify:

- xml:lang is exported correctly
- existing export functionality remains unchanged

---

## Duplicate Prevention Test

Verify:

- accepted suggestions are not recreated

---

## Mixed-Language Title Set Test

Verify:

- multilingual titles export correctly
- language assignments remain independent

---

# Risks

## Export Assumptions

Existing export logic may contain assumptions about NULL language values.

Potential Impact:

- incorrect XML export behaviour
- missing xml:lang attributes

---

## Stale Suggestions

Suggestions may target title records that have changed after discovery.

Potential Impact:

- incorrect language assignment
- outdated review information

---

# Out of Scope

The following topics are not part of this issue:

- refactoring unrelated export functionality
- implementing custom review interfaces
- automatic metadata correction without curator approval
- changes unrelated to title language assignment

---

# Summary

Issue #783 completes the title-language workflow.

The issue focuses on:

- reviewing language suggestions
- accepting language suggestions safely
- protecting existing metadata
- exporting xml:lang attributes correctly
- preventing duplicate or stale suggestions
- ensuring stable behaviour through automated tests