# Issue #783 – Task 1: Reviewer Preview

Part of epic #765 – Title Language Attribute Enrichment Assistant.

## User Story Context

As a curator, I want accepted title language suggestions to update exports cleanly so that DataCite XML gains `xml:lang` coverage without side effects.

Task 1 focuses on the reviewer preview before a curator accepts a title language suggestion.

---

## Goal

The reviewer preview should give the curator enough context to decide whether a title language suggestion can be accepted safely.

The preview should make clear:

* which title is affected
* which language is currently stored
* which language is suggested
* how confident the suggestion is
* whether accepting the suggestion may overwrite existing metadata
* whether the suggestion may be stale because relevant data changed after discovery

---

## Preview Content

Each title language suggestion should show at least:

* title text
* current language
* proposed language
* confidence
* short explanation or warning if there is a conflict
* stale indicator if relevant source data changed after the suggestion was created

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

If a title already has a non-empty language value, the preview should show an explicit warning.

Example conflict warning:

```text
Warning:
This title already has a language value. Accepting this suggestion may overwrite the existing value.
```

---

## XML Export Example

The reviewer preview supports the accept flow that eventually updates the DataCite XML export.

Example before accepting a suggestion:

```xml
<titles>
  <title>Airborne Wind and Eddy Covariance Dataset - Recorded with the ASK-16 EC Platform between 2017 – 2022</title>
</titles>
```

Expected export after accepting the English language suggestion:

```xml
<titles>
  <title xml:lang="en">Airborne Wind and Eddy Covariance Dataset - Recorded with the ASK-16 EC Platform between 2017 – 2022</title>
</titles>
```

The title text itself should not be changed or translated. Only the language metadata should be added or updated.

---

## Implementation Notes / Possible Approach

The reviewer preview may need enough suggestion data to show the curator what will happen before accepting a title language suggestion.

A suggestion preview could be represented with Laravel/PHP-style data like this:

```php
$preview = [
    'id' => $suggestion->id,
    'title_id' => $suggestion->target_id,
    'title_text' => $suggestion->metadata['title_text'] ?? null,
    'current_language' => $suggestion->metadata['current_language'] ?? null,
    'proposed_language' => $suggestion->suggested_value,
    'confidence' => $suggestion->similarity_score,
    'reason' => $suggestion->metadata['reason'] ?? null,
    'created_at' => $suggestion->discovered_at,
    'is_stale' => false,
];
```

This is only an illustrative example for the preview data. The final implementation should use the existing ERNIE models, assistant suggestion fields, and Assistance UI patterns where possible.

---

## Optional PHP Pseudocode

The following pseudocode is only an illustrative example for the reviewer preview logic. It does not define the final ERNIE implementation.

```php
private function buildTitleLanguagePreview(AssistantSuggestion $suggestion): array
{
    $currentLanguage = $suggestion->metadata['current_language'] ?? null;
    $proposedLanguage = $suggestion->suggested_value;

    $hasCurrentLanguage = $currentLanguage !== null && $currentLanguage !== '';
    $hasConfidence = $suggestion->similarity_score !== null;

    return [
        'title_text' => $suggestion->metadata['title_text'] ?? '',
        'current_language' => $hasCurrentLanguage ? $currentLanguage : 'not set',
        'proposed_language' => $proposedLanguage,
        'confidence_label' => $hasConfidence
            ? round($suggestion->similarity_score * 100) . '%'
            : 'not available',
        'show_overwrite_warning' => $hasCurrentLanguage && $currentLanguage !== $proposedLanguage,
        'show_stale_warning' => $this->isStale($suggestion),
    ];
}
```

The backend accept flow should still validate stale suggestions and overwrite conditions server-side. The preview should not be the only protection against unsafe metadata updates.

---

## Preview States

The reviewer preview should support at least three states:

### Normal suggestion

A normal suggestion is shown when the title has no current language value and the suggestion can be reviewed without conflict.

Expected preview behaviour:

* show the title text
* show current language as `not set`
* show proposed language
* show confidence if available
* do not show an overwrite warning

### Overwrite-risk suggestion

An overwrite-risk suggestion is shown when the title already has a non-empty language value.

Expected preview behaviour:

* show the title text
* show the current language value
* show the proposed language value
* show a clear overwrite warning
* avoid wording that implies the change is already applied

### Stale suggestion

A stale suggestion is shown when relevant source data changed after the suggestion was created.

Relevant source data may include:

* title text
* current title language
* resource language

Expected preview behaviour:

* show that the suggestion may be outdated
* prevent the curator from accepting outdated information silently
* guide the curator to re-run discovery or review the current title data

---

## Stale Suggestion Handling

A suggestion may become stale if relevant data changes after discovery, for example:

* title text changed
* current title language changed
* resource language changed

One possible approach is to store a source snapshot or source hash when the suggestion is created and compare it with the current title/resource state before showing or accepting the suggestion.

This document does not define the final backend implementation, but the preview should make stale or potentially unsafe suggestions visible to the curator or prevent silent acceptance.

---

## Dismissed Suggestions

Dismissed suggestions should be handled persistently so they do not immediately reappear after another discovery run.

For Task 1, this is relevant mainly as preview and test context. The actual dismissed-suggestion persistence belongs to the discovery or accept-flow implementation.

---

## Boundary to Accept Flow

The reviewer preview is a curator-facing safety layer. It should clearly show possible conflicts before acceptance.

However, the backend accept flow should still validate stale suggestions and overwrite conditions server-side. The preview should not be the only protection against unsafe metadata updates.

Task 1 does not implement the full accept flow. It prepares the preview requirements and test expectations for safe curator review.

---

## Expected Behaviour

* The preview shows the affected title text.
* The preview shows the current title language.
* The preview shows the proposed language.
* The preview shows confidence if available.
* The preview handles missing confidence gracefully.
* The preview shows an overwrite warning if the current title language is non-empty.
* The preview can indicate stale or potentially outdated suggestions.
* The preview does not suggest automatic metadata changes without curator approval.
* The preview should reuse existing Assistance UI patterns where possible.

---

## Test Scenarios

### Scenario 1: Preview for title without current language

Given a title has no current language value,
when a language suggestion is shown,
then the preview displays the title text, proposed language, and confidence,
and no overwrite warning is shown.

### Scenario 2: Preview for title with existing language

Given a title already has a non-empty language value,
when a different language is suggested,
then the preview displays the current language, proposed language, and an overwrite warning.

### Scenario 3: Preview with missing confidence

Given a suggestion has no confidence value,
when the preview is displayed,
then the UI handles the missing confidence value gracefully.

### Scenario 4: Mixed-language title set

Given a resource has multiple titles in different languages,
when suggestions are shown,
then each preview clearly identifies the affected title and its proposed language.

### Scenario 5: Stale review information

Given a title was changed after the suggestion was created,
when the preview is displayed or accepted,
then the system should avoid applying outdated review information silently.

---

## Potential Impact

* unclear reviewer information
* incorrect language assignment
* accidental overwrite of existing title language metadata
* outdated review information
* curator accepting a suggestion without enough context

---

## Open Questions

* Should the preview display language codes only, or both code and label, for example `en` / English?
* Should confidence be displayed as a percentage or decimal value?
* Should low-confidence suggestions receive a stronger visual warning?
* Should the current resource language also be shown for comparison?
* How should stale suggestions be marked in the preview?
* Is the generic Assistance card sufficient for this preview, or are additional fields needed?
* Should stale suggestions be disabled for acceptance or only shown with a warning?
* Should the preview show when the suggestion was created?

---

## Validation Checklist

* [ ] Preview displays the affected title text.
* [ ] Preview displays the current language value.
* [ ] Preview displays the proposed language value.
* [ ] Preview displays confidence if available.
* [ ] Preview handles missing confidence gracefully.
* [ ] Preview shows no overwrite warning when current language is empty.
* [ ] Preview shows an overwrite warning when current language is non-empty.
* [ ] Preview can represent stale suggestions.
* [ ] Preview gives enough context before the curator accepts the suggestion.
* [ ] Preview does not imply automatic metadata changes without curator approval.


