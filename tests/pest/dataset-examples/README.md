# Related Works Dataset Examples

Diese Beispieldateien dienen als Vorlagen für den Bulk-Import von Related Works im ERNIE Curation System.

## Dateiformat

### `related-works-example.csv`

Standard-CSV-Datei mit folgenden Spalten:

| Spalte | Beschreibung | Beispiel |
|--------|--------------|----------|
| `identifier` | Der eindeutige Identifier der verwandten Ressource | `10.5194/nhess-15-1463-2015` |
| `identifier_type` | Typ des Identifiers (DOI, URL, Handle, URN, etc.) | `DOI` |
| `relation_type` | DataCite Relation Type (siehe unten) | `Cites` |

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

```csv
identifier,identifier_type,relation_type
10.5194/nhess-15-1463-2015,DOI,Cites
https://example.org/data/seismic-2020,URL,IsDocumentedBy
10273/ICDP5054EHW1001,Handle,Compiles
```

## Hinweise

- Das CSV sollte UTF-8 kodiert sein
- Header-Zeile ist erforderlich
- Leerzeilen werden ignoriert
- Duplikate werden automatisch entfernt
- Bei ungültigen Relation Types erscheint eine Warnung
