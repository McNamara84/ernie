# Implementierungsplan für Issues #1191 und #1192

## Ziel

Die Medusa-Datensätze sollen nach einem erneuten Import vollständig verarbeitet und auf ihren Landing Pages korrekt dargestellt werden:

- Die zehn in Issue #1191 genannten Legacy-Klassifikationen werden als gültige kontrollierte Werte übernommen und unverändert angezeigt.
- Die 64 in Issue #1192 genannten Datensätze mit `sediment:other` sollen nicht mehr wegen eines zu langen `user_code` auf DataCite-Minimalmetadaten zurückfallen.
- Bereits importierte Datensätze werden nicht automatisch verändert. Die betroffenen Nutzer löschen und importieren sie nach dem Deployment selbst neu.

## Ergebnis der Ursachenanalyse

### [Issue #1191: Klassifikationen fehlen](https://github.com/McNamara84/ernie/issues/1191)

Das ist ein Importfehler, kein Darstellungsfehler der Landing Page.

`IgsnDifMetadataExtractor` übergibt Klassifikationen an `IgsnVocabularyNormalizerService`. Dessen `partitionClassifications()` akzeptiert nur Werte aus den aktuellen Katalogen in `resources/data/igsn/`. Die zehn Medusa-Legacy-Werte fehlen dort und landen deshalb in der Liste der verworfenen Klassifikationen. Folglich wird keine Klassifikation gespeichert. `AcquisitionSection.tsx` rendert die erhaltenen Daten korrekt, zeigt bei der leeren Liste aber erwartungsgemäß `N/A`.

Betroffene Werte:

| Typ     | Exakter Legacy-Wert         |
| ------- | --------------------------- |
| Rock    | `rock:bedrock igneous`      |
| Rock    | `rock:bedrock metamorphic`  |
| Rock    | `rock:skeleton`             |
| Biology | `vegetation:bark`           |
| Biology | `vegetation:branch`         |
| Biology | `vegetation:leaves/needles` |
| Biology | `vegetation:litter bag`     |
| Biology | `vegetation:stem`           |
| Biology | `vegetation:twig`           |
| Biology | `vegetation:wood`           |

### [Issue #1192: Landing Pages enthalten nur Minimalmetadaten](https://github.com/McNamara84/ernie/issues/1192)

Auch dieses Problem entsteht beim Import und nicht beim Rendern der Landing Page.

Alle 64 betroffenen Medusa-Datensätze enthalten den folgenden, 77 Zeichen langen Wert:

```text
COLD project / Climate Sensitivity of Glacial Landscape Dynamics / ERC-funded
```

Die Datenbankspalte `igsn_metadata.user_code` ist derzeit als `VARCHAR(50)` definiert. Beim Einlesen der DIF-Metadaten löst der Wert daher unter MySQL `SQLSTATE[22001] Data too long for column 'user_code'` aus. Die Transaktion für die DIF-Anreicherung wird vollständig zurückgerollt. Da der DIF-Import als nicht kritisch behandelt wird, bleibt der zuvor aus DataCite angelegte Datensatz bestehen und die Landing Page zeigt nur Titel, IGSN und Ersteller.

Die Materialklassifikation `sediment:other` ist nicht die Fehlerursache; sie ist lediglich das gemeinsame Merkmal der gemeldeten Datensätze.

## Festgelegte fachliche Entscheidungen

1. Alle zehn Werte aus Issue #1191 werden als gültige kontrollierte Klassifikationen aufgenommen. Die gespeicherten Werte bleiben exakt erhalten und erhalten weiterhin den Typ `rock` beziehungsweise `biology`.
2. Alle neuen Einträge werden mit `legacy: true` als Legacy-Werte gekennzeichnet.
3. Die sieben Biology-Einträge erhalten weiterhin eine Definition. Eine BTO-URI wird nur bei einer exakten fachlichen Zuordnung gesetzt:

    | Legacy-Wert                 | BTO-Zuordnung                                                                             |
    | --------------------------- | ----------------------------------------------------------------------------------------- |
    | `vegetation:bark`           | `http://purl.obolibrary.org/obo/BTO_0001301`                                              |
    | `vegetation:branch`         | `http://purl.obolibrary.org/obo/BTO_0001300`                                              |
    | `vegetation:stem`           | `http://purl.obolibrary.org/obo/BTO_0000142`                                              |
    | `vegetation:twig`           | `http://purl.obolibrary.org/obo/BTO_0001411`                                              |
    | `vegetation:wood`           | `http://purl.obolibrary.org/obo/BTO_0005516`                                              |
    | `vegetation:leaves/needles` | `null`, da der zusammengesetzte Legacy-Begriff keine exakte einzelne BTO-Entsprechung hat |
    | `vegetation:litter bag`     | `null`, da der probennah beschriebene Begriff keine exakte BTO-Entsprechung hat           |

4. `igsn_metadata.user_code` wird auf nullable `VARCHAR(255)` erweitert.
5. Es gibt kein automatisches Backfill und keine programmgesteuerte Löschung bestehender Datensätze. Die Nutzer löschen die betroffenen Datensätze nach dem Deployment und importieren sie erneut.

Eine bewusste Folge der ersten Entscheidung ist, dass diese Legacy-Werte künftig nicht nur beim Medusa-Import, sondern überall dort als gültig gelten, wo derselbe kontrollierte Klassifikationskatalog verwendet wird, beispielsweise bei manueller Eingabe oder CSV-Validierung.

## Umsetzung

### 1. Legacy-Klassifikationen in die kontrollierten Vokabulare aufnehmen

Betroffene Dateien:

- `resources/data/igsn/classification-rock.json`
- `resources/data/igsn/classification-biology.json`
- `app/Services/Igsn/IgsnClassificationVocabularyService.php` nur, falls die Validierung der zusätzlichen Metadaten dies erfordert

Vorgehen:

1. Die drei Rock-Werte exakt, einschließlich Präfix und Leerzeichen, ergänzen und jeweils mit `legacy: true` kennzeichnen.
2. Die sieben Biology-Werte exakt ergänzen. Jeder Eintrag enthält `value`, eine aussagekräftige `definition`, `value_uri` gemäß obiger Tabelle und `legacy: true`.
3. Für die fünf exakt zuordenbaren Biology-Werte die vorhandenen beziehungsweise amtlichen BTO-Zuordnungen wiederverwenden. Für die beiden nicht exakt zuordenbaren Begriffe explizit `value_uri: null` speichern, statt eine nur ungefähr passende Ontologie-ID vorzutäuschen.
4. Sicherstellen, dass `IgsnClassificationVocabularyService` sowohl die bestehenden String-Einträge als auch objektförmige Legacy-Einträge zuverlässig auf ihren `value` normalisiert. Der derzeitige Loader unterstützt beide Formen bereits; voraussichtlich ist hier keine Produktivcode-Änderung notwendig.
5. Die Strukturtests so anpassen, dass optionale Legacy-Metadaten geprüft werden können, ohne für ausdrücklich nicht zuordenbare Legacy-Begriffe eine BTO-URI zu erzwingen. Für normale Biology-Einträge bleibt die bestehende URI-Pflicht unverändert.

Erwartete Kataloggrößen nach der Änderung:

- Rock: 79 Werte
- Biology: 31 Werte

### 2. `igsn_metadata.user_code` mit einer additiven Migration erweitern

Betroffene Dateien:

- neue Migration, vorgesehen als `database/migrations/2026_08_27_000003_widen_igsn_metadata_user_code.php`
- neuer Migrationstest unter `tests/pest/Feature/Database/`
- `database/er-diagram.md`
- `database/er-diagram-plantuml.md`

Vorgehen:

1. Eine neue Migration anlegen; die historische Basismigration wird nicht nachträglich verändert.
2. In `up()` die Spalte mit `string('user_code', 255)->nullable()->change()` erweitern.
3. In `down()` vor der Rückkehr auf 50 Zeichen datenbankseitig prüfen, ob Werte mit mehr als 50 Zeichen existieren. In diesem Fall den Rollback mit einer verständlichen `RuntimeException` abbrechen, um stille Datenkürzung zu verhindern. Der Guard folgt dem bereits für `sizes.unit` und `related_items.edition` verwendeten Muster und berücksichtigt SQLite sowie MySQL/MariaDB.
4. Beide ER-Diagramme auf `VARCHAR(255)` aktualisieren.
5. Den neuen Migrationstest in den MySQL-sensitiven Testlauf aufnehmen, weil MySQLs strikte Längenprüfung den ursprünglichen Fehler ausgelöst hat.

### 3. Importpfad durch Regressionstests absichern

Betroffene Tests:

- `tests/pest/Unit/Services/IgsnVocabularyNormalizerTest.php`
- `tests/pest/Unit/Services/IgsnDifMetadataExtractorTest.php`
- `tests/pest/Feature/IgsnImport/IgsnDifXmlParserTest.php`
- neuer Test für die `user_code`-Migration
- Landing-Page-/Transformer-Test nur zur Absicherung des Datenvertrags; eine Frontend-Änderung ist nicht vorgesehen

Testfälle:

1. Alle zehn Legacy-Werte werden vom Normalizer akzeptiert, kanonisch exakt zurückgegeben und dem richtigen Typ zugeordnet.
2. Repräsentative DIF-Daten mit `rock:bedrock igneous` und `vegetation:bark` landen in `classifications` und nicht in `rejected_classifications`.
3. Die Normalisierung bleibt wie bisher unabhängig von der Groß-/Kleinschreibung; der gespeicherte Wert entspricht dem kanonischen Katalogeintrag.
4. Weiterhin unbekannte Klassifikationen werden weiterhin verworfen. Die Änderung darf nicht zu einer generellen Lockerung der kontrollierten Vokabulare führen.
5. Ein DIF-Import mit dem konkreten 77-Zeichen-`user_code` läuft vollständig durch und speichert neben `user_code` auch weitere DIF-Felder. Damit wird nicht nur das isolierte Schreiben der Spalte, sondern der zuvor zurückgerollte Transaktionspfad geprüft.
6. Ein Wert mit exakt 255 Zeichen kann verlustfrei gespeichert werden; `null` bleibt zulässig.
7. Der Migrationstest bestätigt unter MySQL/MariaDB den Typ `VARCHAR(255)` und testet den geschützten Rollback für Werte oberhalb von 50 Zeichen.
8. Der Landing-Page-Datenvertrag liefert die importierten Klassifikationen unverändert aus. `AcquisitionSection.tsx` benötigt keine Sonderbehandlung für Legacy-Werte.
9. `sediment:other` bleibt eine zulässige freie Klassifikation ohne zusätzlichen Klassifikationstyp; die Erweiterung des Rock-/Biology-Katalogs verändert dieses Verhalten nicht.

### 4. Deployment und erneuter Import

Die Reihenfolge ist zwingend:

1. Anwendungscode und Vokabulardateien deployen.
2. Die Migration zur Erweiterung von `igsn_metadata.user_code` erfolgreich ausführen.
3. Erst danach lassen die Nutzer die betroffenen bestehenden Medusa-Datensätze löschen und erneut importieren.
4. Es wird weder im Deployment noch in einer Migration ein Datensatz gelöscht oder automatisch neu importiert.

Der bestehende DataCite-Import überspringt bereits vorhandene IGSNs. Ein erneuter Import ohne vorheriges Löschen würde die unvollständigen Datensätze daher nicht reparieren. Dieser betriebliche Hinweis wird zusätzlich in `docs/local-development.md` beziehungsweise in der für den Produktivimport verwendeten Betriebsdokumentation festgehalten.

## Verifikation

Nach der Implementierung werden mindestens folgende Prüfungen ausgeführt:

```text
npm run test:php -- tests/pest/Unit/Services/IgsnVocabularyNormalizerTest.php
npm run test:php -- tests/pest/Unit/Services/IgsnDifMetadataExtractorTest.php
npm run test:php -- tests/pest/Feature/IgsnImport/IgsnDifXmlParserTest.php
npm run test:php:mysql-sensitive
npm run lint:check
npm run types
npm run test:run
```

Zusätzlich erfolgt nach dem Deployment und dem manuellen Neuimport eine Stichprobe mit mindestens:

- einer Rock-IGSN aus Issue #1191, beispielsweise `GFFB10095`,
- einer Biology-IGSN aus Issue #1191, beispielsweise `GFJUB0012`,
- einer der 64 `sediment:other`-IGSNs aus Issue #1192.

Bei den Issue-#1191-Stichproben muss die jeweilige Klassifikation auf der Landing Page wortgleich erscheinen. Bei der Issue-#1192-Stichprobe müssen der lange `user_code` und die übrigen DIF-Metadaten vorhanden sein; die Seite darf nicht mehr auf Titel, IGSN und Ersteller beschränkt sein.

## Akzeptanzkriterien

- Alle zehn gemeldeten Legacy-Klassifikationen werden bei einem Neuimport gespeichert und auf der Landing Page exakt angezeigt.
- Die Einträge sind als Legacy-Werte nachvollziehbar gekennzeichnet; Biology-Begriffe ohne exakte BTO-Entsprechung tragen keine irreführende URI.
- Der konkrete 77-Zeichen-`user_code` und Werte bis 255 Zeichen werden ohne Kürzung gespeichert.
- Ein Fehler beim Speichern von `user_code` rollt den DIF-Import der gemeldeten Datensätze nicht mehr zurück.
- Bestehende, gültige Vokabulareinträge und die Ablehnung tatsächlich unbekannter Werte bleiben unverändert.
- Es gibt keine Frontend-Sonderlogik für die zehn Werte und keinen automatischen Eingriff in Bestandsdaten.
- Die Reparatur bestehender Datensätze erfolgt ausschließlich durch Löschen und Neuimport durch die Nutzer und erst nach erfolgreicher Migration.

## Nicht Bestandteil der Umsetzung

- Kein automatisches Backfill der Klassifikationen oder des `user_code`.
- Keine automatische Löschung beziehungsweise Neuerstellung der 64 oder weiterer bestehender Datensätze.
- Keine Alias-Zuordnung der Legacy-Werte auf modernere, anders lautende Klassifikationen.
- Keine pauschale Übernahme unbekannter DIF-Klassifikationen.
- Kein Frontend-Workaround, der fehlende Importdaten nur optisch ersetzt.
