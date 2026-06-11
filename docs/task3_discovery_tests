# Task 3 — Gezielte Erkennungstests
**Schätzung:** 1 Story Point

## Lieferergebnis
Automatisierte Tests für kurze Titel, geliehene englische Begriffe, formelartige Werte und gültige mehrsprachige Titelmengen.

Prüft, ob die Erkennungslogik auch in schwierigen Fällen korrekt reagiert.

---

## Testklasse 1 — Kurze Titel
**Beispiele:** `Atlas` / `Data` / `Report`

**Erwartetes Ergebnis:**
- Kein Sprachvorschlag erzeugt
- Als mehrdeutig markiert

---

## Testklasse 2 — Geliehene englische Begriffe
**Beispiele:** `Climate Change` / `Open Data Portal`

**Erwartetes Ergebnis:**
- Korrekte Spracherkennung (Titel in deutschem Datensatz korrekt als nicht-konfliktreich behandelt)
- Kein falscher Konflikt erzeugt

---

## Testklasse 3 — Formelartige Werte
**Beispiele:** `Dataset 2024` / `Version 2.0` / `Report No. 15`

**Erwartetes Ergebnis:**
- Kein Sprachvorschlag erzeugt
- Geringe Konfidenz
- Als mehrdeutig markiert

---

## Testklasse 4 — Gültige mehrsprachige Titelmengen
**Beispiel:**
| Titeltyp | Wert |
|---|---|
| Haupttitel | Groundwater Recharge |
| Alternativer Titel | Grundwasserneubildung |

**Erwartetes Ergebnis:**
- Haupttitel = `en`
- Alternativer Titel = `de`
- Kein Konflikt erzeugt
- Jeder Titel wird unabhängig bewertet

---

## Testklasse 5 — Duplikat-Unterdrückung *(Ergänzung)*
**Szenario:** Gleicher Titel wird zweimal als Eingabe übergeben.

**Erwartetes Ergebnis:**
- Nur 1 Vorschlag wird erzeugt
- Kein Duplikat in der Ausgabeliste

---

## Testklasse 6 — Unterdrückung abgelehnter Vorschläge *(Ergänzung)*
**Szenario:** Vorschlag wird erzeugt → Kurator lehnt ab → erneuter Lauf mit demselben Titel.

**Erwartetes Ergebnis:**
- Kein neuer Vorschlag für dieselbe Titel-Sprache-Kombination
- Abgelehnte Vorschläge bleiben unterdrückt

---

## Offene Fragen *(vor Sprint-Start zu klären)*

1. **Welche Spracherkennungsbibliothek soll verwendet werden?**
   Kandidaten: `langdetect`, `lingua`, `cld3`
   Relevant für: Konfidenzverhalten bei kurzen Titeln, Umgang mit geliehenen englischen Begriffen.

2. **Welcher Konfidenzschwellenwert gilt für "geringe Konfidenz"?**
   Empfehlung: Drei-Stufen-Modell (siehe Task 2).
   Ohne festgelegten Schwellenwert können die erwarteten Werte in Task 3 nicht präzise definiert werden.
