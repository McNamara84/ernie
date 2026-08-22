# Legacy extreme datasets for Issue #1096

## Scope and method

This list records the highest cardinality found for every repeatable Data Editor form group populated by the SUMARIOPMD Legacy import. The Legacy database contained 2,574 resources when it was checked on 2026-08-22. Counts were first determined from the source tables and then verified against `OldDatasetEditorLoader` for the maximum candidates.

Datacenter names come from the GFZ Data Services portal. The License and Rights maximum is not present in the portal yet, so its value is the datacenter fallback that ERNIE assigns during the pending SUMARIO import.

## Maximum imported values

| Data Editor form group | Imported entries | DOI | Datacenter | Notes |
|---|---:|---|---|---|
| Titles | 4 | `10.5880/fidgeo.2023.017` | FID GEO | Four non-empty Legacy title rows. |
| Licenses and Rights | 6 | `10.5880/GFZ.LKUT.2025.003` | GFZ German Research Centre for Geosciences | Three distinct, mappable license rows plus the same three raw rights statements retained as import evidence. The source-table maximum itself is 3. |
| Authors | 302 | `10.5880/GFZ.1.1.2015.002` | GFZ German Research Centre for Geosciences | Verified against the loader output. |
| Contributors | 666 | `10.5880/GFZ.KTB.top` | GFZ German Research Centre for Geosciences | The source contains 667 distinct non-Creator agents. The loader removes one whose normalized name duplicates an author, leaving 666 imported contributors. |
| Descriptions | 4 | `10.5880/hA-ArboDat_AK291` | ArboDat 2016 | Verified against the loader output. |
| Controlled Vocabularies | 55 | `10.5880/fidgeo.2025.026` | FID GEO | There are 56 supported source associations; one invalid value is skipped by the Legacy transformer, leaving 55 imported entries. |
| Free Keywords | 79 | `10.5880/digis.e.2024.008` | DIGIS Geochemical Data for GEOROC 2.0 | Non-empty comma-separated values after the same trimming/filtering used by the loader. |
| MSL Laboratories | 3 | `10.5880/GFZ.KHAG.2025.001` | GFZ German Research Centre for Geosciences | Lab-ID agents with the Hosting Institution role. |
| Spatial and Temporal Coverage | 1,271 | `10.5880/GFZ.LKUT.2025.002` | GFZ German Research Centre for Geosciences | Demonstrates that a proposed limit of 200 would still truncate 1,071 entries. |
| Dates | 63 | `10.5880/GFZ.4.8.2022.014` | GFZ German Research Centre for Geosciences | 61 `Collected`, one `Created`, and one `Available` entry. Repeated date types are therefore required. |
| Related Work | 3,711 | `10.5880/digis.e.2024.007` | DIGIS Geochemical Data for GEOROC 2.0 | Legacy related identifiers imported into Related Work. |
| Funding References | 13 | `10.5880/fidgeo.d.2025.002` | SPP 2238 - Dynamics of Ore Metals Enrichment - DOME | Verified against the loader output. |

## Groups without a SUMARIOPMD source

The Legacy loader does not populate Related Items or Used Instruments. Their existing DataCite JSON/XML and manual-editor paths were still checked during the implementation, and artificial cardinality limits were removed there where applicable. Datacenter remains intentionally limited to one because it is ERNIE resource ownership rather than repeatable DataCite metadata.

## Important interpretation details

- The values above describe what reaches the Data Editor, not merely raw table row counts.
- No language is inferred for Legacy descriptions because SUMARIOPMD does not store a description-language column.
- Distinct entries are not automatically merged or deduplicated, except for the existing contributor-versus-author duplicate rule described above.
- Portal assignments can change independently of the Legacy database; the names here are the values returned during this audit.
