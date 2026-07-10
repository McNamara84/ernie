# Resource Language Enrichment Assistant – Language Evidence Cascade and Confidence Model

## Purpose

This document defines how the Resource Language Enrichment Assistant derives language suggestions for resources that do not yet contain a resource-level language. The goal is to provide explainable and consistent recommendations while keeping the curator in control of the final decision.

---

# Evidence Sources

The assistant combines multiple language signals instead of relying on a single source.

| Evidence Source | Priority | Reliability | Notes |
|-----------------|----------|-------------|-------|
| Title languages (`Title.language`) | 1 | Very High | Explicit curator metadata. |
| Description languages | 2 | Very High | Explicit language assignments on descriptions. |
| Subject languages | 3 | High | Useful when titles/descriptions are unavailable. |
| Publisher default language | 4 | Medium | Fallback only if no stronger evidence exists. |
| Automatic language detection | 5 | Medium | Applied to textual content when explicit metadata is missing. |

---

# Evidence Cascade

The assistant evaluates evidence in the following order.

1. Explicit title language assignments
2. Explicit description language assignments
3. Explicit subject language assignments
4. Publisher default language
5. Automatic language detection

Higher-priority evidence forms the primary basis for the suggested language. Lower-priority evidence does not override higher-priority evidence but may increase or decrease the confidence score when it agrees or conflicts. 
Example:

Title = English

Description = English

Language detection = German

→ Suggested language = English

because explicit metadata has precedence over automatic detection.

---

# Confidence Model

Confidence is determined by combining all available evidence.

| Confidence | Meaning | Action |
|------------|---------|--------|
| 95–100 | Strong agreement between multiple high-priority signals | Suggest automatically |
| 80–94 | Good agreement with minor uncertainty | Suggest |
| 60–79 | Weak agreement or limited evidence | Suggest with low-confidence warning |
| Below 60 | Insufficient confidence | Skip suggestion |

---

# Confidence Factors

Confidence increases when

- multiple evidence sources agree
- explicit metadata exists
- language detection confidence is high
- sufficient text is available

Confidence decreases when

- evidence sources disagree
- text is very short
- multiple languages appear
- only publisher defaults exist

---

# Skip Rules

No suggestion is generated when any of the following conditions apply.

## Existing resource language

The resource already contains a language value.

Action:

Skip.

---

## Low-text records

Available text is too short for reliable detection.

Examples:

- title shorter than 10 characters
- description shorter than 30 characters
- no meaningful textual metadata

Action:

Skip.

---

## Ambiguous detection

Automatic language detection produces no clear result.

Example:

English 42%

German 39%

French 19%

Action:

Skip.

---

## Multilingual resources

Different evidence sources consistently indicate different languages.

Example:

Title: English

Description: German

Subjects: German

Action:

Do not force a single language.

Generate no suggestion.

---

# Decision Matrix

| Scenario | Result |
|----------|--------|
| All evidence agrees | High confidence suggestion |
| Explicit metadata agrees, detection differs | Follow explicit metadata |
| Detection only | Medium confidence suggestion |
| Detection confidence below threshold | Skip |
| Multiple languages detected | Skip |
| Very little text | Skip |
| Existing resource language | Skip |

---

# Suggestion Payload

Each suggestion should expose the information required by the reviewer UI.

```json
{
  "type": "resource-language",
  "proposedLanguage": "en",
  "confidence": 97,
  "evidence": [
    {
      "source": "title",
      "language": "en"
    },
    {
      "source": "description",
      "language": "en"
    },
    {
      "source": "language_detection",
      "language": "en",
      "confidence": 99
    }
  ],
  "summary": "All available language signals consistently identify English.",
  "explanation": "Titles, descriptions, and automatic language detection all indicate English with high confidence."
}
```

---

# Reviewer Guidance

The reviewer should always see

- proposed language
- confidence score
- evidence sources used
- short explanation of the decision

This allows suggestions to remain transparent and easy to verify before acceptance.

---

# Future Extensions

Possible future improvements include

- multilingual resource language suggestions
- weighted publisher-specific language models
- document type specific confidence adjustments
- confidence calibration using real-world curator feedback