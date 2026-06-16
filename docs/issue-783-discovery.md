# Discovery: Apply Accepted Title Languages and Protect Export Behavior (#783)

## Purpose

This discovery describes the implementation of the Title Language Assistant that applies accepted title language suggestions to title records while protecting existing metadata and preparing title-level language information for downstream XML export.

The implementation is based on:

- `modules/assistants/TitleSuggestion/Assistant.php`
- `modules/assistants/TitleSuggestion/manifest.json`
- Composer dependency integration for `nitotm/efficient-language-detector`

---

# Overview

The assistant discovers title records without a language value, detects the title language using the Efficient Language Detector (ELD), and creates reviewable suggestions.

Accepted suggestions update `Title.language`.

The implementation intentionally protects existing metadata, prevents stale suggestions from being applied, and provides title-level language metadata that can later be used by XML export components.

---

# Assistant Registration

The assistant is registered through:

```json
{
    "id": "title-language-suggestion",
    "name": "Title Language Detection",
    "assistant_class": "Modules\\Assistants\\TitleSuggestion\\Assistant"
}
```

The assistant appears in the generic Assistance interface and does not use a custom card component.

```json
"card_component": null
```

The generic Assistance UI is therefore reused.

---

# Language Detection

## Detection Library

Language detection is implemented using:

```php
Nitotm\Eld\LanguageDetector
```

The dependency is provided through Composer:

```json
"nitotm/efficient-language-detector": "^3.2"
```

---

## Supported Languages

The current implementation only generates suggestions for:

```php
private const SUPPORTED_LANGUAGES = [
    'de',
    'en',
    'fr',
];
```

Detected languages outside this list are ignored.

---

# Discovery Scope

The assistant currently evaluates titles that:

- have no language value
- contain non-empty title text

Query:

```php
Title::query()
    ->where(function ($query): void {
        $query
            ->whereNull('language')
            ->orWhere('language', '');
    })
```

As a result:

- missing language values are discovered
- already populated language values are not rediscovered

---

# Suggestion Generation

For each matching title:

1. title text is analyzed
2. language is detected
3. confidence score is calculated
4. a suggestion is stored

Example:

Before:

Title:

Groundwater Recharge

Language:

NULL

Detected Language:

en

Result:

Suggestion created.

---

# Reviewer Preview

The implementation enriches suggestion metadata to support review in the generic Assistance interface.

Stored metadata includes:

```php
'title_text'
'current_language'
'current_language_label'
'proposed_language'
'proposed_language_label'
'confidence'
'confidence_percent'
'reason'
'warning'
'has_overwrite_warning'
'source_hash'
'source_snapshot'
```

---

## Preview Information

The reviewer receives:

- title text
- current language
- proposed language
- confidence score
- explanation text

Example:

Title:

Groundwater Recharge

Current Language:

not set

Proposed Language:

English (en)

Confidence:

95%

Reason:

Detected from title text using ELD language detection.

---

# Existing Language Protection

The implementation explicitly protects existing language values.

During acceptance:

```php
if ($currentLanguage !== null
    && $currentLanguage !== $proposedLanguage) {
```

the suggestion is rejected.

Returned message:

```text
Title already has language 'de'.
It was not overwritten automatically.
```

---

## Current Behaviour

The current implementation:

- generates overwrite warnings in suggestion metadata
- refuses automatic replacement of existing language values
- preserves curator-supplied language assignments
- does not currently provide a separate explicit overwrite workflow

As a result, existing metadata cannot be silently replaced.

---

# Accept Flow

## Successful Acceptance

When a suggestion is accepted:

```php
$title->language = $proposedLanguage;
$title->save();
```

The language value is persisted to the title record.

Example:

Before:

Language:

NULL

Suggestion:

en

After:

Language:

en

---

## Suggestion Removal

Accepted suggestions are handled through the generic Assistance workflow.

After successful acceptance:

- the title is updated
- the suggestion is resolved
- the suggestion is removed from the review queue

This behavior is provided by `GenericTableAssistant`.

---

# Stale Suggestion Protection

Suggestions store a snapshot of the original title state.

Stored values include:

```php
source_hash
source_snapshot
```

Example snapshot:

```php
[
    'title_id',
    'title_text',
    'current_language',
    'resource_id'
]
```

---

## Validation During Acceptance

Before applying a suggestion:

```php
isStale()
```

compares:

- current title state
- stored discovery snapshot

using:

```php
hash_equals()
```

---

## Behaviour

If the title changed after discovery:

- the suggestion is considered stale
- the suggestion cannot be applied
- a new discovery run is required

Returned message:

```text
Suggestion is stale because the title data changed after discovery.
Please run discovery again.
```

This protects against outdated review decisions.

---

# Duplicate Prevention

Suggestions are stored through:

```php
storeSuggestion(...)
```

The generic assistant framework suppresses:

- identical suggestions
- previously dismissed suggestions
- duplicate discovery results

Result:

The same title-language suggestion is not repeatedly recreated.

---

# XML Export Behaviour

The assistant itself does not modify XML export code.

Instead:

1. accepted suggestions populate `Title.language`
2. export components can consume the stored title language
3. XML export support for `xml:lang` must be implemented and verified separately in the DataCite export layer

Expected export behaviour after export integration:

```xml
<title xml:lang="en">
Groundwater Recharge
</title>
```

The assistant provides the title-level language metadata required for XML export.

---

# Mixed-Language Title Sets

Language values are stored per title record.

Example:

Title 1:

Groundwater Recharge

Language:

en

Title 2:

Grundwasserneubildung

Language:

de

Result:

Each title preserves its own language assignment.

The implementation does not force a single language across all titles of a resource.

---

# User Interface Impact

No custom React component was introduced.

Manifest:

```json
"card_component": null
```

The implementation intentionally relies on:

- existing generic Assistance cards
- existing review workflow
- existing acceptance controls

This avoids introducing additional UI complexity.

---

# Risks Addressed

## Existing Metadata Protection

Mitigation:

- overwrite attempts are rejected automatically
- existing language values are preserved

Result:

No silent metadata replacement.

---

## Stale Suggestions

Mitigation:

- source snapshot
- source hash validation

Result:

Suggestions cannot be applied after title changes.

---

## Duplicate Suggestions

Mitigation:

- generic `storeSuggestion()` duplicate handling

Result:

Repeated suggestions are suppressed.

---

# Out of Scope

The implementation does not currently include:

- custom reviewer components
- multilingual conflict detection
- language correction suggestions for already populated titles
- XML export implementation
- export refactoring
- automatic language replacement

---

# Summary

The implemented Title Language Assistant provides:

- automatic title language detection
- reviewer preview information
- confidence reporting
- overwrite protection
- stale suggestion protection
- duplicate suppression
- title language persistence

Accepted suggestions update `Title.language` safely and provide the title-level language metadata required for future `xml:lang` support in DataCite XML exports.