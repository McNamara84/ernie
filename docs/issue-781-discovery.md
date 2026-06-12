# Catalogue Title-Level Language Signals and Conflict Cases

## Purpose

This document inventories the signals and edge cases that can be used when inferring the language of individual title records. The goal is to support title-level language suggestions that prioritize the title text itself while also considering resource-level and contextual information.

---

# 1. Title Text Patterns

## Clear Language Indicators

### German

**Example**

```text
Grundwasserneubildung in Trockengebieten
```

**Signals**

- German vocabulary
- Umlauts (ä, ö, ü)
- German grammar and stop words

---

### English

**Example**

```text
Groundwater Recharge in Arid Regions
```

**Signals**

- English vocabulary
- Common English stop words
- English grammar patterns

---

### French

**Example**

```text
Analyse géophysique des sols
```

**Signals**

- French vocabulary
- Accented characters (é, è, à, ç)
- French grammar patterns

---

## Short Titles

**Examples**

```text
Atlas
Data
Report
Map
```

**Risk**

- Insufficient text for reliable language detection
- May exist identically in multiple languages

---

## Technical and Scientific Terms

**Examples**

```text
GNSS Observations
LiDAR Dataset
GPS Measurements
```

**Risk**

- Domain-specific terminology may be language-neutral
- Detection confidence may be low

---

## Titles Containing Mostly Proper Names

**Examples**

```text
Potsdam Climate Impact Study
Berlin Geological Survey
```

**Risk**

- Proper names provide little language information
- Language must be inferred from surrounding words

---

# 2. Resource Language Priors

## Resource Language Available

**Example**

```xml
<language>en</language>
```

**Use**

- Provides supporting evidence
- Can increase confidence when matching title detection

---

## Resource Language Missing

**Example**

```xml
<language/>
```

**Impact**

- No supporting signal available
- Language determination relies on title-level evidence

---

## Resource Language Conflicts with Title

**Example**

Resource language:

```text
en
```

Title:

```text
Grundwasserneubildung in Trockengebieten
```

**Observation**

- Resource language and title language differ
- Title-level evidence should not automatically be overridden

---

# 3. Typed-Title Context Hints

## Main Title and Subtitle

| Title Type | Title |
|------------|--------|
| Main Title | Groundwater Recharge |
| Subtitle | A Regional Study |

**Hint**

- Subtitle is likely in the same language as the main title

---

## Alternative Titles

| Title Type | Title |
|------------|--------|
| Main Title | Groundwater Recharge |
| Alternative Title | Grundwasserneubildung |

**Hint**

- Different title types may intentionally use different languages

---

## Multiple Titles with Consistent Language

| Title Type | Title |
|------------|--------|
| Main Title | Groundwater Recharge |
| Subtitle | A Regional Study |
| Alternative Title | Regional Water Analysis |

**Hint**

- Consistent language across title set increases confidence

---

# 4. Multilingual Edge Cases

## Multiple Languages Within One Resource

| Title Type | Title | Language |
|------------|--------|----------|
| Main Title | Groundwater Recharge | English |
| Alternative Title | Grundwasserneubildung | German |

**Observation**

- Each title should be evaluated independently
- Language should not be inherited across languages

---

## Mixed-Language Titles

**Example**

```text
Groundwater Recharge – Eine Fallstudie aus Brandenburg
```

**Risk**

- Contains multiple languages
- May produce uncertain detection results

---

## Translated Titles

**Examples**

```text
Climate Change
Klimawandel
```

**Observation**

- Titles represent the same concept in different languages
- Both language assignments may be valid

---

## Acronyms and Abbreviations

**Examples**

```text
GFZ Data Report
UNESCO Dataset
NASA Mission Archive
```

**Risk**

- Acronyms contain little language information
- Detection may rely on surrounding text

---

## Numeric or Symbolic Titles

**Examples**

```text
Dataset 2024
Report No. 15
Version 2.0
```

**Risk**

- Almost no language-specific information
- Detection confidence expected to be low

---

# 5. Conflict Cases Inventory

| Case | Description |
|--------|-------------|
| Missing language | No language assigned to title |
| Conflicting language | Stored language differs from detected language |
| Ambiguous short title | Title too short for reliable detection |
| Resource conflict | Resource language differs from title language |
| Mixed-language title | Multiple languages within one title |
| Multilingual title set | Different titles intentionally use different languages |
| Acronym-heavy title | Insufficient linguistic evidence |
| Proper-name-heavy title | Detection based on very limited language clues |

---

# Summary

The following signal categories have been identified:

1. Title text patterns
2. Resource-language priors
3. Typed-title context hints
4. Multilingual edge cases
5. Language conflict scenarios

# Title Language Inference Rules

## Purpose

This document defines the precedence rules for title language suggestions. The goal is to prioritize title-level evidence while using resource-level language and title context as supporting signals.

---

# Guiding Principles

1. Title language should primarily reflect the title text itself.
2. Resource language is a supporting signal, not a replacement for title-level detection.
3. Each title is evaluated independently.
4. Multilingual title sets are allowed and should be preserved.
5. Ambiguous cases should not receive automatic suggestions.

---

# Signal Precedence

Signals are evaluated in the following order:

| Priority | Signal | Role |
|-----------|---------|---------|
| 1 | Title text language detection | Primary evidence |
| 2 | Typed-title context | Supporting evidence |
| 3 | Resource language | Supporting evidence |

---

# Rule 1: Follow Title-Level Detection

## Condition

Language detection returns a clear result with high confidence.

### Example

Title:

```text
Groundwater Recharge in Arid Regions
```

Detected language:

```text
en
```

Confidence:

```text
95%
```

### Result

```text
Suggested language = en
```

### Rationale

The title text is the strongest available signal.

---

# Rule 2: Use Resource Language as Supporting Evidence

## Condition

Language detection confidence is moderate.

### Example

Title:

```text
Regional Water Study
```

Detected language:

```text
en
```

Confidence:

```text
70%
```

Resource language:

```text
en
```

### Result

```text
Suggested language = en
Confidence increased
```

### Rationale

Matching resource language increases confidence but does not replace title-level detection.

---

# Rule 3: Do Not Override Clear Title Evidence

## Condition

Detected language conflicts with resource language.

### Example

Resource language:

```text
en
```

Title:

```text
Grundwasserneubildung in Trockengebieten
```

Detected language:

```text
de
```

Confidence:

```text
96%
```

### Result

```text
Suggested language = de
Explanation = Title language differs from resource language
```

### Rationale

Strong title evidence has higher priority than resource language.

---

# Rule 4: Use Typed-Title Context as Supporting Evidence

## Condition

Related titles strongly indicate the same language.

### Example

| Title Type | Language |
|------------|----------|
| Main Title | en |
| Subtitle | unknown |

Subtitle:

```text
A Regional Study
```

### Result

```text
Suggested language = en
```

### Rationale

Titles belonging to the same title set often share a language.

---

# Rule 5: Preserve Multilingual Title Sets

## Condition

Different titles are written in different languages.

### Example

| Title Type | Title |
|------------|--------|
| Main Title | Groundwater Recharge |
| Alternative Title | Grundwasserneubildung |

### Result

```text
Main Title = en
Alternative Title = de
```

### Rationale

Language should be assigned per title, not per resource.

---

# Rule 6: Skip Ambiguous Cases

## Condition

Language detection confidence is low.

### Examples

```text
Atlas
Data
Report
Map
```

### Result

```text
No suggestion generated
Status = ambiguous
```

### Rationale

Automatic suggestions should only be created when confidence is sufficient.

---

# Rule 7: Skip Mixed-Language Titles

## Condition

Multiple languages appear within the same title.

### Example

```text
Groundwater Recharge – Eine Fallstudie aus Brandenburg
```

### Result

```text
No automatic suggestion
Status = ambiguous
```

### Rationale

The title cannot be reliably assigned to a single language.

---

# Rule 8: Existing Language Values

## Condition

A language value already exists.

### Example

Stored language:

```text
de
```

Detected language:

```text
en
```

### Result

```text
Flag as potential conflict
No automatic overwrite
```

### Rationale

Existing metadata should be reviewed by a curator.

---

# Decision Matrix

| Situation | Action |
|------------|---------|
| High-confidence title detection | Use detected language |
| Moderate-confidence detection + matching resource language | Use detected language |
| High-confidence detection + conflicting resource language | Use detected language |
| Multilingual title set | Evaluate each title independently |
| Ambiguous short title | No suggestion |
| Mixed-language title | No suggestion |
| Existing conflicting language value | Flag for review |

---

# Summary

Language suggestions should primarily follow title-level language detection. Resource language and title context may increase confidence but must not override strong title evidence. Multilingual title sets should be preserved, and ambiguous cases should be skipped to avoid incorrect automatic suggestions.

# Suggestion Payload Contract – Title Language Attribute Enrichment

## Purpose

The suggestion payload defines the structure used by the Title Language Attribute Enrichment Assistant to communicate language recommendations for title records.

The payload provides:

- The title text being evaluated
- The proposed language code
- A confidence score
- An explanation describing how the suggestion was generated

---

## Payload Structure

```json
{
  "titleText": "Climate Change Impacts on Coastal Regions",
  "proposedLanguage": "en",
  "confidence": 0.98,
  "explanation": "Detected as English based on language detection with high confidence and consistency with resource language."
}

Emely-Lotta Behrendt

{ 

"titleId": "title-14302", 

"titleText": "Le Petit Prince", 

"currentLanguage": "en", 

"proposedLanguage": "fr", 

"confidence": "high", 

"confidenceScore": 0.96, 

"suggestionType": "conflict", 

"explanation": "Der Titeltext entspricht mit hoher Wahrscheinlichkeit Französisch und widerspricht der aktuell hinterlegten Sprache Englisch.", 

"signals": { 

"titleDetection": "fr", 

"titleDetectionConfidence": 0.96, 

"resourceLanguage": "en", 

"typedTitleContext": [] 

} 

} 

Für Payload 
Paul Ubben