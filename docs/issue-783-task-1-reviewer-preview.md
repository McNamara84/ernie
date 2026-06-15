# Issue #783 – Task 1: Reviewer Preview

Part of epic #765 – Title Language Attribute Enrichment Assistant.

## User Story Context

As a curator, I want accepted title language suggestions to update exports cleanly so that DataCite XML gains `xml:lang` coverage without side effects.

Task 1 focuses on the reviewer preview before a curator accepts a title language suggestion.

---

## Goal

The reviewer preview should give the curator enough context to decide whether a title language suggestion can be accepted safely.

The preview should make clear:

- which title is affected
- which language is currently stored
- which language is suggested
- how confident the suggestion is
- whether accepting the suggestion may overwrite existing metadata

---

## Preview Content

Each title language suggestion should show at least:

- title text
- current language
- proposed language
- confidence
- short explanation or warning if there is a conflict

Example preview content:

```text
Title:
Airborne Wind and Eddy Covariance Dataset - Recorded with the ASK-16 EC Platform between 2017 – 2022

Current language:
not set

Proposed language:
English (en)

Confidence:
95%

Warning:
No existing title language will be overwritten.
```


---

## Implementation Notes / Possible Approach

The reviewer preview may need enough suggestion data to show the curator what will happen before accepting a title language suggestion.

A suggestion should provide or derive at least the following information:

```ts
type TitleLanguageSuggestionPreview = {
  id: string;
  titleId: string;
  titleText: string;
  currentLanguage: string | null;
  proposedLanguage: string;
  confidence?: number;
  reason?: string;
  createdAt?: string;
  isStale?: boolean;
};
