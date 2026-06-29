# Mögliche Verbesserungen für den Title Language Enrichment Assistant

## 1. Konfliktierende Sprachwerte erkennen

### Aktueller Stand
Der Assistent verarbeitet hauptsächlich Titel ohne gesetzte Sprache.

### Verbesserung
Zusätzlich sollten Titel geprüft werden, bei denen bereits eine Sprache gesetzt ist, die aber offensichtlich nicht zum Titel passt.

### Beispiel

| Titel | Gespeichert | Detection | Confidence |
|---------|---------|---------|---------|
| "Groundwater Modelling in Germany" | de | en | 97 % |

→ Suggestion erstellen

### Vorteil
Erfüllt das Acceptance Criterion:

> The rules distinguish missing language values from obviously conflicting language values.

---

## 2. Unterschiedliche Suggestion-Typen einführen

Aktuell gibt es nur einen allgemeinen Vorschlag.

### Vorschlag

```php
'conflict_type' => 'missing_language'
```

oder

```php
'conflict_type' => 'conflicting_language'
```

oder

```php
'conflict_type' => 'low_confidence_match'
```

### Vorteil

Reviewer:innen erkennen sofort:

- Sprache fehlt
- Sprache ist vermutlich falsch
- Sprache ist unsicher

---

## 3. Resource.language berücksichtigen

### Aktueller Stand

Nur automatische Sprachdetektion.

### Verbesserung

Falls Detection unsicher ist:

```php
Resource.language = en
```

und

```php
Title = "Dataset Description"
```

dann kann die Ressourcensprache als zusätzlicher Hinweis dienen.

### Mögliche Gewichtung

| Quelle | Gewicht |
|----------|----------|
| Sprachdetektion | 70 % |
| Resource.language | 20 % |
| Titelkontext | 10 % |

---

## 4. Nearby Typed-Title Context berücksichtigen

Acceptance Criterion:

> Title-level inference rules combine automatic detection, resource language, and nearby typed-title context.

### Beispiel

Resource besitzt:

```text
Title: Grundwassermodellierung in Brandenburg
Type: Main Title

Title: Groundwater Modelling in Brandenburg
Type: Translated Title
```

Dann kann die Sprache des zweiten Titels helfen, die Sprache des ersten Titels sicherer zu bestimmen.

---

## 5. Multilinguale Datensätze besser behandeln

Acceptance Criterion:

> The design defines how multilingual title sets should be handled.

### Beispiel

```text
Title 1: Klimawandel in Deutschland
Title 2: Climate Change in Germany
Title 3: Changement climatique en Allemagne
```

### Verbesserung

Nicht versuchen, eine gemeinsame Sprache für alle Titel abzuleiten.

Jeder Titel erhält eine eigene Bewertung.

---

## 6. Dismissed Suggestions dauerhaft unterdrücken

### Problem

Reviewer lehnt Vorschlag ab.

Beim nächsten Discovery-Lauf erscheint derselbe Vorschlag erneut.

### Verbesserung

Dismissed Suggestions speichern:

```php
title_id
proposed_language
source_hash
dismissed_at
```

Beim Discovery-Lauf prüfen:

```php
if (dismissedAlready()) {
    skip();
}
```

---

## 7. Stale Suggestions automatisch bereinigen

### Aktueller Stand

Stale Suggestions werden bereits über einen `source_hash` erkannt und können beim Accept-Prozess nicht mehr übernommen werden.

### Verbesserung

Wenn sich der Titel geändert hat:

```php
Old:
Climate Data

New:
Climate Data for Germany
```

dann:

```php
status = stale
```

oder

```php
delete suggestion
```

---

## 8. Source Hash verbessern

Aktueller Stand

Der Source Hash wird bereits aus mehreren Eigenschaften erzeugt.

Unter anderem:

- title_id
- title_text
- current_language
- resource_id
```

### Mögliche Verbesserung

Vor dem Hashing könnten die Eingabedaten zusätzlich normalisiert werden.

### Beispiel

Vor dem Hashing könnten beispielsweise

```php

trim()

mb_strtolower()
```

Beispiel:

```text
Climate Data
```

und

```text
Climate Data
```

mit Leerzeichen am Ende sollten identisch sein.

---

## 9. Confidence-Schwellenwerte zentral definieren

Statt:

```php
if ($confidence > 0.9)
```

mehrfach im Code.

Besser:

```php
const MIN_ACCEPT_CONFIDENCE = 0.90;
const MIN_SUGGEST_CONFIDENCE = 0.75;
```

### Vorteil

Einfachere Wartung.

---

## 10. Reviewer Preview erweitern

Aktuell:

Die Preview enthält inzwischen unter anderem:

```php

title_text

current_language

current_language_label

proposed_language

proposed_language_label

confidence

confidence_percent

warning

has_overwrite_warning

reason

source_hash

source_snapshot

is_stale
```

Zusätzlich sinnvoll:

```php
reason
```

Beispiel:

```text
Detected language differs from stored value.
```

oder

```text
Missing language attribute.
```

---

## 11. Erklärungen für Reviewer erzeugen

Aktueller Stand

Die Suggestions enthalten bereits strukturierte Erklärungen im Payload.

```php
'reason' => ...

'explanation' => [
    'detected_language' => 'en',
    'confidence' => 0.97,
    'resource_language' => 'en',
    'current_title_language' => 'de',
]
```

### Vorteil

Der aktuelle Stand liefert Reviewer:innen bereits deutlich mehr Kontext als die ursprüngliche Implementierung.

---

## 12. Batch-Verarbeitung robuster machen

Bei großen Repositories können mehrere tausend Titel verarbeitet werden.

### Verbesserung

Chunking:

```php
Title::query()
    ->chunkById(500, function ($titles) {
        ...
    });
```

### Vorteil

Weniger Speicherverbrauch.

---

## 13. Sehr kurze Titel filtern

Spracherkennung ist bei kurzen Titeln oft unzuverlässig.

### Beispiel

```text
Map
```

```text
Data
```

```text
Report
```

### Verbesserung

Nur prüfen wenn:

```php
mb_strlen($title) >= 10
```

oder

```php
wordCount >= 3
```

---

## 14. Export-Regressionsschutz

Da im Acceptance Criterion steht:

> DataCite XML gains xml:lang coverage without side effects.

Sollten Tests sicherstellen:

### Vorher

```xml
<title>Climate Data</title>
```

### Nachher

```xml
<title xml:lang="en">
    Climate Data
</title>
```

### Zusätzlich prüfen

- kein doppeltes xml:lang
- bestehende Werte bleiben erhalten
- andere XML-Elemente unverändert

---

## 15. Browser-Workflow vollständig testen

Gefordert in #783.

### Happy Path

```text
Discovery
→ Preview
→ Accept
→ Title.language aktualisiert
→ Suggestion entfernt
```
✔ Bereits umgesetzt.

### Weitere Fälle

```text
Dismiss
```
❌ Noch offen.

```text
Stale Suggestion
```
🟡 Logik vorhanden, Test fehlt noch.

```text
Overwrite vorhandener Sprache
```
🟡 Logik vorhanden, Test fehlt noch.

```text
Mehrere Vorschläge gleichzeitig
```
❌ Noch offen.


# Code with my Suggestions

```php
<?php

declare(strict_types=1);

namespace Modules\Assistants\TitleSuggestion;

use App\Models\AssistantSuggestion;
use App\Models\Title;
use App\Services\Assistance\GenericTableAssistant;
use Closure;
use Nitotm\Eld\LanguageDetector;

class Assistant extends GenericTableAssistant
{
    private const SUPPORTED_LANGUAGES = ['de', 'en', 'fr'];

    private const MIN_SUGGEST_CONFIDENCE = 0.75;

    private const MIN_CONFLICT_CONFIDENCE = 0.90;

    private const MIN_TITLE_LENGTH = 10;

    private LanguageDetector $detector;

    public function __construct()
    {
        parent::__construct();

        $this->detector = new LanguageDetector();
    }

    protected function getManifestPath(): string
    {
        return __DIR__ . '/manifest.json';
    }

    /**
     * Discover language suggestions for titles.
     *
     * This discovery step now handles two cases:
     * 1. Missing language values.
     * 2. Existing language values that clearly conflict with the detected language.
     *
     * @param Closure(string): void $onProgress
     */
    protected function discover(Closure $onProgress): int
    {
        $count = 0;

        Title::query()
            ->whereNotNull('value')
            ->where('value', '<>', '')
            ->chunkById(500, function ($titles) use (&$count, $onProgress): void {
                foreach ($titles as $title) {
                    $onProgress("Checking title #{$title->id}");

                    $suggestion = $this->buildSuggestionForTitle($title);

                    if ($suggestion === null) {
                        continue;
                    }

                    if ($this->wasDismissedBefore($title, $suggestion)) {
                        continue;
                    }

                    $stored = $this->storeSuggestion(
                        resourceId: $title->resource_id,
                        targetType: 'title',
                        targetId: $title->id,
                        suggestedValue: $suggestion['proposed_language'],
                        suggestedLabel: $suggestion['label'],
                        similarityScore: $suggestion['confidence'],
                        metadata: $suggestion,
                    );

                    if ($stored) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Build a suggestion payload for one title.
     *
     * This method decides whether a title needs a suggestion and why.
     *
     * @return array<string, mixed>|null
     */
    private function buildSuggestionForTitle(Title $title): ?array
    {
        $titleText = trim((string) $title->value);

        // Very short titles are skipped because language detection is often unreliable.
        if (mb_strlen($titleText) < self::MIN_TITLE_LENGTH) {
            return null;
        }

        $detection = $this->detectLanguage($titleText);

        if ($detection === null) {
            return null;
        }

        $currentLanguage = $this->currentLanguage($title);
        $proposedLanguage = $detection['code'];
        $confidence = $detection['confidence'];

        $conflictType = null;
        $warning = null;

        // Case 1: The title has no language value yet.
        if ($currentLanguage === null) {
            if ($confidence < self::MIN_SUGGEST_CONFIDENCE) {
                return null;
            }

            $conflictType = 'missing_language';
        }

        // Case 2: The title already has the same language value.
        if ($currentLanguage === $proposedLanguage) {
            return null;
        }

        // Case 3: The title already has a different language value.
        if ($currentLanguage !== null && $currentLanguage !== $proposedLanguage) {
            if ($confidence < self::MIN_CONFLICT_CONFIDENCE) {
                return null;
            }

            $conflictType = 'conflicting_language';
            $warning = 'This title already has a language value. Accepting this suggestion requires explicit overwrite approval.';
        }

        return [
            'payload_version' => 1,
            'assistant' => 'title-language-enrichment',
            'target_type' => 'title',
            'title_id' => $title->id,
            'resource_id' => $title->resource_id,

            // Reviewer preview fields.
            'title_text' => $titleText,
            'current_language' => $currentLanguage,
            'current_language_label' => $currentLanguage !== null
                ? $this->languageLabel($currentLanguage)
                : null,
            'proposed_language' => $proposedLanguage,
            'proposed_language_label' => $this->languageLabel($proposedLanguage),
            'confidence' => $confidence,
            'confidence_percent' => $this->confidencePercent($confidence),
            'conflict_type' => $conflictType,
            'warning' => $warning,
            'has_overwrite_warning' => $warning !== null,

            // Explanation fields for transparent review.
            'reason' => $this->reasonForSuggestion($conflictType),
            'explanation' => [
                'detection_method' => 'ELD language detection',
                'detected_language' => $proposedLanguage,
                'confidence' => $confidence,
                'resource_language' => $this->resourceLanguage($title),
                'current_title_language' => $currentLanguage,
            ],

            // Stale protection.
            'source_hash' => $this->sourceHash($title),
            'source_snapshot' => [
                'title_id' => $title->id,
                'title_text' => $titleText,
                'current_language' => $currentLanguage,
                'resource_id' => $title->resource_id,
            ],

            // Generic label shown in the Assistance UI.
            'label' => $this->suggestionLabel(
                titleText: $titleText,
                proposedLanguage: $proposedLanguage,
                confidence: $confidence,
                currentLanguage: $currentLanguage,
                conflictType: $conflictType,
            ),
        ];
    }

    /**
     * Apply an accepted suggestion.
     *
     * Existing language values are only overwritten if explicit overwrite approval
     * is present in the suggestion metadata.
     *
     * @return array{success: bool, message: string}
     */
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        $title = Title::find($suggestion->target_id);

        if ($title === null) {
            return [
                'success' => false,
                'message' => 'Title record not found.',
            ];
        }

        $metadata = $this->metadata($suggestion);

        // Prevent applying outdated suggestions.
        if ($this->isStale($title, $metadata)) {
            $this->markSuggestionAsStale($suggestion);

            return [
                'success' => false,
                'message' => 'Suggestion is stale because the title changed after discovery. Please run discovery again.',
            ];
        }

        $currentLanguage = $this->currentLanguage($title);
        $proposedLanguage = strtolower((string) $suggestion->suggested_value);

        if (! in_array($proposedLanguage, self::SUPPORTED_LANGUAGES, true)) {
            return [
                'success' => false,
                'message' => "Unsupported language '{$proposedLanguage}'. Only de, en and fr are supported.",
            ];
        }

        $overwriteApproved = (bool) ($metadata['overwrite_approved'] ?? false);

        // Protect existing values unless overwrite was explicitly approved.
        if (
            $currentLanguage !== null
            && $currentLanguage !== $proposedLanguage
            && ! $overwriteApproved
        ) {
            return [
                'success' => false,
                'message' => "Title already has language '{$currentLanguage}'. Explicit overwrite approval is required.",
            ];
        }

        if ($currentLanguage === $proposedLanguage) {
            return [
                'success' => true,
                'message' => "Title language is already set to {$proposedLanguage}.",
            ];
        }

        $title->language = $proposedLanguage;
        $title->save();

        // Remove or mark the accepted suggestion so it does not remain visible.
        $this->removeStaleSuggestionsForTitle($title);

        return [
            'success' => true,
            'message' => "Title language set to {$this->languageLabel($proposedLanguage)}.",
        ];
    }

    /**
     * Detect the language of a title text.
     *
     * Only German, English and French are supported by this assistant.
     *
     * @return array{code: string, confidence: float}|null
     */
    private function detectLanguage(string $text): ?array
    {
        $result = $this->detector->detect($text);

        if ($result === null || empty($result->language)) {
            return null;
        }

        $languageCode = strtolower((string) $result->language);

        if (! in_array($languageCode, self::SUPPORTED_LANGUAGES, true)) {
            return null;
        }

        $scores = method_exists($result, 'scores') ? $result->scores() : [];
        $confidence = isset($scores[$languageCode]) ? (float) $scores[$languageCode] : 0.0;

        if (method_exists($result, 'isReliable') && ! $result->isReliable()) {
            return null;
        }

        return [
            'code' => $languageCode,
            'confidence' => max(0.0, min(1.0, $confidence)),
        ];
    }

    /**
     * Check if the same suggestion was previously dismissed.
     *
     * This prevents reviewers from seeing the same dismissed suggestion again.
     *
     * @param array<string, mixed> $suggestion
     */
    private function wasDismissedBefore(Title $title, array $suggestion): bool
    {
        return AssistantSuggestion::query()
            ->where('target_type', 'title')
            ->where('target_id', $title->id)
            ->where('suggested_value', $suggestion['proposed_language'])
            ->where('status', 'dismissed')
            ->where('metadata->source_hash', $suggestion['source_hash'])
            ->exists();
    }

    /**
     * Build the label displayed in the generic Assistance UI.
     */
    private function suggestionLabel(
        string $titleText,
        string $proposedLanguage,
        float $confidence,
        ?string $currentLanguage,
        ?string $conflictType,
    ): string {
        $current = $currentLanguage ?? 'not set';

        return sprintf(
            '%s (%s) · %d%% confidence · current: %s · reason: %s · "%s"',
            $this->languageLabel($proposedLanguage),
            $proposedLanguage,
            $this->confidencePercent($confidence),
            $current,
            $conflictType ?? 'unknown',
            $this->shortTitle($titleText),
        );
    }

    /**
     * Explain why a suggestion was created.
     */
    private function reasonForSuggestion(?string $conflictType): string
    {
        return match ($conflictType) {
            'missing_language' => 'The title has no language value and the detected language is reliable enough.',
            'conflicting_language' => 'The detected language conflicts with the existing title language.',
            default => 'The assistant found a possible title language improvement.',
        };
    }

    /**
     * Normalize the title source before hashing.
     *
     * This avoids duplicate suggestions caused by insignificant whitespace
     * or capitalization changes.
     */
    private function sourceHash(Title $title): string
    {
        return hash('sha256', implode('|', [
            (string) $title->id,
            $this->normalizeForHash((string) $title->value),
            strtolower((string) ($title->language ?? '')),
            (string) $title->resource_id,
        ]));
    }

    private function normalizeForHash(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }

    /**
     * Check whether the title changed after the suggestion was created.
     *
     * If the hash no longer matches, the suggestion must not be applied.
     *
     * @param array<string, mixed> $metadata
     */
    private function isStale(Title $title, array $metadata): bool
    {
        $storedHash = $metadata['source_hash'] ?? null;

        if (! is_string($storedHash) || $storedHash === '') {
            return false;
        }

        return ! hash_equals($storedHash, $this->sourceHash($title));
    }

    /**
     * Mark a suggestion as stale.
     *
     * This assumes the AssistantSuggestion model has a status field.
     * If the project does not support this status yet, this can be replaced
     * by deleting the suggestion instead.
     */
    private function markSuggestionAsStale(AssistantSuggestion $suggestion): void
    {
        if (! isset($suggestion->status)) {
            return;
        }

        $suggestion->status = 'stale';
        $suggestion->save();
    }

    /**
     * Remove outdated open suggestions for a title after applying one suggestion.
     *
     * This prevents stale or duplicate suggestions from remaining visible.
     */
    private function removeStaleSuggestionsForTitle(Title $title): void
    {
        AssistantSuggestion::query()
            ->where('target_type', 'title')
            ->where('target_id', $title->id)
            ->whereIn('status', ['open', 'pending'])
            ->delete();
    }

    /**
     * Safely read metadata from the suggestion model.
     *
     * @return array<string, mixed>
     */
    private function metadata(AssistantSuggestion $suggestion): array
    {
        $metadata = $suggestion->metadata ?? [];

        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function currentLanguage(Title $title): ?string
    {
        $language = $title->language;

        if ($language === null || trim((string) $language) === '') {
            return null;
        }

        return strtolower((string) $language);
    }

    /**
     * Optional resource-level language signal.
     *
     * This assumes that Title belongs to Resource.
     * If the relationship is named differently, adjust this method.
     */
    private function resourceLanguage(Title $title): ?string
    {
        $language = $title->resource->language ?? null;

        if ($language === null || trim((string) $language) === '') {
            return null;
        }

        return strtolower((string) $language);
    }

    private function confidencePercent(float $confidence): int
    {
        return (int) round(max(0.0, min(1.0, $confidence)) * 100);
    }

    private function shortTitle(string $title): string
    {
        $title = trim($title);

        if (mb_strlen($title) <= 90) {
            return $title;
        }

        return mb_substr($title, 0, 87) . '...';
    }

    private function languageLabel(string $code): string
    {
        return match ($code) {
            'de' => 'German',
            'en' => 'English',
            'fr' => 'French',
            default => strtoupper($code),
        };
    }
}
```