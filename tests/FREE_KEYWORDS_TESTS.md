# Free Keywords Test Suite

Diese Testsuite sichert die Free Keywords Funktionalität ab, ohne dass eine Datenbankverbindung erforderlich ist. Alle Tests sind für die CI-Umgebung geeignet.

## Übersicht

Die Free Keywords Feature-Tests sind in verschiedene Kategorien aufgeteilt:

### ✅ Backend Unit-Tests (ohne DB-Verbindung)

#### 1. **Free Keywords Parsing Tests** (`tests/pest/Unit/FreeKeywordsParsingTest.php`)
- **Zweck**: Testet die grundlegende Parsing-Logik für komma-separierte Keywords
- **Umfang**: 7 Tests
- **Test-Fälle**:
  - Parsen von komma-separierten Keywords
  - Trimmen von Whitespace
  - Filtern leerer Keywords
  - Behandlung von einzelnen Keywords
  - Behandlung von null/leeren Strings
  - Beibehaltung von gemischter Groß-/Kleinschreibung

**Ausführen:**
```bash
php artisan test --filter="FreeKeywordsParsingTest"
```

#### 2. **XML Free Keywords Extraction Tests** (`tests/pest/Unit/XmlFreeKeywordsExtractionTest.php`)
- **Zweck**: Testet die Extraktion von Free Keywords aus DataCite XML
- **Umfang**: 10 Tests
- **Test-Fälle**:
  - Extraktion von subjects ohne Schema-Attribute
  - Ausschluss von subjects mit subjectScheme, schemeURI oder valueURI
  - Trimmen von Whitespace
  - Überspringen leerer Elemente
  - Beibehaltung von gemischter Groß-/Kleinschreibung
  - Behandlung von leeren subject-Listen
  - Komplexe gemischte Szenarien

**Ausführen:**
```bash
php artisan test --filter="XmlFreeKeywordsExtractionTest"
```

#### 3. **Old Dataset Free Keywords Parsing Tests** (`tests/pest/Unit/OldDatasetFreeKeywordsParsingTest.php`)
- **Zweck**: Testet die Parsing-Logik für Keywords aus der alten Metaworks-Datenbank
- **Umfang**: 11 Tests
- **Test-Fälle**:
  - Parsen von komma-separierten Keywords aus der alten DB
  - Trimmen von Whitespace
  - Filtern leerer Keywords
  - Behandlung von null/leeren Strings
  - Beibehaltung von gemischter Groß-/Kleinschreibung
  - Sequentielle Array-Schlüssel nach Filterung
  - Behandlung von Sonderzeichen (CO2, μ-CT, β-diversity)
  - Behandlung von Bindestrichen und Unterstrichen
  - Sehr lange Keyword-Listen (50+ Keywords)

**Ausführen:**
```bash
php artisan test --filter="OldDatasetFreeKeywordsParsingTest"
```

### ✅ Frontend Unit-Tests (ohne DB-Verbindung)

#### 4. **Curation Query Building Tests** (`tests/vitest/lib/curation-query.test.ts`)
- **Zweck**: Testet die Konvertierung von Resource-Objekten zu Query-Parametern für das Curation-Formular
- **Umfang**: 4 neue Tests für Free Keywords (zusätzlich zu bestehenden Tests)
- **Test-Fälle**:
  - Inklusion von Free Keywords in Query-Parametern
  - Behandlung leerer Keyword-Arrays
  - Behandlung fehlender freeKeywords-Eigenschaft
  - Beibehaltung der Keyword-Reihenfolge und Inhalte

**Ausführen:**
```bash
npm test -- --run tests/vitest/lib/curation-query.test.ts
```

## Alle Free Keywords Tests ausführen

### Backend (PHP/Pest):
```bash
php artisan test --filter="Free Keywords"
```

### Frontend (TypeScript/Vitest):
```bash
npm test -- --run tests/vitest/lib/curation-query.test.ts
```

## Test-Ergebnisse

**Backend Tests:**
- ✅ 28 Unit-Tests bestanden (ohne DB-Verbindung)
- ✅ Alle Tests laufen in der CI-Umgebung

**Frontend Tests:**
- ✅ 9 Tests bestanden (inkl. 4 neue Free Keywords Tests)
- ✅ Keine DB-Verbindung erforderlich

## Hinweise für CI/CD

Alle Tests in dieser Suite:
- ✅ Benötigen **KEINE** Datenbankverbindung
- ✅ Nutzen Reflection für private Methoden (UploadXmlController)
- ✅ Nutzen Mocking für externe Dependencies
- ✅ Sind vollständig isoliert und unabhängig
- ✅ Laufen schnell (< 2 Sekunden gesamt)

## Test-Abdeckung

Die Tests decken folgende Aspekte der Free Keywords Funktionalität ab:

### Input-Validierung
- ✅ Komma-separierte Strings
- ✅ Whitespace-Behandlung
- ✅ Leere Werte
- ✅ Null/undefined
- ✅ Sonderzeichen
- ✅ Sehr lange Listen

### Datenquellen
- ✅ XML-Upload (DataCite subjects ohne Schema)
- ✅ Alte Datenbank (comma-separated Strings)
- ✅ Manuelle Eingabe (über Curation-Formular)

### Workflow
- ✅ Extraktion aus verschiedenen Quellen
- ✅ Parsing und Normalisierung
- ✅ Query-Parameter-Generierung für Edit-Workflow

## Best Practices

1. **Keine Datenbankverbindung**: Alle Tests verwenden Mocking oder Reflection
2. **Isolation**: Jeder Test ist unabhängig
3. **Klare Benennung**: Test-Namen beschreiben exakt was getestet wird
4. **Edge Cases**: Null, leere Strings, Sonderzeichen werden getestet
5. **Performance**: Schnelle Ausführung für CI-Pipelines

## Wartung

Bei Änderungen an der Free Keywords Logik sollten folgende Tests aktualisiert werden:

- **Parsing-Logik ändern** → `FreeKeywordsParsingTest.php` & `OldDatasetFreeKeywordsParsingTest.php`
- **XML-Extraktion ändern** → `XmlFreeKeywordsExtractionTest.php`
- **Query-Building ändern** → `curation-query.test.ts`

## Bekannte Einschränkungen

- **Feature-Tests mit DB**: Die Tests in `ResourceFreeKeywordsTest.php` benötigen eine Datenbankverbindung und sind NICHT für CI geeignet
- **Frontend-Component-Tests**: Direct Tagify-Integration ist schwer zu testen und wurde ausgelassen
