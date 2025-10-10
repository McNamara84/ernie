# Related Works Dataset Examples

Diese Beispieldateien dienen als Vorlagen für den Bulk-Import von Related Works im ERNIE Curation System.

## Quick Start

1. **Download** die Beispieldatei `related-works-example.csv`
2. **Bearbeite** die CSV mit deinen Daten (Excel, LibreOffice, VS Code)
3. **Importiere** über die Curation-Seite → Related Work → "Import from CSV"

## Dateiformat

### `related-works-example.csv`

Standard-CSV-Datei (UTF-8, Komma-getrennt) mit **3 Pflichtspalten**:

| Spalte | Pflicht | Beschreibung | Beispiel |
|--------|---------|--------------|----------|
| `identifier` | ✓ | Der eindeutige Identifier der verwandten Ressource | `10.5194/nhess-15-1463-2015` |
| `identifier_type` | ✓ | Typ des Identifiers (siehe Liste unten) | `DOI` |
| `relation_type` | ✓ | DataCite Relation Type (siehe Liste unten) | `Cites` |

**Wichtig:**

- Header-Zeile (Spaltenköpfe) ist Pflicht
- Groß-/Kleinschreibung beachten bei `identifier_type` und `relation_type`
- Keine Leerzeichen vor/nach Kommata
- UTF-8 Encoding verwenden

## Unterstützte Identifier Types

- **DOI** (empfohlen, 83% aller Fälle)
- **URL**
- **Handle**
- **IGSN**
- **URN**
- **ISBN**
- **ISSN**
- **PURL**

## Häufigste Relation Types

Basierend auf der Analyse der bestehenden Daten (metaworks):

1. **Cites** (56%) - Dieser Datensatz zitiert eine Publikation
2. **References** (15%) - Dieser Datensatz referenziert eine andere Ressource
3. **IsDerivedFrom** (13%) - Dieser Datensatz ist abgeleitet von einer Originalressource
4. **IsDocumentedBy** (5%) - Dieser Datensatz wird dokumentiert durch
5. **IsSupplementTo** (4%) - Dieser Datensatz ist Supplement zu einer Publikation

## Alle DataCite Relation Types (Schema 4.6)

### Citation

- `Cites` ↔ `IsCitedBy`
- `References` ↔ `IsReferencedBy`

### Documentation

- `Documents` ↔ `IsDocumentedBy`
- `Describes` ↔ `IsDescribedBy`

### Versions

- `IsNewVersionOf` ↔ `IsPreviousVersionOf`
- `HasVersion` ↔ `IsVersionOf`
- `Continues` ↔ `IsContinuedBy`
- `Obsoletes` ↔ `IsObsoletedBy`
- `IsVariantFormOf` ↔ `IsOriginalFormOf`

### Compilation

- `HasPart` ↔ `IsPartOf`
- `Compiles` ↔ `IsCompiledBy`

### Derivation

- `IsSourceOf` ↔ `IsDerivedFrom`

### Supplement

- `IsSupplementTo` ↔ `IsSupplementedBy`

### Software

- `Requires` ↔ `IsRequiredBy`

### Metadata

- `HasMetadata` ↔ `IsMetadataFor`

### Reviews

- `Reviews` ↔ `IsReviewedBy`

### Other

- `IsIdenticalTo`
- `IsPublishedIn`
- `IsCollectedBy` ↔ `Collects`

## Beispiel-Verwendung

### Minimales Beispiel (3 Zeilen)

```csv
identifier,identifier_type,relation_type
10.5194/nhess-15-1463-2015,DOI,Cites
https://example.org/data/seismic-2020,URL,IsDocumentedBy
```

### Vollständiges Beispiel

Siehe `related-works-example.csv` - enthält 15 Beispiel-Einträge mit verschiedenen Identifier- und Relation-Types.

## CSV Import Features

✓ **Drag & Drop** - Ziehe die CSV-Datei direkt ins Import-Fenster
✓ **Validierung** - Format wird automatisch geprüft
✓ **Fehler-Report** - Detaillierte Fehleranalyse mit Zeilen-Angabe
✓ **Preview** - Vorschau der zu importierenden Daten
✓ **Bulk-Import** - Hunderte Einträge in Sekunden

## Hinweise

- Das CSV sollte UTF-8 kodiert sein
- Header-Zeile (identifier,identifier_type,relation_type) ist erforderlich
- Leerzeilen werden ignoriert
- Groß-/Kleinschreibung bei `identifier_type` und `relation_type` beachten
- Bei ungültigen Werten erscheint eine detaillierte Fehlermeldung
- Maximum: keine Begrenzung, aber Performance-Test mit <1000 Einträgen empfohlen

## Häufige Fehler

### ❌ Falsch

```csv
identifier,identifier_type,relation_type
10.1234/example,doi,cites          # Kleinschreibung nicht erlaubt
10.5678/test, DOI ,Cites           # Leerzeichen vor/nach Kommata
https://example.com,URL,CitedBy    # IsCitedBy (nicht CitedBy)
```

### ✓ Richtig

```csv
identifier,identifier_type,relation_type
10.1234/example,DOI,Cites
10.5678/test,DOI,Cites
https://example.com,URL,IsCitedBy
```

## Support

Bei Fragen oder Problemen mit dem CSV-Import:

1. Überprüfe die Beispieldatei `related-works-example.csv`
2. Kontrolliere die Groß-/Kleinschreibung
3. Stelle sicher, dass alle 3 Spalten vorhanden sind
4. Nutze die Validierungs-Fehler-Meldungen im Import-Dialog
