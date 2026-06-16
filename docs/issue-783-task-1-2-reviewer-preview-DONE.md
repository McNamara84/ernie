# Work Log – Title Language Detection Assistant

Part of epic #765 – Title Language Attribute Enrichment Assistant.
Related branch: `doc/783-Apply-accepted-title-languages-and-protect-export-behavior`
Latest pushed commit: `59e0da2b`

---

## Goal

Today’s work focused on making the Title Language Detection Assistant available in ERNIE and improving the reviewer preview for title language suggestions.

The assistant should help curators review detected title languages before accepting a suggestion, without silently overwriting existing metadata.

---

## Implemented / Verified

### Assistant Registration

* Added and registered the `Title Language Detection` assistant.
* Verified locally that the assistant appears under **Administration → Assistance**.
* Verified that the assistant can be triggered with **Check**.

### Language Detection

* Connected the ELD language detection library.
* Verified the correct detector class: `Nitotm\Eld\LanguageDetector`.
* Verified locally that language detection works for English title text.
* Limited suggestions to supported languages:

  * `de`
  * `en`
  * `fr`

### Discovery

* Implemented discovery for titles with missing language values.
* The assistant creates title-language suggestions for titles where the language is not set.
* Tested locally with ERNIE test data.
* The assistant created new pending suggestions successfully.

### Reviewer Preview Information

The generic Assistance card currently shows the suggestion label.
To make the preview more useful, the label now includes:

* proposed language
* language code
* confidence percentage
* current language
* title text

Example label:

```text
English (en) · 61% confidence · current: not set · "TEST: Mandatory Fields Only"
```

### Metadata Stored for Preview / Future UI

Additional preview-relevant metadata is stored with each suggestion:

* title text
* current language
* current language label
* proposed language
* proposed language label
* confidence
* confidence percentage
* reason / explanation
* overwrite warning information
* stale-check information
* source hash
* source snapshot

This should make it easier to build a custom preview card later if the generic Assistance card is not sufficient.

### Overwrite Protection

The accept flow now checks the current title language before applying a suggestion.

If the title already has a non-empty language value and the suggested language is different, the suggestion is not applied automatically.

This prevents accidental overwrite of existing title language metadata.

### Stale Suggestion Support

A source hash and source snapshot are stored when the suggestion is created.

Before accepting a suggestion, the assistant compares the stored source hash with the current title data.

If relevant source data changed after discovery, the suggestion is treated as stale and is not silently applied.

---

## Local Validation

Verified locally:

* Assistant appears under Assistance.
* `Check` runs successfully.
* Suggestions are created on local test data.
* Suggestions show confidence percentage in the preview label.
* Existing suggestions can be regenerated to show the updated preview label.
* PHP syntax check passes.
* Latest changes were committed and pushed.

Latest pushed commit:

```text
59e0da2b feat: improve title language suggestion preview
```

---

## Current Scope

The current implementation covers:

* titles with missing language values
* title-language suggestions for `de`, `en`, and `fr`
* reviewer preview information via the generic suggestion label
* metadata needed for a richer preview
* backend overwrite protection
* backend stale-check protection

---

## Still Open / To Align

The following points still need alignment with #782 / Task 1 selection logic:

* suspicious language conflicts where an existing stored language differs from the detected language
* exclusion of formula-like, code-like or symbol-heavy titles
* whether the generic Assistance card is sufficient or whether a custom preview component is needed
* how stale suggestions should be displayed visually in the UI
* whether low-confidence suggestions should receive a stronger warning
* whether current resource language should also be shown in the preview

---

## Conflict Note

PR #875 currently conflicts with the updated implementation in:

* `modules/assistants/TitleSuggestion/Assistant.php`
* `modules/assistants/TitleSuggestion/manifest.json`

Because the current #783 branch now contains a working local implementation, I would avoid resolving the conflict via the GitHub web editor before we decide which implementation should be kept.

---

## Validation Checklist

* [x] Assistant is visible under Assistance.
* [x] Assistant can run discovery.
* [x] Suggestions are created for titles with missing language values.
* [x] Preview label shows proposed language.
* [x] Preview label shows confidence percentage.
* [x] Preview label shows current language.
* [x] Preview label includes title text.
* [x] Metadata stores preview-relevant fields.
* [x] Backend prevents automatic overwrite of existing different title language.
* [x] Backend checks for stale suggestions before applying.
* [ ] Suspicious language conflicts are fully aligned with #782.
* [ ] Formula/code-like title exclusion is fully aligned with #782.
* [ ] Decision needed: generic Assistance card vs. custom preview component.
