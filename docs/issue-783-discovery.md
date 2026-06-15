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

# Vorschlag Code Emely für Bib https://github.com/nitotm/nitotm.github.io

## manifest.json
{
    "id": "title-language-suggestion",
    "name": "Suggested Title Languages",
    "description": "Detect missing or conflicting title language values.",
    "icon": "Languages",
    "version": "1.0.0",
    "assistant_class": "Modules\\Assistants\\TitleLanguageSuggestion\\Assistant",
    "route_prefix": "title-languages",
    "lock_key": "title_language_discovery_running",
    "cache_key_prefix": "title_language_discovery",
    "sort_order": 50,
    "card_component": "TitleLanguageCard"
}
## manifest Paul 
{
    "id": "title-language-suggestion",
    "name": "Title Language Detection",
    "description": "Detects the language of titles and suggests the code to save.",
    "icon": "Globe",
    "version": "1.0.0",
    "assistant_class": "Modules\\Assistants\\TitleSuggestion\\Assistant",
    "route_prefix": "title-language",
    "lock_key": "title_language_detection_running",
    "cache_key_prefix": "title_language_detection",
    "sort_order": 45,
    "status_labels": {
        "checking": "Detecting title languages...",
        "completed_with_results": "Language detection completed: {count} new suggestion(s) found.",
        "completed_empty": "Language detection completed: No new suggestions found.",
        "failed": "Language detection failed: {error}",
        "already_running": "A language detection job is already running."
    },
    "empty_state": {
        "title": "No pending title language suggestions",
        "description": "All titles already have a language code or no suggestions were found."
    },
    "card_component": null
}
## assistant.php
<?php

declare(strict_types=1);

namespace Modules\Assistants\TitleLanguageSuggestion;

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\Title;
use App\Services\Assistance\GenericTableAssistant;
use Closure;

class Assistant extends GenericTableAssistant
{
    /**
     * Pfad zur manifest.json dieses Assistant-Moduls.
     * Darüber erkennt ERNIE den Assistant automatisch.
     */
    protected function getManifestPath(): string
    {
        return __DIR__ . '/manifest.json';
    }

    /**
     * Sucht nach fehlenden oder widersprüchlichen Title.language-Werten.
     *
     * Diese Methode wird beim Klick auf "Check" ausgeführt.
     *
     * @param Closure(string): void $onProgress
     *        Callback für Fortschrittsmeldungen im Frontend.
     *
     * @return int Anzahl neu gespeicherter Suggestions.
     */
    protected function discover(Closure $onProgress): int
    {
        $count = 0;

        // Alle Resources mit ihren Titles laden.
        // Name ggf. an euer echtes Relation-Model anpassen.
        $resources = Resource::with('titles')->get();

        foreach ($resources as $index => $resource) {
            $onProgress('Checking resource ' . ($index + 1) . ' of ' . $resources->count());

            foreach ($resource->titles as $title) {
                // Pro Titel prüfen, ob eine Suggestion nötig ist.
                $suggestion = $this->buildSuggestionPayload($resource, $title);

                // Wenn kein Problem erkannt wurde, wird nichts gespeichert.
                if ($suggestion === null) {
                    continue;
                }

                // Vorschlag in der generischen Tabelle assistant_suggestions speichern.
                // storeSuggestion vermeidet automatisch Duplikate und bereits abgelehnte Vorschläge.
                $stored = $this->storeSuggestion(
                    resourceId: $resource->id,
                    targetType: 'title',
                    targetId: $title->id,
                    suggestedValue: $suggestion['proposed_language'],
                    suggestedLabel: $suggestion['suggested_label'],
                    similarityScore: $suggestion['confidence'],
                    metadata: $suggestion
                );

                if ($stored) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Baut die eigentliche Suggestion für einen einzelnen Titel.
     *
     * Es gibt zwei Fälle:
     * 1. Die Sprache fehlt.
     * 2. Die Sprache ist vorhanden, passt aber unter 90 % zur erkannten Sprache.
     */
    private function buildSuggestionPayload(Resource $resource, Title $title): ?array
    {
        $detector = new TitleLanguageDetector();

        // Titeltext aus dem Model holen.
        // Falls euer Feld anders heißt, hier z. B. $title->value oder $title->title anpassen.
        $titleText = trim((string) $title->title);

        // Sprache aus dem Title-Model holen.
        // Das entspricht dem xml:lang-Wert bzw. Title.language.
        $currentLanguage = $title->language;

        // Automatische Spracherkennung ausführen.
        $detection = $detector->detect($titleText);

        // Wenn die Erkennung unsicher ist, keine Suggestion erzeugen.
        if ($detection === null) {
            return null;
        }

        $proposedLanguage = $detection['language'];
        $confidence = $detection['confidence'];
        $allMatches = $detection['matches'];

        // Prüfen, wie gut die aktuell gespeicherte Sprache zum Titel passt.
        // Wenn keine aktuelle Sprache vorhanden ist, ist der Match null.
        $currentLanguageMatch = $currentLanguage
            ? ($allMatches[$currentLanguage] ?? 0)
            : null;

        /**
         * Fall 1:
         * Sprache fehlt komplett.
         * Dann soll eine neue Sprache vorgeschlagen werden.
         */
        if (empty($currentLanguage)) {
            return [
                'type' => 'missing_title_language',
                'title_text' => $titleText,
                'current_language' => null,
                'proposed_language' => $proposedLanguage,
                'confidence' => $confidence,
                'current_language_match' => null,
                'threshold' => 0.90,
                'overwrite_warning' => false,
                'suggested_label' => strtoupper($proposedLanguage),
                'explanation' => 'Title language is missing. The assistant detected a likely language.',
            ];
        }

        /**
         * Fall 2:
         * Sprache ist vorhanden, aber der Match liegt unter 90 %.
         * Dann soll ein Korrekturvorschlag erzeugt werden.
         */
        if ($currentLanguageMatch < 0.90 && $currentLanguage !== $proposedLanguage) {
            return [
                'type' => 'conflicting_title_language',
                'title_text' => $titleText,
                'current_language' => $currentLanguage,
                'proposed_language' => $proposedLanguage,
                'confidence' => $confidence,
                'current_language_match' => $currentLanguageMatch,
                'threshold' => 0.90,
                'overwrite_warning' => true,
                'suggested_label' => strtoupper($proposedLanguage),
                'explanation' => 'Existing title language does not match the detected language with at least 90 % confidence.',
            ];
        }

        // Kein Problem gefunden.
        return null;
    }

    /**
     * Wird ausgeführt, wenn Kurator:innen im Frontend auf "Accept" klicken.
     *
     * Die vorgeschlagene Sprache wird in Title.language übernommen.
     */
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        // Den betroffenen Title laden.
        $title = Title::findOrFail($suggestion->target_id);

        // Vorgeschlagene Sprache setzen.
        $title->language = $suggestion->suggested_value;

        // Änderung speichern.
        $title->save();

        return [
            'success' => true,
            'message' => 'Title language updated.',
        ];
    }
}

## TitleLanguageDetector.php
<?php

declare(strict_types=1);

namespace Modules\Assistants\TitleLanguageSuggestion;

use LanguageDetection\Language;

class TitleLanguageDetector
{
    /**
     * Mindestlänge für Titel.
     * Sehr kurze Titel sind für automatische Spracherkennung oft zu unsicher.
     */
    private const MIN_TITLE_LENGTH = 12;

    /**
     * Mindest-Confidence für den besten Sprachvorschlag.
     * Darunter wird kein Vorschlag erzeugt.
     */
    private const MIN_CONFIDENCE = 0.75;

    /**
     * Erkennt die Sprache eines Title-Textes.
     *
     * @return array{
     *     language: string,
     *     confidence: float,
     *     matches: array<string, float>
     * }|null
     */
    public function detect(string $text): ?array
    {
        $text = trim($text);

        // Leere oder sehr kurze Titel überspringen.
        if ($text === '' || mb_strlen($text) < self::MIN_TITLE_LENGTH) {
            return null;
        }

        // Unterstützte Sprachen begrenzen.
        // Kann bei euch erweitert werden, z. B. ['de', 'en', 'fr'].
        $detector = new Language(['de', 'en', 'fr', 'es', 'it']);

        // Top 3 erkannte Sprachen holen.
        $matches = $detector
            ->detect($text)
            ->limit(0, 3)
            ->close();

        // Wenn keine Sprache erkannt wurde, keine Suggestion.
        if (empty($matches)) {
            return null;
        }

        // Beste erkannte Sprache ist der erste Eintrag.
        $language = array_key_first($matches);
        $confidence = (float) $matches[$language];

        // Zu unsichere Vorschläge verwerfen.
        if ($confidence < self::MIN_CONFIDENCE) {
            return null;
        }

        return [
            'language' => $language,
            'confidence' => round($confidence, 3),
            'matches' => $matches,
        ];
    }
}

## Logik

if (empty($currentLanguage)) {
    // Sprache fehlt → Vorschlag
}

if ($currentLanguageMatch < 0.90) {
    // Sprache vorhanden, aber wahrscheinlich falsch → Korrekturvorschlag
}