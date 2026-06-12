# Task 1 — Auswahllogik für zu prüfende Titel
**Schätzung:** 2 Story Points

## Lieferergebnis
Auswahllogik für Titel mit fehlenden Sprachkennzeichen und explizit unterstützten verdächtigen Konfliktkandidaten.

---

## Fall 1: Fehlende Sprache
- `language = NULL`
- Titel ist nicht leer

## Fall 2: Verdächtiger Sprachkonflikt
- Sprachkennzeichen ist vorhanden
- Erkannte Titelsprache weicht von gespeicherter Sprache ab

**Beispiel:**
| Feld | Wert |
|---|---|
| Titel | Groundwater Recharge in Arid Regions |
| Gespeicherte Sprache | `de` |
| Erkannte Sprache | `en` |

## Fall 3 — Ausschluss formelartiger / symbolhaltiger Titel *(Ergänzung)*
- Titel enthält ausschließlich Zahlen, Codes, Symbole oder formelartige Werte
- Beispiele: `Dataset 2024` / `Version 2.0` / `GFZ-RD-001`
- **Aktion:** Titel wird aus der Prüfung ausgeschlossen — kein Aufruf von Task 2, da keine sinnvolle Spracherkennung möglich ist
- Verhindert doppelte Ausschlusslogik in Task 1 und Task 2

---

## Ausgabe
Liste der Titel, die vom Assistenten geprüft werden sollen (Fälle 1 und 2, nach Ausschluss von Fall 3).
