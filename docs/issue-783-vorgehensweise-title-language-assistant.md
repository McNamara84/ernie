# Issue #783 – Vorgehensweise: Title Language Detection Assistant – by Maryia Balonnikava

Part of epic #765 – Title Language Attribute Enrichment Assistant.

## Branch / Commit

This work was committed and pushed to the #783 development branch.

Latest relevant commit:

```text
59e0da2b feat: improve title language suggestion preview
```

---

## Ziel

Ziel war es, den `Title Language Detection` Assistant lokal lauffähig zu machen, ihn in ERNIE unter **Assistance** sichtbar zu registrieren und erste title-language suggestions mit ausreichend Preview-Informationen für Kurator:innen zu erzeugen.

Der Fokus lag dabei auf einer sicheren Reviewer Preview und darauf, keine bestehenden Sprachmetadaten stillschweigend zu überschreiben.

---

## Vorgehensweise

### 1. Lokale Umgebung geprüft

Zuerst wurde sichergestellt, dass auf dem richtigen #783 development branch gearbeitet wird.

Außerdem wurde die lokale ERNIE-Docker-Umgebung gestartet und geprüft.

---

### 2. Assistant-Dateien geprüft und vorbereitet

Die relevanten Dateien wurden vorbereitet:

```text
modules/assistants/TitleSuggestion/Assistant.php
modules/assistants/TitleSuggestion/manifest.json
```

Dabei wurde darauf geachtet, dass die Assistant-Klasse korrekt registriert wird:

```text
Modules\\Assistants\\TitleSuggestion\\Assistant
```

Außerdem wurde die Dateibenennung auf `Assistant.php` vereinheitlicht.

---

### 3. ELD Language Detection Library angebunden

Für die Spracherkennung wurde die ELD Library verwendet.

Wichtig war, die korrekte Klasse zu verwenden:

```php
Nitotm\Eld\LanguageDetector
```

Die lokale Prüfung zeigte, dass die Library ein Ergebnis mit Sprache, Scores und Reliability liefert.

Beispiel:

```text
language: en
scores: [...]
isReliable: true
```

Daraufhin wurde die Detection-Logik so angepasst, dass der Confidence-Wert aus den Scores gelesen und als Prozentwert für die Preview verwendet werden kann.

---

### 4. Assistant in ERNIE registriert

Nach dem Anpassen von `Assistant.php` und `manifest.json` wurden Cache und Container aktualisiert.

Danach erschien der Assistant lokal unter **Assistance** als:

```text
Title Language Detection
```

---

### 5. Discovery getestet

Der Assistant wurde über **Check** ausgeführt.

Dabei wurde festgestellt, dass alte Suggestions bereits gespeicherte Labels behalten. Um die neue Preview mit Confidence-Prozentwerten zu testen, wurden alte title suggestions lokal entfernt und Discovery erneut ausgeführt.

Danach wurden neue Suggestions erstellt und die Preview zeigte unter anderem:

```text
English (en) · 61% confidence · current: not set · "TEST: Mandatory Fields Only"
```

---

### 6. Reviewer Preview verbessert

Da aktuell noch die generische Assistance Card verwendet wird, wurde die wichtigste Preview-Information direkt in das `suggested_label` aufgenommen.

Die Preview enthält nun:

* title text
* current language
* proposed language
* confidence percentage

Zusätzlich werden preview-relevante Daten in `metadata` gespeichert, damit später eine eigene Preview Card oder Detailansicht darauf aufbauen kann.

Gespeicherte Metadata umfasst unter anderem:

* title text
* current language
* current language label
* proposed language
* proposed language label
* confidence
* confidence percentage
* reason / explanation
* overwrite warning information
* source hash
* source snapshot
* stale-check information

---

### 7. Schutz gegen unsicheres Überschreiben ergänzt

Im Accept-Flow wurde serverseitig geprüft, ob bereits ein Sprachwert existiert.

Wenn ein Titel bereits eine nicht-leere Sprache hat und die vorgeschlagene Sprache abweicht, wird die Suggestion nicht automatisch angewendet.

Damit wird verhindert, dass bestehende Sprachmetadaten stillschweigend überschrieben werden.

---

### 8. Stale-Suggestion-Grundlage ergänzt

Beim Erstellen einer Suggestion werden ein Source Snapshot und ein Source Hash gespeichert.

Vor dem Anwenden einer akzeptierten Suggestion wird geprüft, ob sich relevante Quelldaten geändert haben.

Wenn die Suggestion stale ist, wird sie nicht stillschweigend angewendet.

---

### 9. Lokal validiert

Lokal wurde geprüft:

* Assistant erscheint unter Assistance
* `Check` läuft erfolgreich
* Suggestions werden erstellt
* Preview zeigt Confidence als Prozentwert
* PHP syntax check läuft erfolgreich
* Änderungen wurden committed und nach GitHub gepusht

---

## Aktueller Stand

Die aktuelle Implementierung deckt ab:

* Registrierung des Assistants
* Detection für `de`, `en`, `fr`
* Discovery für Titel mit fehlender Sprache
* Erstellung von title-language suggestions
* Preview-Information über das generische Suggestion Label
* Speicherung zusätzlicher Preview-Metadata
* serverseitigen Schutz vor automatischem Überschreiben
* serverseitige Grundlage für Stale Checks

---

## Noch offen / Abstimmung mit #782

Noch mit #782 beziehungsweise der Task-1-Auswahllogik abzustimmen:

* verdächtige Sprachkonflikte, bei denen eine bestehende Sprache von der erkannten Sprache abweicht
* Ausschluss von formelartigen, codeartigen oder stark symbolhaltigen Titeln
* Entscheidung, ob die generische Assistance Card ausreicht oder eine eigene Preview-Komponente nötig ist
* visuelle Darstellung von stale suggestions
* Umgang mit low-confidence suggestions

---

## Hinweis zu PR #875

PR #875 enthält ebenfalls Änderungen am TitleSuggestion Assistant und hat aktuell Konflikte in:

```text
modules/assistants/TitleSuggestion/Assistant.php
modules/assistants/TitleSuggestion/manifest.json
```

Da auf dem #783-Branch bereits eine lokal getestete Implementierung vorhanden ist, sollte der Konflikt nicht unüberlegt im GitHub Web Editor gelöst werden.

Stattdessen sollte abgestimmt werden, ob PR #875 rebased/geschlossen wird oder ob die Konflikte lokal gelöst werden, während die aktuelle #783-Implementierung erhalten bleibt.

---

## Ergebnis

Der Title Language Detection Assistant ist lokal lauffähig, in ERNIE sichtbar und erzeugt Suggestions mit verbesserter Reviewer Preview.

Die Implementierung ist auf dem #783 development branch gepusht und kann von anderen Teammitgliedern per `git pull` übernommen werden.
