# Task 2 — Titel-Spracherkennung und Unterdrückung
**Schätzung:** 2 Story Points

## Lieferergebnis
Assistentenlogik, die Titel-Sprache-Vorschläge pro Titeldatensatz bewertet und doppelte oder abgelehnte Titel-Sprache-Paare unterdrückt.

---

## Eingabe
- Titeltext
- Gespeicherter Sprachwert *(optional)*

---

## Spracherkennung
- Sprache aus dem Titeltext erkennen
- Konfidenzwert berechnen

---

## Vorschlagserzeugung
Vorschlag wird erzeugt, wenn:
- Sprache fehlt
- Erkannte Sprache weicht von gespeicherter Sprache ab
- Optional: Fälle mit geringer Konfidenz zur Prüfung markieren

### Vorschlag enthält
| Feld | Beschreibung |
|---|---|
| Titeltext | Der geprüfte Titel |
| Gespeicherte Sprache | Bisheriger Sprachwert (falls vorhanden) |
| Vorgeschlagene Sprache | Vom Assistenten erkannte Sprache |
| Konfidenzwert | Numerischer Wert der Erkennungssicherheit |
| Begründungszusammenfassung | Warum der Vorschlag erzeugt wurde |

### Begründungszusammenfassung enthält
- Erkannte Sprache
- Erkennungskonfidenz
- Gespeicherte Sprache (falls vorhanden)
- Grund: fehlende Sprache oder Sprachkonflikt

**Beispiel:**
```
Erkannte Sprache:     en
Konfidenz:            0.95
Gespeicherte Sprache: de
Grund:                Sprachkonflikt
```

---

## Konfidenzschwellenwerte *(Ergänzung)*
| Bereich | Verhalten |
|---|---|
| < 0.5 | Mehrdeutig / überspringen — kein Vorschlag |
| 0.5 – 0.75 | Geringe Konfidenz — zur Prüfung markieren |
| > 0.75 | Vorschlag erzeugen |

> Schwellenwerte sind vor Sprint-Start mit dem Team abzustimmen und hängen von der gewählten Erkennungsbibliothek ab.

---

## Kurze Titel *(Ergänzung)*
- **Regel:** Wortanzahl < 3 → als mehrdeutig markieren, kein Vorschlag
- Betroffene Beispiele: `Atlas`, `Data`, `Report`

---

## Unterdrückung

### Duplikat-Unterdrückung
- Keine doppelten Vorschläge für dasselbe Titel-Sprache-Paar erzeugen

### Unterdrückung abgelehnter Vorschläge
- Vorschläge, die für dasselbe Titel-Sprache-Paar bereits abgelehnt wurden, nicht neu erstellen

---

## Sonderfälle

### Geliehene englische Begriffe
- Sorgfältig behandeln, um unnötige Konflikterkennung zu vermeiden
- Beispiele: `Open Data Portal`, `Climate Change`
- Solche Begriffe tauchen oft in deutschen Datensätzen auf — der Assistent darf daraus nicht automatisch auf Englisch schließen

### Formelartige Werte, Symbole und Codes
- Kein Vorschlag, wenn die Spracherkennung unzuverlässig ist
- Beispiele: `Dataset 2024` / `Version 2.0` / `GFZ-RD-001`
- Idealerweise bereits durch Task 1 / Fall 3 herausgefiltert

---

## Umfang
- Nur Vorschläge erzeugen
- Keine automatische Metadatenkorrektur
- Die Entscheidung liegt immer beim Kurator


