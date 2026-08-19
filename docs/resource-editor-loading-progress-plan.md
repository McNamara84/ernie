# Implementierungsplan: echter Ladefortschritt für bestehende Resources im Data Editor

## Ziel und festgelegter Umfang

Beim Öffnen einer bereits gespeicherten Resource wird im Ziel-Tab sofort eine nicht schließbare Ladeansicht mit einem shadcn-`Dialog` und einem shadcn-`Progress` angezeigt. Das gilt unabhängig davon, ob die Resource über `/resources`, das Dashboard, Assistance oder einen direkten Link `/editor?resourceId=...` geöffnet wird.

Der Fortschritt basiert ausschließlich auf tatsächlich abgeschlossenen server- und clientseitigen Arbeitsschritten. Die Nachrichten wechseln unabhängig davon alle zwei Sekunden. Sobald das Editor-Formular vollständig benutzbar ist, wird es ohne künstliche Mindestwartezeit angezeigt.

Nicht Teil der Änderung sind:

- der leere Editor ohne `resourceId`,
- XML-/JSON-Upload-Sessions,
- der Legacy-Editorpfad mit `oldDatasetId`,
- ein Wechsel vom bestehenden Mehrfach-Tab-Verhalten unter `/resources` zu einer anderen Navigation,
- eine Verlagerung des Ladens in die Queue.

## Akzeptanzkriterien

- Jeder Einstieg zu einer bestehenden Resource zeigt die Ladeansicht im Editor-Tab beziehungsweise im aktuellen Ziel-Tab.
- Bei Mehrfachauswahl unter `/resources` besitzt jeder geöffnete Tab einen eigenen, benutzer- und resourcegebundenen Ladevorgang.
- Der Balken steigt nur, wenn eine definierte Ladephase wirklich abgeschlossen wurde; die Nachrichten steuern den Prozentwert nicht.
- Die folgenden Texte erscheinen in exakt dieser Reihenfolge:

  1. `Preparing the Data Editor for the Data Curators work`
  2. `Load user-specific settings for Data Editor`
  3. `Ask ELMO if Cookie Monster still has any cookies`
  4. `Load unicorns into the DataCite cache`
  5. `Groan under the weight of the huge dataset`
  6. `Who on earth works with such massive datasets?`

- Nachricht 1 gilt für 0–2 Sekunden, Nachricht 2 für 2–4 Sekunden und so weiter. Ab Sekunde 10 bleibt Nachricht 6 stehen, auch über Sekunde 12 hinaus.
- Wird der Editor innerhalb eines Nachrichtenintervalls fertig, wird er sofort angezeigt; die aktuelle Nachricht wird nicht künstlich auf zwei Sekunden verlängert.
- Die Ladeansicht bleibt bis zur serverseitigen Resource-Aufbereitung **und** bis zum Abschluss der bereits vorhandenen clientseitigen Editor-Vokabularabfragen sichtbar.
- Ab einer Gesamtladezeit von 12 Sekunden wird pro Ladeversuch genau ein `warning`-Eintrag erzeugt.
- Die bestehende kanonische URL `/editor?resourceId=...` bleibt erhalten; die Fortschrittskennung erscheint nicht in der URL.
- Fehler bieten einen neuen Versuch sowie eine Rücknavigation an und hinterlassen keinen leeren Tab.
- Direkte neue Editoren, Upload-Sessions und Legacy-Datensätze behalten ihr heutiges Verhalten.

## Zielarchitektur

```mermaid
sequenceDiagram
    participant B as Browser / Editor-Tab
    participant E as EditorController
    participant P as Progress-Tracker + Cache
    participant S as Status-Endpunkt

    B->>E: GET /editor?resourceId=42
    E->>P: Ladeversuch für User + Resource anlegen
    E-->>B: Inertia-Seite editor-loading + zufälliges Token
    B->>E: Inertia-Reload derselben URL mit Token-Header
    par echter Resource-Load
        E->>P: Phase nach jedem abgeschlossenen Arbeitsschritt aktualisieren
        E->>E: Relations laden und Editor-Daten transformieren
    and Statusanzeige
        B->>S: Status regelmäßig mit Token abfragen
        S->>P: gebundenen Status lesen
        S-->>B: Phase + Prozentwert
    end
    E-->>B: Inertia-Seite editor + Resource-Daten + Ladekontext
    B->>B: Editor-Vokabulare laden; Fortschritt pro fertigem Request erhöhen
    B->>B: Modal schließen und DataCiteForm sofort anzeigen
```

Der zweite Request bleibt synchron. Dadurch entstehen weder Queue-Wartezeit noch ein temporär serialisiertes vollständiges Editor-Payload im Cache. Im Cache liegen nur kleine Fortschrittsdaten.

## Umsetzungsschritte

### 1. Benutzergebundenes Fortschrittsmodell einführen

Unter `app/Services/Editor` wird ein eigener Progress-Tracker ergänzt. Er verwaltet je Ladeversuch eine zufällige UUID mit kurzer TTL, beispielsweise 15 Minuten.

Der Cache-Eintrag enthält nur technische Daten:

- Token beziehungsweise tokenbasierter Cache-Key,
- `user_id` und `resource_id`,
- Status wie `pending`, `loading`, `server_ready`, `failed` oder `complete`,
- zuletzt abgeschlossene Phase und Prozentwert,
- Start- und Aktualisierungszeitpunkt,
- optional die Dauer der abgeschlossenen Serverphasen,
- Kennzeichnung, ob der Slow-Load-Warnhinweis bereits geschrieben wurde.

Der Tracker stellt typisierte Operationen zum Starten, Prüfen, Fortschreiben, Fehlschlagen und Abschließen bereit. Tokenzugriffe werden immer gegen den angemeldeten Benutzer und die erwartete Resource geprüft. Ungültige UUIDs, abgelaufene Tokens und fremde Benutzer-/Resource-Kombinationen liefern keine Statusinformationen.

Die 12-Sekunden-Grenze und die Cache-TTL werden an einer zentralen Backend-Stelle definiert. Die Zeitgrenze wird der Lade-Seite als Prop übergeben, damit Frontend und Backend nicht auseinanderlaufen.

### 2. Den bestehenden Resource-Load in echte Phasen teilen

`EditorController::show()` behält die bestehende Priorität für XML, JSON, Legacy-Datensätze und neue Editoren. Nur der Zweig für ein vorhandenes `resourceId` wird zweistufig:

1. Ein Request ohne Progress-Header validiert die Resource leichtgewichtig, legt den Ladeversuch an und rendert `editor-loading`.
2. Die Lade-Seite führt einen Inertia-Reload derselben URL mit einem Header wie `X-Editor-Load-Token` aus. Erst dieser Request lädt die vollständige Resource.

Die heutige große `with([...])`-Abfrage wird in fachlich zusammenhängende `load()`-Phasen aufgeteilt. Nach jeder vollständig abgeschlossenen Phase wird der Cache fortgeschrieben. Vorgesehene Gruppen sind:

- Resource-Grunddatensatz und gemeinsame Editor-Einstellungen,
- Typ, Sprache, Titel, Rechte, Beschreibungen und Datumsangaben,
- Creators, Contributors, polymorphe Personen/Institutionen, Rollen und Affiliations,
- Subjects und räumliche Abdeckung,
- Related Identifiers, Funding References, Instruments, Datacenter und Landing Page,
- Transformation der geladenen Modelle in das Editor-Prop-Format.

Verschachtelte Relations, die der Transformer verwendet, werden explizit eager geladen, insbesondere `descriptions.descriptionType` und `dates.dateType`. So darf die Phasenaufteilung keine N+1-Abfragen hinzufügen und kann bestehende implizite Abfragen reduzieren.

Für sehr große Collections erhält `EditorDataTransformer::transformResource()` optional einen neutralen Fortschritts-Callback oder Reporter. Dieser meldet abgeschlossene Transformationsgruppen, ohne Cache- oder HTTP-Logik in den Transformer einzubauen. Bestehende Aufrufer ohne Reporter behalten unverändert dasselbe Ergebnis.

Die Serverphasen belegen nur den ersten Teil des Balkens, beispielsweise 0–75 %. Die konkreten Gewichte werden zentral und monoton definiert. Sie stellen abgeschlossene Arbeitsblöcke dar und sind ausdrücklich keine zeitbasierte Restzeitprognose.

### 3. Status- und Slow-Load-Endpunkte ergänzen

Im bestehenden authentifizierten Routenblock werden zwei kleine Endpunkte ergänzt:

- `GET` für Status, Phase und Prozentwert eines Tokens,
- `POST` zum Melden, dass die Ladeansicht nach 12 Sekunden noch sichtbar ist.

Ein schlanker `EditorLoadProgressController` validiert UUID, Benutzerbindung und Resourcebindung über den Tracker. Statusantworten erhalten `Cache-Control: no-store`. Falls ein Rate Limit ergänzt wird, muss es pro Benutzer **und Token** gelten, damit die vorhandene Mehrfachauswahl nicht mehrere Tabs gegenseitig drosselt.

Die neuen Laravel-Routen werden über `php artisan ernie:wayfinder-generate --with-form` in die bestehenden Wayfinder-Helfer übernommen; generierte Dateien werden nicht manuell editiert.

### 4. Slow-Load-Logging genau einmal absichern

Der Tracker prüft die Zeitgrenze sowohl bei serverseitigen Phasenwechseln/Abschluss als auch beim expliziten Frontend-Signal nach 12 Sekunden. Ein atomarer Cache-Marker, etwa über `Cache::add`, verhindert doppelte Logzeilen bei konkurrierenden Requests.

Der Warnhinweis verwendet das normale Laravel-Log und enthält:

- eine feste Meldung wie `Slow Data Editor resource load`,
- `user_id`,
- `resource_id`,
- `duration_ms`,
- zuletzt bekannte Phase und Prozentwert,
- Kennzeichnung, ob der Hinweis während Serververarbeitung oder Clientinitialisierung ausgelöst wurde.

Resource-Metadaten, Titel, DOI oder vollständige Payloads werden nicht protokolliert. Normale Ladezeiten erzeugen keinen zusätzlichen Logeintrag. Technische Ladefehler werden weiterhin separat als Fehler gemeldet.

### 5. Wiederverwendbares shadcn-Lademodal bauen

Eine neue Komponente unter `resources/js/components/editor` verwendet die vorhandenen shadcn-Komponenten:

- `Dialog`/`DialogContent` ohne Close-Button,
- `DialogTitle` und `DialogDescription`,
- `Progress`,
- `Button` für den Fehlerzustand.

Der Dialog kann während eines aktiven Loads weder per Escape noch durch Klick auf den Overlay geschlossen werden. Ein Browser-Tab kann weiterhin normal geschlossen werden. Vorgesehener Titel ist `Loading Data Editor`.

Die Nachrichtenlogik wird in einen kleinen Hook ausgelagert:

- Start beim ersten Anzeigen des Modals,
- Wechsel anhand der verstrichenen Zeit in 2.000-ms-Schritten,
- Index bei der sechsten Nachricht begrenzen,
- zeitliche Kontinuität beim Inertia-Wechsel von `editor-loading` zu `editor` über das Token im Tab erhalten,
- Timer beim Erfolg, Fehler oder Unmount vollständig aufräumen.

Der Nachrichtentimer verändert niemals den Progress-Wert. Der Statusbereich verwendet `aria-live="polite"`; der Progress erhält einen zugänglichen Namen und aktuelle Wertattribute. Progress-Transitions respektieren `prefers-reduced-motion`, gegebenenfalls durch eine kleine, allgemeingültige Ergänzung in der vorhandenen shadcn-`Progress`-Komponente.

### 6. Leichtgewichtige Inertia-Lade-Seite ergänzen

`resources/js/pages/editor-loading.tsx` rendert den normalen App-Rahmen und öffnet das Lademodal unmittelbar. Beim Mounten:

- startet sie die zweisekündige Nachrichtenfolge,
- pollt sie den geschützten Status-Endpunkt in einem kurzen, aber moderaten Intervall,
- startet sie genau einmal den Inertia-Reload derselben kanonischen Editor-URL mit dem Token-Header,
- unterdrückt sie den globalen NProgress-Indikator für diesen internen Reload, damit nicht zwei konkurrierende Balken erscheinen,
- ersetzt sie den Loader-History-Eintrag beim Übergang, damit „Zurück“ zum tatsächlichen Ursprung führt.

Kurzzeitige Fehler des Status-Pollings stoppen den eigentlichen Editor-Request nicht. Ein tatsächlicher Backend-Ladefehler wechselt dagegen in einen verständlichen Modal-Fehlerzustand.

„Try again“ startet über einen vollständigen Reload der kanonischen URL einen frischen, unabhängigen Versuch. „Go back“ verwendet die Browser-History und fällt ohne sinnvollen Ursprung auf `/resources` zurück.

### 7. Clientseitige Editor-Initialisierung in den echten Fortschritt aufnehmen

`resources/js/pages/editor.tsx` lädt nach dem Inertia-Wechsel heute noch Resource Types, Title Types, Date Types, Description Types, Licenses, Languages sowie drei Rollensätze. Für bestehende Resources bleibt das gemeinsame Lademodal deshalb geöffnet, bis diese Daten wirklich vorliegen.

Die vorhandene Initialisierung wird so refaktoriert, dass sie nach jedem vollständig empfangenen und geparsten Datensatz einen echten Client-Arbeitsschritt meldet:

- Abschluss von Session-Warmup/Resource Types,
- Abschluss jedes der acht parallel geladenen Vokabular-/Rollen-Endpunkte,
- abschließende React-Bereitschaft des `DataCiteForm`.

Diese Arbeitsschritte füllen den reservierten letzten Bereich des Balkens, beispielsweise 75–100 %. Parallele Antworten erhöhen einen threadsicher über React-State beziehungsweise einen lokalen Zähler verwalteten Completed-Count. Der Balken bleibt innerhalb eines Versuchs monoton.

Sobald `isEditorReady` wahr ist, wird das Modal sofort entfernt und `DataCiteForm` angezeigt. Es gibt keinen zusätzlichen Timeout und kein Warten auf das Ende der aktuellen Nachricht. Für Editoraufrufe ohne Resource-Progress-Kontext bleibt der heutige Skeleton-/Fehlerablauf bestehen.

Scheitert eine notwendige Clientabfrage bei einer bestehenden Resource, zeigt das Modal den gemeinsamen Fehlerzustand. Ein Retry beginnt einen neuen Ladeversuch, sodass Fortschritt, Zeitmessung und Slow-Log eindeutig bleiben.

### 8. Alle Einstiegspunkte ohne URL-Duplikation abdecken

Dashboard, Assistance, `/resources`, Popup-Fallbacks und direkte Links verwenden bereits `/editor?resourceId=...`. Da die Verzweigung im `EditorController` erfolgt, müssen diese Komponenten nicht auf spezielle Loader-URLs umgestellt werden.

Besonders zu prüfen sind:

- Zeilenklick und Tastaturaktivierung unter `/resources`,
- Edit-Button für eine einzelne Auswahl,
- Edit-Button für mehrere Resources mit einem Token pro Tab,
- bestehender Dialog für durch den Browser blockierte Tabs,
- Dashboard-Links zu zuletzt bearbeiteten Resources,
- Resource-Links in Assistance,
- direkt eingegebene oder neu geladene Editor-URLs.

Die bestehende `openDetachedTab()`-Logik und ihre Popup-Erkennung bleiben erhalten. Damit erscheint das Modal im neu geöffneten Tab und nicht im zurückbleibenden `/resources`-Tab.

## Geplante Tests

### Backend/Pest

- Gastzugriff bleibt geschützt.
- `/editor` ohne `resourceId` rendert weiterhin direkt den leeren Editor.
- XML-, JSON- und `oldDatasetId`-Pfade umgehen den neuen Resource-Loader.
- Der erste Request mit `resourceId` rendert `editor-loading` und erzeugt einen gültigen, kurzlebigen Ladeversuch.
- Ein gültiger Token-Header desselben Benutzers und derselben Resource rendert anschließend `editor` mit unverändertem Prop-Payload.
- Fehlende, ungültige, abgelaufene und fremde Tokens werden abgewiesen.
- Ein Token kann nicht für eine andere Resource oder einen anderen Benutzer verwendet werden.
- Statuswerte steigen entsprechend real abgeschlossener Phasen monoton.
- Transformationsresultate und bestehende Landing-Page-, Titeltyp- und MSL-Laboratory-Roundtrips bleiben identisch.
- Verschachtelte Relations sind eager geladen; ein Query-Count-Regressionstest schützt vor neuem N+1-Verhalten.
- Bei 11,999 Sekunden wird kein Warnhinweis geschrieben; ab 12 Sekunden genau einer, auch wenn Server und Frontend fast gleichzeitig melden.
- Der Slow-Log enthält die technischen Kontextfelder, aber keine Resource-Payload.
- Fehler markieren den Ladeversuch und liefern den Loader-Fehlerzustand.

Bestehende Featuretests, die bei einem einzigen `GET /editor?resourceId=...` unmittelbar `component('editor')` erwarten, werden auf einen wiederverwendbaren Zwei-Schritt-Testhelper umgestellt. Das betrifft insbesondere `EditorTest`, `EditorTitleTypeMappingTest` und `MslLaboratoryRoundtripTest`.

### Frontend/Vitest

- Das Modal ist geöffnet, nicht dismissbar und verwendet Dialog/Progress mit zugänglichen Beschriftungen.
- Fake-Timer prüfen alle sechs Texte an den Grenzen 0, 2, 4, 6, 8, 10 und über 12 Sekunden.
- Ein früher erfolgreicher Load blendet das Modal sofort aus.
- Poll-Antworten verändern den Balken; bloßer Zeitablauf verändert ihn nicht.
- Serverseitiger und clientseitiger Fortschritt gehen ohne Rücksprung ineinander über.
- Parallel abgeschlossene Vokabularrequests erhöhen den Completed-Count korrekt und zeigen das Formular erst bei vollständiger Bereitschaft.
- Timer und Polling werden beim Unmount abgeräumt.
- Nach 12 Sekunden wird das Slow-Load-Signal einmalig gesendet.
- Backend- und Clientfehler zeigen Retry/Back; Retry verwendet einen frischen Versuch.
- Reduced Motion deaktiviert unnötige Balkenübergänge.
- Die vorhandenen Tests für Resource-Zeilen, Mehrfachauswahl, blockierte Tabs, Dashboard- und Assistance-Links bestätigen weiterhin die kanonischen Editor-URLs.

### Optionaler Browser-Smoke-Test

Mit einer Test-Resource wird geprüft, dass ein echter Klick aus `/resources` einen neuen Tab öffnet, dort zunächst das Modal zeigt und anschließend das ausgefüllte Editorformular rendert. Der Test darf keine produktive künstliche Verzögerung einführen.

## Voraussichtlich betroffene Dateien

- `app/Http/Controllers/EditorController.php`
- neuer `app/Http/Controllers/EditorLoadProgressController.php`
- neuer Tracker und gegebenenfalls ein Stage-Enum unter `app/Services/Editor` beziehungsweise `app/Enums`
- `app/Services/Editor/EditorDataTransformer.php`
- `routes/web.php`
- neue `resources/js/pages/editor-loading.tsx`
- `resources/js/pages/editor.tsx`
- neue Komponenten/Hooks unter `resources/js/components/editor` und `resources/js/hooks`
- gegebenenfalls `resources/js/components/ui/progress.tsx` ausschließlich für Reduced Motion
- automatisch generierte Wayfinder-Routen
- neue fokussierte Pest- und Vitest-Tests sowie Anpassungen der bestehenden Editor-Featuretests

## Empfohlene Implementierungsreihenfolge

1. Tracker, Sicherheitsbindung, Phasenmodell und Backendtests implementieren.
2. `EditorController` zweistufig machen und den Resource-Load ohne Payloadänderung in Phasen teilen.
3. Status-/Slow-Endpunkte und Wayfinder-Helfer ergänzen.
4. Gemeinsames Modal, Nachrichten-Hook und `editor-loading`-Seite implementieren.
5. Den vorhandenen clientseitigen Editor-Warmup in die restlichen echten Fortschrittsschritte integrieren.
6. Fehler- und Retrypfade vervollständigen.
7. Bestehende Editor-, Resource-, Dashboard- und Assistance-Tests anpassen beziehungsweise erweitern.
8. Typprüfung, Linting, Backendanalyse, fokussierte Tests und abschließend die vollständigen relevanten Test-Suites ausführen.

## Verifikation vor Abschluss

- `npm run types`
- `npm run lint:check`
- fokussierte Vitest-Suite für Loader, Editor und bestehende Resource-Einstiege
- fokussierte Pest-Suite für Editor-Loading, Transformer und Roundtrips
- `npm run phpstan:check`
- `npm run test:run`
- `npm run test:php`
- optional `npm run test:e2e` beziehungsweise der passende Devstack-Smoke-Test

Vor dem Merge wird zusätzlich mit einer kleinen und einer außergewöhnlich großen Resource manuell geprüft, dass die Prozentwerte nur an echten Phasengrenzen steigen, die letzte Nachricht nach 12 Sekunden stehen bleibt und der Warnlog genau einmal geschrieben wird.
