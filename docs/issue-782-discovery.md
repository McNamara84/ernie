# Issue #782: Implement Discovery for Missing or Suspicious Title Languages

## Purpose

This document defines the discovery logic for identifying title records that may require curator review because of missing language information or suspicious language conflicts.

The goal is to generate language suggestions while minimizing false positives and avoiding duplicate suggestions.

---

# User Story

As a curator, I want the assistant to highlight titles that are missing a language value or appear inconsistent with their content so that I can normalize title metadata efficiently.

---

# Acceptance Criteria

- Discovery targets titles with empty language values.
- Discovery can optionally flag language mismatches when confidence is sufficiently high.
- Suggestions include:
  - title text
  - proposed language
  - confidence score
  - evidence summary
- Duplicate suggestions are not recreated.
- Dismissed suggestions are not recreated for the same title-language combination.

---

# Task 1: Query Target Titles for Review

## Missing Language

Condition:warum 

- language = NULL
- language field is empty

Result:

- create language suggestion

Example:

Title:

Groundwater Recharge in Arid Regions

Language:

NULL

Expected Action:

- execute language detection
- generate language suggestion

---

## Suspicious Language Mismatch

Condition:

- stored language exists
- detected language differs from stored language
- confidence exceeds threshold

Example:

Title:

Groundwater Recharge in Arid Regions

Stored Language:

de

Detected Language:

en

Confidence:

0.95

Expected Action:

- create conflict suggestion
- flag for curator review

---

# Task 2: Implement Title-Language Discovery and Suppression

## Suggestion Generation

Each suggestion should contain:

- title text
- current language
- proposed language
- confidence score
- evidence summary

Example:

Title:

Groundwater Recharge in Arid Regions

Current Language:

de

Proposed Language:

en

Confidence:

0.95

Evidence:

Detected language differs from stored language.

---

## Confidence Handling

High confidence:

- suggestion generated

Low confidence:

- no automatic suggestion
- status = ambiguous

Example threshold:

- confidence ≥ 0.90 → generate suggestion
- confidence < 0.90 → no automatic suggestion

---

## Duplicate Suppression

Condition:

- identical title-language suggestion already exists

Result:

- no new suggestion generated

---

## Dismissed Suggestion Suppression

Condition:

- same title-language pair was previously dismissed

Result:

- do not recreate suggestion

---

## Existing Language Values

Condition:

- language value already exists
- detected language differs

Result:

- flag as conflict
- do not overwrite existing metadata

---

## Mixed-Language Titles

Example:

Groundwater Recharge – Eine Fallstudie aus Brandenburg

Result:

- no automatic suggestion
- status = ambiguous

---

# Task 3: Discovery Tests

## Test 1: Short Titles

Input:

- Atlas
- Data
- Report
- Map

Expected Result:

- no suggestion generated

---

## Test 2: Borrowed English Phrases

Input:

- Open Data
- Climate Change

Expected Result:

- avoid false mismatch detection

---

## Test 3: Formula-Like Values

Input:

- Dataset 2024
- Report No. 15
- Version 2.0

Expected Result:

- no suggestion generated

---

## Test 4: Valid Multilingual Title Sets

Input:

Main Title:

- Groundwater Recharge

Alternative Title:

- Grundwasserneubildung

Expected Result:

- evaluate titles independently

---

# Risks

- Overly aggressive mismatch detection may create noise for borrowed English phrases.
- Formula-like values may trigger unreliable language detection.

---

# Out of Scope

- Full title transliteration support.
- Automatic correction of existing language values.
- Automatic metadata overwrites without curator review.

---

# Open Questions

- Which language detection library should be used?
- Which confidence threshold should trigger suggestions?
- Should confidence values be visible to curators?
- How should dismissed suggestions be stored and referenced?
