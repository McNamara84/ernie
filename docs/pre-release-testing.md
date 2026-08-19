# Pre-Release-Testing von ERNIE / Pre-Release Testing of ERNIE

Diese Anleitung enthält den Smoke-Test nach einem Stage-Deployment und den
vollständigen Release-Regressionstest vor einem Produktiv-Deployment. Für die
gezielte Prüfung eines gemergten Pull Requests gilt stattdessen
[`post-merge-testing.md`](post-merge-testing.md).

_This guide contains the smoke test after a stage deployment and the full
release regression test before a production deployment. For targeted testing
of a merged pull request, use
[`post-merge-testing.md`](post-merge-testing.md) instead._

> **Wichtig / Important:** Vor einem Testlauf eine lokale Kopie dieser Datei
> anlegen und nur die Kopie ausfüllen, zum Beispiel
> `pre-release-testing-2026-07-17-release-1.2.0.md`. Ausgefüllte Testläufe
> werden nicht in diesem Repository gepflegt.
>
> _Before starting a test run, create a local copy of this file and fill in only
> that copy, for example
> `pre-release-testing-2026-07-17-release-1.2.0.md`. Completed test runs are not
> maintained in this repository._

## Ablauf / Process

1. Grundlegende Informationen lesen und Testlauf vorbereiten.  
   _Read the general information and prepare the test run._
2. Nach jedem Stage-Deployment den Smoke-Test in Abschnitt 2 ausführen.  
   _After every stage deployment, run the smoke test in section 2._
3. Vor einem Produktiv-Deployment zusätzlich die vollständige
   Release-Regression in Abschnitt 3 ausführen.  
   _Before a production deployment, also run the full release regression in
   section 3._
4. Die Testerin spricht eine Empfehlung aus; die finale Go-/No-Go-Entscheidung
   trifft Product Ownerin Tanja
   ([anti@gfz.de](mailto:anti@gfz.de)).  
   _The tester makes a recommendation; the final go/no-go decision is made by
   Product Owner Tanja ([anti@gfz.de](mailto:anti@gfz.de))._

---

## 1. Grundlegende Informationen zum manuellen Testing von ERNIE / General Information About Manual Testing of ERNIE

### 1.1 Ziel und Geltungsbereich / Purpose and Scope

Manuelles Testing ergänzt die automatisierten Tests. Es prüft aus Sicht einer
Benutzerin, ob die auf Stage bereitgestellte Version verständlich bedienbar ist,
kritische Arbeitsabläufe funktionieren und keine sichtbaren Regressionen
aufgetreten sind.

_Manual testing complements the automated tests. It verifies from a user's
perspective that the version deployed to stage is usable, that critical
workflows function, and that no visible regressions have been introduced._

Diese Anleitung deckt folgende Perspektiven ab:

_This guide covers the following perspectives:_

- nicht angemeldete Benutzerinnen und Benutzer / unauthenticated users
- Curator / curator
- Admin / administrator
- öffentliche Landingpages und Portal / public landing pages and portal

Die Rollen **Group Leader** und **Beginner** sind nicht Bestandteil der
regelmäßigen manuellen Regression. Ihre spezifischen Rechte und Einschränkungen
werden damit manuell nicht vollständig abgedeckt.

_The **Group Leader** and **Beginner** roles are not part of the regular manual
regression. Their role-specific permissions and restrictions are therefore not
fully covered by manual testing._

### 1.2 Testarten / Test Types

| Testart / Test type                               | Zweck / Purpose                                                                                                                                 | Zeitpunkt / When                                               | Richtwert / Target                       |
| ------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- | ---------------------------------------- |
| Smoke-Test / Smoke test                           | Kritische Grundfunktionen und erfolgreiches Deployment bestätigen / Confirm critical basic functions and a successful deployment                | Nach jedem Stage-Deployment / After every stage deployment     | 10–15 Minuten / minutes                  |
| Release-Regressionstest / Release regression test | Alle sichtbaren und aktivierten Bereiche mit repräsentativen Abläufen prüfen / Test all visible and enabled areas with representative workflows | Vor jedem Prod-Deployment / Before every production deployment | ungefähr 3–4 Stunden / approx. 3–4 hours |

### 1.3 Verantwortlichkeiten und Freigabe / Responsibilities and Approval

- Die Testerin führt den vereinbarten Test aus, dokumentiert Abweichungen und
  spricht eine Freigabeempfehlung aus.

    _The tester performs the agreed test, documents deviations, and makes a
    release recommendation._

- Die finale Go-/No-Go-Entscheidung trifft Product Ownerin Tanja
  ([anti@gfz.de](mailto:anti@gfz.de)).

    _The final go/no-go decision is made by Product Owner Tanja
    ([anti@gfz.de](mailto:anti@gfz.de))._

- Entwicklerinnen und Entwickler beantworten fachliche oder technische
  Rückfragen und analysieren gemeldete Fehler.

    _Developers answer functional or technical questions and analyse reported
    defects._

### 1.4 Stage, Konten und Zugangsdaten / Stage, Accounts, and Credentials

- Ausschließlich auf <https://ernie.rz-vm182.gfz.de/> testen.

    _Test only at <https://ernie.rz-vm182.gfz.de/>._

- Für die Tests werden dedizierte Admin- und Curator-Testkonten verwendet.

    _Dedicated administrator and curator test accounts are used._

- Zugangsdaten niemals in diese Datei, Screenshots, Backlog Items oder
  Chat-Nachrichten kopieren.

    _Never copy credentials into this file, screenshots, backlog items, or chat
    messages._

- Vor dem Test Rolle und Umgebung prüfen. Bei unerwarteten echten
  Produktionsdaten oder einer falschen Domain den Test sofort abbrechen.

    _Verify the role and environment before testing. Stop immediately if
    unexpected real production data or the wrong domain is shown._

### 1.5 Testdaten und erlaubte Aktionen / Test Data and Permitted Actions

Stage darf für vollständige Tests verändert werden. Es gelten dabei folgende
Regeln:

_Stage may be modified for complete testing. The following rules apply:_

- Nur synthetische Daten verwenden. Keine personenbezogenen, vertraulichen oder
  produktiven Forschungsdaten hochladen.

    _Use synthetic data only. Do not upload personal, confidential, or production
    research data._

- Jeden erzeugten Datensatz, Benutzer und jede Konfiguration eindeutig mit
  `MANUAL-TEST-<Datum>-<Kürzel>` kennzeichnen, zum Beispiel
  `MANUAL-TEST-20260717-AB`.

    _Clearly label every created resource, user, and configuration with
    `MANUAL-TEST-<date>-<initials>`, for example
    `MANUAL-TEST-20260717-AB`._

- Erstellte lokale Stage-Daten nach dem Test wieder löschen oder auf den
  Ausgangszustand zurücksetzen. Extern erzeugte Test-DOIs und Test-IGSNs im
  Testprotokoll festhalten, auch wenn sie nicht entfernt werden können.

    _Delete locally created stage data after the test or restore the initial
    state. Record externally created test DOIs and test IGSNs in the test log,
    even if they cannot be removed._

- Benutzerverwaltung, E-Mail-Versand, Importe, Exporte, Datenbank-Dumps,
  Thesaurus-Aktualisierungen und andere auf Stage sichtbare Aktionen dürfen mit
  Testdaten ausgeführt werden.

    _User administration, email delivery, imports, exports, database dumps,
    thesaurus updates, and other actions visible on stage may be performed using
    test data._

- **Logs dürfen weder einzeln noch vollständig gelöscht werden.** Sie werden
  von den Entwicklerinnen und Entwicklern für die Fehleranalyse benötigt.

    _**Logs must not be deleted, either individually or in bulk.** Developers
    require them for troubleshooting._

### 1.6 DataCite-Testmodus / DataCite Test Mode

Auf Stage wird `DATACITE_TEST_MODE=true` erwartet. DOI- und IGSN-Registrierungen
dürfen deshalb vollständig getestet werden, müssen aber eindeutig als
Testregistrierung erkennbar sein.

_Stage is expected to use `DATACITE_TEST_MODE=true`. DOI and IGSN registrations
may therefore be tested completely, but they must be clearly identifiable as
test registrations._

Vor jeder Registrierung:

_Before every registration:_

- [ ] Im Dialog wird ein Hinweis auf den DataCite-Testmodus angezeigt.

    _The dialog displays a DataCite test mode notice._

- [ ] DOI beziehungsweise IGSN und Titel verwenden die aktuelle
      `MANUAL-TEST`-Kennung.

    _The DOI or IGSN and title use the current `MANUAL-TEST` identifier._

- [ ] Wenn der Testmodus-Hinweis fehlt oder eine Produktivregistrierung
      vermutet wird: nicht bestätigen, Screenshot erstellen und den Test
      blockieren.

    _If the test mode notice is missing or production registration is suspected:
    do not confirm, take a screenshot, and block the test._

### 1.7 Browser- und Geräteabdeckung / Browser and Device Coverage

| Umfang / Scope                          | Browser und Ansicht / Browser and viewport                                                                                                                                               |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Jeder Smoke-Test / Every smoke test     | aktuelles Chrome, Desktop / current Chrome, desktop                                                                                                                                      |
| Release-Regression / Release regression | vollständiger Lauf in aktuellem Chrome; kurzer Gegencheck in aktuellem Firefox; mobiler Smoke-Test / full run in current Chrome; short cross-check in current Firefox; mobile smoke test |

Browser-Erweiterungen, Übersetzungsfunktionen und Ad-Blocker möglichst
deaktivieren. Bei browserabhängigen Fehlern zusätzlich ein privates Fenster
ohne Erweiterungen verwenden.

_Disable browser extensions, translation features, and ad blockers where
possible. For browser-specific issues, also test in a private window without
extensions._

### 1.8 Status und Nachweise / Status and Evidence

Eine Checkbox wird erst markiert, wenn der Schritt ausgeführt wurde und das
beschriebene Ergebnis eingetreten ist.

_Mark a checkbox only after the step has been performed and the described
result has occurred._

Für Abweichungen unmittelbar unter dem betroffenen Schritt notieren:

_For deviations, add the following directly below the affected step:_

```text
Status / Status: FAIL | BLOCKED | N/A
Backlog Item:
Ist-Ergebnis / Actual result:
Nachweis / Evidence:
```

Statusdefinitionen:

_Status definitions:_

- **PASS:** Ausgeführt, erwartetes Ergebnis erreicht. / _Executed, expected
  result achieved._
- **FAIL:** Ausgeführt, erwartetes Ergebnis nicht erreicht. / _Executed,
  expected result not achieved._
- **BLOCKED:** Nicht ausführbar, weil eine Voraussetzung fehlt oder ein anderer
  Fehler den Weg blockiert. / _Cannot be executed because a prerequisite is
  missing or another defect blocks the workflow._
- **N/A:** Auf diesem Deployment bewusst nicht anwendbar; Begründung ist
  Pflicht. / _Intentionally not applicable to this deployment; a reason is
  required._

### 1.9 Fehler melden / Reporting Defects

Für jedes einzelne Problem wird ein eigenes Backlog Item gemäß dem internen
Cheat Sheet **„Creating Backlog Items“** erstellt. Der Titel sollte das
betroffene Modul und das beobachtete Problem knapp benennen.

_Create one separate backlog item for each problem according to the internal
cheat sheet **“Creating Backlog Items”**. The title should briefly identify the
affected module and observed problem._

Mindestens dokumentieren:

_Document at least:_

1. Umgebung, Deploy-/Versionsangabe, Datum und Uhrzeit  
   _Environment, deployment/version, date, and time_
2. verwendete Rolle und Testdatenkennung  
   _Role used and test data identifier_
3. Browser, Browserversion und Betriebssystem  
   _Browser, browser version, and operating system_
4. eindeutige Schritte zum Reproduzieren  
   _Unambiguous steps to reproduce_
5. erwartetes und tatsächliches Ergebnis  
   _Expected and actual result_
6. Häufigkeit: immer, häufig, sporadisch oder einmalig  
   _Frequency: always, often, intermittent, or once_
7. Screenshot oder kurzes Video, ohne Zugangsdaten oder vertrauliche Daten  
   _Screenshot or short video without credentials or confidential data_
8. sichtbare Fehlermeldung; Logs nur verlinken oder mit den Entwicklern
   abstimmen, niemals löschen  
   _Visible error message; only reference logs or coordinate with developers,
   never delete them_

### 1.10 Schweregrade und Abbruchkriterien / Severity and Stop Criteria

| Schweregrad / Severity | Bedeutung / Meaning                                                                                                                                                                          | Reaktion / Response                                                                                         |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| Blocker                | Login, Seite oder zentraler Ablauf ist nicht nutzbar; Datenverlust oder Produktivwirkung möglich / Login, page, or critical workflow is unusable; data loss or production impact is possible | Testlauf stoppen, Entwicklung sofort informieren / Stop the run and notify development immediately          |
| Kritisch / Critical    | Kernfunktion liefert ein falsches Ergebnis, Registrierung oder Speichern schlägt fehl / A core function produces an incorrect result, or registration or saving fails                        | Betroffenen Bereich stoppen; Release nicht empfehlen / Stop the affected area; do not recommend the release |
| Normal / Major         | Funktion ist fehlerhaft, aber ein vertretbarer Umweg existiert / Function is defective, but a reasonable workaround exists                                                                   | Backlog Item erstellen und Release-Risiko dokumentieren / Create a backlog item and document release risk   |
| Gering / Minor         | Darstellung, Text oder Komfortproblem ohne Datenrisiko / Visual, copy, or usability issue without data risk                                                                                  | Backlog Item erstellen; Test fortsetzen / Create a backlog item; continue testing                           |

Sofort abbrechen bei:

_Stop immediately if:_

- falscher Domain oder vermuteter Prod-Umgebung,
- fehlendem DataCite-Testmodus vor einer Registrierung,
- unbeabsichtigtem Zugriff auf echte oder vertrauliche Daten,
- Datenverlust außerhalb der eigenen `MANUAL-TEST`-Daten,
- wiederholten Serverfehlern, die weitere Ergebnisse unzuverlässig machen.

_the wrong domain or suspected production environment is shown; DataCite test
mode is missing before registration; real or confidential data is accessed
unintentionally; data outside the tester's own `MANUAL-TEST` data is lost; or
repeated server errors make further results unreliable._

---

## 2. Smoke-Test (ca. 10–15 Minuten, nach jedem Deploy) / Smoke Test (Approx. 10–15 Minutes, After Every Deployment)

### 2.1 Testlaufkopf / Test Run Header

| Feld / Field                                       | Eintrag / Entry |
| -------------------------------------------------- | --------------- |
| Anlass / Reason                                    |                 |
| PR, Commit oder Version / PR, commit, or version   |                 |
| Deploy abgeschlossen um / Deployment completed at  |                 |
| Testdatum und Startzeit / Test date and start time |                 |
| Testerin / Tester                                  |                 |
| Browser und Version / Browser and version          |                 |
| Betriebssystem / Operating system                  |                 |
| Testdatenkennung / Test data identifier            | `MANUAL-TEST-`  |

### 2.2 Vorbereitung / Preparation

- [ ] Stage-URL, Deployment und gewünschte Version wurden von der Entwicklung
      bestätigt.

    _The stage URL, deployment, and intended version have been confirmed by
    development._

- [ ] Aktuelles Chrome-Desktopfenster geöffnet; alte ERNIE-Tabs geschlossen.

    _A current Chrome desktop window is open; old ERNIE tabs are closed._

- [ ] Curator-Testkonto und synthetische Testdaten sind verfügbar.

    _The curator test account and synthetic test data are available._

### 2.3 Öffentliche Erreichbarkeit / Public Availability

- [ ] <https://ernie.rz-vm182.gfz.de/> öffnet ohne Zertifikats-, Gateway-,
      Server- oder leere Seitenfehler.

    _<https://ernie.rz-vm182.gfz.de/> opens without certificate, gateway, server,
    or blank-page errors._

- [ ] Die Loginseite ist vollständig dargestellt; Logo, E-Mail, Passwort und
      `Log in` sind sichtbar.

    _The login page renders completely; logo, email, password, and `Log in` are
    visible._

- [ ] `/portal` öffnet öffentlich, zeigt Ergebnisse oder einen plausiblen
      Leerzustand und reagiert auf eine Suche.

    _`/portal` opens publicly, shows results or a plausible empty state, and
    responds to a search._

### 2.4 Login und Navigation / Login and Navigation

- [ ] Login mit dem Curator-Testkonto führt zum `Dashboard`; es erscheint keine
      unerwartete Fehlermeldung.

    _Logging in with the curator test account leads to the `Dashboard`; no
    unexpected error appears._

- [ ] Dashboard-Kennzahlen und die letzten Ressourcen werden ohne dauerhaften
      Ladezustand dargestellt.

    _Dashboard metrics and recent resources render without a persistent loading
    state._

- [ ] Die Navigation öffnet `Data Editor`, `Resources`, `IGSNs List`,
      `IGSNs Map`, `Documentation` und `Changelog`.

    _The navigation opens `Data Editor`, `Resources`, `IGSNs List`, `IGSNs Map`,
    `Documentation`, and `Changelog`._

- [ ] `IGSN Editor` ist weiterhin als deaktiviert erkennbar, solange diese
      Funktion nicht freigegeben wurde.

    _`IGSN Editor` remains visibly disabled while that function has not been
    released._

### 2.5 Kritischer Curator-Ablauf / Critical Curator Workflow

- [ ] Auf dem Dashboard wird eine gültige synthetische XML-Datei akzeptiert und
      ein erfolgreicher Upload bestätigt.

    _A valid synthetic XML file is accepted on the dashboard and a successful
    upload is confirmed._

- [ ] `Open in editor` öffnet den `Data Editor`; mindestens DOI/Identifier,
      Titel, Publikationsjahr, Ressourcentyp und Autor sind plausibel vorbelegt.

    _`Open in editor` opens the `Data Editor`; at least DOI/identifier, title,
    publication year, resource type, and creator are populated plausibly._

- [ ] Der Titel wurde um die aktuelle `MANUAL-TEST`-Kennung ergänzt und
      `Save to database` speichert ohne Server- oder Validierungsfehler.

    _The current `MANUAL-TEST` identifier was added to the title and `Save to
database` saves without a server or validation error._

- [ ] Die gespeicherte Ressource erscheint in `Resources` und kann über Suche
      oder Filter gefunden werden.

    _The saved resource appears in `Resources` and can be found using search or
    filters._

- [ ] Die Ressource lässt sich wieder im Editor öffnen, eine kleine Änderung
      speichern und anschließend mit der Änderung in `Resources` finden.

    _The resource can be reopened in the editor, a small change can be saved, and
    the updated resource can then be found in `Resources`._

- [ ] Die Landingpage-Konfiguration der Testressource lässt sich öffnen und
      eine Vorschau zeigt mindestens Titel, Identifier, Autor und Lizenz.

    _The landing page configuration for the test resource opens and a preview
    shows at least title, identifier, creator, and licence._

### 2.6 Abschluss / Completion

- [ ] Logout führt zurück zur Loginseite; eine geschützte Seite ist danach
      nicht ohne erneuten Login erreichbar.

    _Logout returns to the login page; a protected page cannot be accessed
    afterwards without logging in again._

- [ ] Die im Smoke-Test angelegte Ressource und Landingpage-Konfiguration
      wurden gelöscht oder für die anschließende Regression eindeutig notiert.

    _The resource and landing page configuration created during the smoke test
    have been deleted or clearly recorded for the subsequent regression._

| Abschluss / Completion | Eintrag / Entry       |
| ---------------------- | --------------------- |
| Endzeit / End time     |                       |
| Ergebnis / Result      | PASS / FAIL / BLOCKED |
| Backlog Items          |                       |
| Bemerkungen / Notes    |                       |

**Freigaberegel:** Ein Smoke-Test ist nur bei vollständig bestandenem
kritischem Ablauf erfolgreich. Bei `FAIL` oder `BLOCKED` keine weitere
Freigabeempfehlung aussprechen, bevor die Ursache bewertet wurde.

_**Approval rule:** A smoke test succeeds only if the complete critical workflow
passes. For `FAIL` or `BLOCKED`, do not make any further approval recommendation
until the cause has been assessed._

---

## 3. Release-Regressionstest (vor dem Produktiv-Deployment) / Release Regression Test (Before Production Deployment)

Der Release-Regressionstest wird erst nach einem bestandenen Smoke-Test
begonnen. Alle auf Stage sichtbaren und aktivierten Module werden geprüft.
Nicht verfügbare oder bewusst deaktivierte Funktionen erhalten `N/A` mit
Begründung.

_Start the release regression test only after a successful smoke test. Test all
modules that are visible and enabled on stage. Mark unavailable or intentionally
disabled functions as `N/A` and provide a reason._

### 3.1 Testlaufkopf und Eintrittskriterien / Test Run Header and Entry Criteria

| Feld / Field                                | Eintrag / Entry                               |
| ------------------------------------------- | --------------------------------------------- |
| Release/Version                             |                                               |
| Commit oder Tag / Commit or tag             |                                               |
| enthaltene PRs / Included PRs               |                                               |
| Deploy-Zeitpunkt / Deployment time          |                                               |
| Testdatum / Test date                       |                                               |
| Testerin / Tester                           |                                               |
| Chrome-Version / Chrome version             |                                               |
| Firefox-Version / Firefox version           |                                               |
| Betriebssystem / Operating system           |                                               |
| Testdatenkennung / Test data identifier     | `MANUAL-TEST-`                                |
| vorheriger Smoke-Test / Previous smoke test | PASS / Link oder Dateiname / Link or filename |

- [ ] Entwicklung hat bestätigt, dass der Release Candidate vollständig auf
      Stage deployed ist.

    _Development has confirmed that the complete release candidate is deployed
    to stage._

- [ ] Bekannte Einschränkungen, geänderte Features und Akzeptanzkriterien
      wurden übergeben.

    _Known limitations, changed features, and acceptance criteria have been
    provided._

- [ ] Admin- und Curator-Testkonto funktionieren; Group Leader und Beginner
      sind als nicht manuell abgedeckt vermerkt.

    _The administrator and curator test accounts work; Group Leader and Beginner
    are recorded as not manually covered._

- [ ] Die Testdatenkennung ist eindeutig und wurde im Testlaufkopf eingetragen.

    _The test data identifier is unique and has been entered in the test run
    header._

### 3.2 Öffentliche Seiten / Public Pages

- [ ] Start-/Loginseite, `About`, `Legal Notice` und `Changelog` öffnen direkt
      per URL ohne Fehler.

    _The home/login page, `About`, `Legal Notice`, and `Changelog` open directly
    by URL without errors._

- [ ] Links im Header und Footer führen zum erwarteten Ziel; externe Links
      öffnen sicher und ohne den ERNIE-Zustand zu verlieren.

    _Header and footer links lead to the expected destinations; external links
    open safely without losing the ERNIE state._

- [ ] Das `Changelog` zeigt die aktuelle Version, sinnvolle Kategorien und
      ein-/ausklappbare Einträge.

    _The `Changelog` shows the current version, meaningful categories, and
    expandable/collapsible entries._

- [ ] Timeline-/Sprungnavigation des Changelogs funktioniert mit Maus und
      Tastatur.

    _The changelog timeline/jump navigation works with mouse and keyboard._

- [ ] Eine bekannte öffentliche Landingpage öffnet direkt und zeigt keine
      Authentifizierungsaufforderung.

    _A known public landing page opens directly and does not request
    authentication._

### 3.3 Anmeldung, Sitzung und Zugriffsschutz / Authentication, Session, and Access Control

Mit beiden Testrollen ausführen, soweit anwendbar.

_Run with both test roles where applicable._

- [ ] Falsches Passwort zeigt eine verständliche Fehlermeldung und meldet die
      Benutzerin nicht an.

    _An incorrect password displays an understandable error and does not log the
    user in._

- [ ] Gültige Anmeldung führt zum Dashboard und bleibt bei Navigation sowie
      Neuladen erhalten.

    _Valid login leads to the dashboard and persists across navigation and page
    reloads._

- [ ] Direkter Aufruf einer geschützten URL ohne Sitzung führt zur Loginseite.

    _Direct access to a protected URL without a session leads to the login page._

- [ ] Der Curator sieht keine Admin-Navigation und erhält bei direktem Aufruf
      von Admin-URLs keinen Zugriff.

    _The curator does not see administrator navigation and is denied access when
    opening administrator URLs directly._

- [ ] Logout beendet die Sitzung; Browser-Zurück zeigt keine weiter
      bedienbaren geschützten Inhalte.

    _Logout ends the session; using the browser Back button does not expose
    protected content that can still be used._

### 3.4 Dashboard und Uploads / Dashboard and Uploads

Als Curator ausführen.

_Run as curator._

- [ ] Dashboard zeigt plausible Kennzahlen, Entwürfe und zuletzt bearbeitete
      Ressourcen ohne widersprüchliche Zähler.

    _The dashboard shows plausible metrics, drafts, and recently edited resources
    without contradictory counts._

- [ ] Eine gültige DataCite-XML-Datei wird hochgeladen, bestätigt und korrekt
      in den Editor übernommen.

    _A valid DataCite XML file uploads successfully and is transferred correctly
    to the editor._

- [ ] Eine ungültige XML-Datei erzeugt eine verständliche Fehlermeldung; die
      Seite bleibt bedienbar und es wird keine Ressource gespeichert.

    _An invalid XML file produces an understandable error; the page remains
    usable and no resource is saved._

- [ ] Eine gültige DataCite-JSON-Datei wird übernommen; Kernfelder stimmen mit
      dem Inhalt der Datei überein.

    _A valid DataCite JSON file is imported; core fields match the file contents._

- [ ] Eine gültige IGSN-CSV-Datei startet den vorgesehenen IGSN-Ablauf und
      meldet Erfolg beziehungsweise zeilenbezogene Fehler verständlich.

    _A valid IGSN CSV file starts the intended IGSN workflow and reports success
    or row-specific errors clearly._

- [ ] Drag-and-drop und Dateiauswahl verhalten sich gleich; falsche Dateitypen
      werden abgewiesen.

    _Drag-and-drop and file selection behave consistently; invalid file types are
    rejected._

Geeignete synthetische Ausgangsdateien befinden sich unter
[`tests/pest/dataset-examples`](../tests/pest/dataset-examples) und
[`tests/playwright/fixtures`](../tests/playwright/fixtures). Identifier und
Titel vor dem Speichern immer eindeutig anpassen.

_Suitable synthetic source files are available under
[`tests/pest/dataset-examples`](../tests/pest/dataset-examples) and
[`tests/playwright/fixtures`](../tests/playwright/fixtures). Always make
identifiers and titles unique before saving._

### 3.5 Data Editor – Grundverhalten / Data Editor – Basic Behaviour

Als Curator mit einer neuen Testressource ausführen.

_Run as curator using a new test resource._

- [ ] Leerer Editor lädt vollständig; Akkordeons, Statusanzeigen und Buttons
      reagieren ohne sichtbare JavaScript-Fehler.

    _The empty editor loads completely; accordions, status indicators, and
    buttons respond without visible JavaScript errors._

- [ ] Pflichtfelder werden verständlich gekennzeichnet; Speichern ist bei
      unvollständigen Pflichtdaten verhindert und die Hinweise führen zu den
      betroffenen Feldern.

    _Required fields are clearly identified; saving is prevented while required
    data is incomplete and the messages lead to the affected fields._

- [ ] Ein Entwurf mit unvollständigen Daten kann gespeichert und später wieder
      geöffnet werden, sofern die Oberfläche diese Aktion anbietet.

    _A draft with incomplete data can be saved and reopened later when the UI
    offers that action._

- [ ] Vollständige Pflichtdaten aktivieren `Save to database`; Erfolg wird
      bestätigt und erzeugt genau eine Ressource.

    _Complete required data enables `Save to database`; success is confirmed and
    exactly one resource is created._

- [ ] Doppelklick oder erneutes Bestätigen während des Speicherns erzeugt kein
      Duplikat.

    _Double-clicking or confirming again while saving does not create a
    duplicate._

- [ ] Neu laden oder Zurück-Navigation bei ungespeicherten Änderungen warnt
      angemessen oder verhält sich entsprechend der dokumentierten Anwendung.

    _Reloading or navigating back with unsaved changes warns appropriately or
    behaves according to the documented application behaviour._

### 3.6 Data Editor – Metadatenbereiche / Data Editor – Metadata Sections

In einer repräsentativen Ressource mindestens folgende Bereiche bearbeiten,
speichern und nach erneutem Öffnen kontrollieren:

_Edit at least the following sections in a representative resource, save, and
verify them after reopening:_

- [ ] Ressourceninformation: DOI/Identifier, Publikationsjahr, Ressourcentyp,
      Sprache, Version und Haupttitel.

    _Resource information: DOI/identifier, publication year, resource type,
    language, version, and main title._

- [ ] Titel: Haupttitel und ein zusätzlicher Titeltyp; Reihenfolge und Sprache
      bleiben erhalten.

    _Titles: main title and one additional title type; order and language are
    preserved._

- [ ] Lizenzen: mindestens eine SPDX-Lizenz auswählen; Link und Bezeichnung
      sind plausibel.

    _Licences: select at least one SPDX licence; link and label are plausible._

- [ ] Autoren: Person hinzufügen, bearbeiten, entfernen und per Drag-and-drop
      umsortieren.

    _Creators: add, edit, remove, and reorder a person using drag-and-drop._

- [ ] Institutionellen Autor anlegen und eine ROR-Zuordnung auswählen.

    _Create an institutional creator and select a ROR affiliation._

- [ ] ORCID in gültigem Format erfassen; ungültiges Format wird abgewiesen.
      Sofern verfügbar, ORCID-Suche beziehungsweise Auto-Fill prüfen.

    _Enter an ORCID in valid format; invalid format is rejected. Where available,
    test ORCID search or auto-fill._

- [ ] Autor als Kontaktperson markieren und Kontaktdaten speichern.

    _Mark a creator as contact person and save contact details._

- [ ] Contributors: mindestens zwei unterschiedliche Rollen hinzufügen,
      Reihenfolge ändern und einen Eintrag entfernen.

    _Contributors: add at least two different roles, change the order, and remove
    one entry._

- [ ] CSV-Importdialog für Autoren oder Contributors öffnen; Beispieldatei kann
      heruntergeladen und eine gültige Datei importiert werden.

    _Open the CSV import dialog for creators or contributors; the example file
    can be downloaded and a valid file imported._

- [ ] Beschreibungen: Abstract und einen weiteren Beschreibungstyp speichern;
      Zeichenzähler und Validierung reagieren plausibel.

    _Descriptions: save an abstract and one additional description type;
    character count and validation respond plausibly._

- [ ] Freie Keywords hinzufügen, Duplikatverhalten prüfen und einen Begriff
      entfernen.

    _Add free keywords, test duplicate handling, and remove one keyword._

- [ ] Kontrollierte Vokabulare durchsuchen und mindestens je einen sichtbaren
      Begriffspfad auswählen und entfernen.

    _Search controlled vocabularies and select and remove at least one visible
    term path._

- [ ] MSL-Labor, Instrument oder andere aktivierte Fachvokabulare suchen und
      auswählen; Lade- und Fehlerzustände sind verständlich.

    _Search and select an MSL laboratory, instrument, or other enabled domain
    vocabulary; loading and error states are understandable._

- [ ] Räumliche Abdeckung mit einem Punkt erfassen; gültige Breiten- und
      Längengrade werden akzeptiert.

    _Enter spatial coverage using a point; valid latitude and longitude are
    accepted._

- [ ] Räumliche Abdeckung mit Bounding Box oder Polygon erfassen; ungültige
      Koordinaten und falsche Min-/Max-Reihenfolge werden abgewiesen.

    _Enter spatial coverage using a bounding box or polygon; invalid coordinates
    and incorrect min/max order are rejected._

- [ ] Zeitliche Abdeckung und mindestens ein Datum mit Datumstyp erfassen;
      Einzelwert und Zeitraum werden korrekt dargestellt.

    _Enter temporal coverage and at least one date with a date type; single value
    and range render correctly._

- [ ] Related Work mit DOI und URL hinzufügen; Typ und Relation speichern.
      DOI-Erkennung beziehungsweise Literatur-Lookup prüfen, sofern verfügbar.

    _Add related work using a DOI and URL; save type and relation. Test DOI
    detection or citation lookup where available._

- [ ] Funding Reference mit Förderer, Identifier, Award-Nummer und Titel
      speichern; Lookup-Vorschläge sind plausibel.

    _Save a funding reference with funder, identifier, award number, and title;
    lookup suggestions are plausible._

- [ ] Nach erneutem Öffnen stimmen Werte, Reihenfolgen, Sonderzeichen und
      Zeilenumbrüche mit den gespeicherten Eingaben überein.

    _After reopening, values, order, special characters, and line breaks match
    the saved inputs._

### 3.7 Ressourcenverwaltung / Resource Management

- [ ] `Resources` lädt Tabelle oder Liste, Anzahl und Statusanzeigen ohne
      dauerhaften Spinner.

    _`Resources` loads the table or list, counts, and status indicators without a
    persistent spinner._

- [ ] Suche findet die aktuelle `MANUAL-TEST`-Ressource über Titel und
      Identifier; eine nicht vorhandene Suche zeigt einen sinnvollen Leerzustand.

    _Search finds the current `MANUAL-TEST` resource by title and identifier; a
    search with no match shows a meaningful empty state._

- [ ] Filter lassen sich einzeln und kombiniert anwenden und vollständig
      zurücksetzen.

    _Filters can be applied individually and in combination and can be reset
    completely._

- [ ] Sortierung, Seitennavigation beziehungsweise Nachladen funktionieren und
      verlieren aktive Filter nicht unerwartet.

    _Sorting, pagination, or loading more works and does not unexpectedly lose
    active filters._

- [ ] Einzel- und Mehrfachauswahl zeigen passende Bulk-Aktionen; Auswahlzustand
      und Anzahl stimmen überein.

    _Single and multiple selection show appropriate bulk actions; selection state
    and count agree._

- [ ] DataCite XML, DataCite JSON und JSON-LD einer Testressource werden
      exportiert; Dateien sind nicht leer und enthalten den erwarteten Identifier
      und Titel.

    _DataCite XML, DataCite JSON, and JSON-LD for a test resource export
    successfully; files are not empty and contain the expected identifier and
    title._

- [ ] Öffnen im Editor verwendet die ausgewählte Ressource und erzeugt keine
      Meldung über einen vom Browser blockierten Tab.

    _Opening in the editor uses the selected resource and does not produce a
    browser-blocked-tab message._

- [ ] Eine ausschließlich für diesen Test angelegte, nicht registrierte
      Ressource kann nach Bestätigung gelöscht werden; Abbrechen erhält sie.

    _A non-registered resource created only for this test can be deleted after
    confirmation; cancelling preserves it._

### 3.8 Landingpage-Konfiguration und Vorschau / Landing Page Configuration and Preview

- [ ] Landingpage-Dialog für eine Testressource öffnet und zeigt passende
      Vorlage, Domain sowie Downloadoptionen.

    _The landing page dialog for a test resource opens and shows appropriate
    template, domain, and download options._

- [ ] Gültige Download-URL oder aktivierte Alternative wird gespeichert;
      ungültige URL erzeugt einen verständlichen Hinweis.

    _A valid download URL or enabled alternative is saved; an invalid URL
    produces an understandable message._

- [ ] `Create Preview` beziehungsweise `Update` speichert die Konfiguration
      ohne Duplikat und stellt eine Vorschau bereit.

    _`Create Preview` or `Update` saves the configuration without a duplicate and
    provides a preview._

- [ ] `Preview` öffnet eine neue Seite oder einen neuen Tab und zeigt Titel,
      DOI/Identifier, Autoren, Abstract, Lizenz und Downloadbereich korrekt.

    _`Preview` opens a new page or tab and correctly shows title, DOI/identifier,
    creators, abstract, licence, and download area._

- [ ] Optionale vorhandene Metadaten wie Contributors, Keywords, Förderung,
      räumliche Karte, zeitliche Abdeckung und Related Work werden korrekt
      dargestellt.

    _Available optional metadata such as contributors, keywords, funding,
    spatial map, temporal coverage, and related work render correctly._

- [ ] ORCID-, ROR-, Lizenz-, DOI- und Downloadlinks zeigen auf plausible Ziele
      und öffnen wie vorgesehen.

    _ORCID, ROR, licence, DOI, and download links point to plausible destinations
    and open as intended._

- [ ] Kontaktformular ist bei vorhandener Kontaktperson sichtbar, validiert
      Pflichtfelder und versendet eine Testnachricht an eine Testadresse.

    _The contact form is visible when a contact person exists, validates required
    fields, and sends a test message to a test address._

- [ ] Darstellung funktioniert in Light und Dark Mode; lange Titel, viele
      Autoren und fehlende optionale Abschnitte zerstören das Layout nicht.

    _Rendering works in light and dark mode; long titles, many creators, and
    missing optional sections do not break the layout._

### 3.9 DOI-Registrierung im Testmodus / DOI Registration in Test Mode

- [ ] Eine Ressource ohne erforderliche Landingpage kann nicht versehentlich
      registriert werden und zeigt eine hilfreiche Begründung.

    _A resource without the required landing page cannot be registered
    accidentally and displays a helpful reason._

- [ ] Registrierungsdialog zeigt eindeutig den DataCite-Testmodus, Identifier,
      Zielstatus und betroffene Ressource.

    _The registration dialog clearly shows DataCite test mode, identifier, target
    status, and affected resource._

- [ ] Abbrechen schließt den Dialog ohne Status- oder Datenänderung.

    _Cancelling closes the dialog without changing status or data._

- [ ] Registrierung einer vollständigen `MANUAL-TEST`-Ressource ist
      erfolgreich; Status und Liste aktualisieren sich ohne manuelles hartes
      Neuladen.

    _Registration of a complete `MANUAL-TEST` resource succeeds; status and list
    update without a manual hard reload._

- [ ] Metadaten einer bereits im Testsystem registrierten DOI können nach einer
      Änderung aktualisiert werden.

    _Metadata for a DOI already registered in the test system can be updated
    after a change._

- [ ] Fehlermeldungen des externen Dienstes werden verständlich angezeigt und
      erzeugen keinen falschen Erfolgsstatus.

    _Errors from the external service are displayed clearly and do not produce a
    false success status._

### 3.10 Portal und öffentliche Recherche / Portal and Public Discovery

- [ ] Portal öffnet ohne Login und zeigt Ergebnisanzahl, Ergebnisliste sowie
      Karte oder einen verständlichen Leerzustand.

    _The portal opens without login and shows result count, result list, and map
    or an understandable empty state._

- [ ] Freitextsuche liefert passende Ergebnisse und lässt sich löschen.

    _Free-text search returns matching results and can be cleared._

- [ ] Sichtbare Filter für Datacenter, Ressourcentyp, Keyword, Thesaurus,
      Zeitraum und Geografie funktionieren einzeln und kombiniert.

    _Visible filters for data centre, resource type, keyword, thesaurus, time,
    and geography work individually and in combination._

- [ ] URL und Browsernavigation erhalten beziehungsweise rekonstruieren den
      Filterzustand sinnvoll.

    _The URL and browser navigation preserve or reconstruct filter state
    appropriately._

- [ ] Listen- und Karteninteraktion beziehen sich auf dieselbe Ressource;
      Cluster und Marker reagieren plausibel.

    _List and map interactions refer to the same resource; clusters and markers
    respond plausibly._

- [ ] Ein Suchergebnis öffnet die richtige öffentliche Landingpage.

    _A search result opens the correct public landing page._

### 3.11 IGSN-Listen, Karte und Registrierung / IGSN Lists, Map, and Registration

- [ ] `IGSNs List` lädt Anzahl, Status, Tabelle und Filter ohne Fehler.

    _`IGSNs List` loads count, status, table, and filters without errors._

- [ ] Suche, Filter, Sortierung, Auswahl und Zurücksetzen funktionieren wie bei
      den Datenressourcen.

    _Search, filters, sorting, selection, and reset work as they do for data
    resources._

- [ ] Eine gültige Parent- und Child-CSV kann in der vorgesehenen Reihenfolge
      importiert werden; Hierarchiebezüge werden plausibel dargestellt.

    _A valid parent and child CSV can be imported in the intended order;
    hierarchy relationships render plausibly._

- [ ] Ungültige oder unvollständige CSV-Zeilen melden Zeile und Ursache, ohne
      gültige vorhandene Daten zu beschädigen.

    _Invalid or incomplete CSV rows report the row and cause without damaging
    existing valid data._

- [ ] Einzelimport aus DataCite und sichtbarer Importfortschritt funktionieren;
      Abbrechen beendet einen laufenden Testimport kontrolliert.

    _Single import from DataCite and visible import progress work; cancelling
    stops an active test import cleanly._

- [ ] JSON- und JSON-LD-Export eines Test-IGSN enthalten den erwarteten
      Identifier und die Kerndaten.

    _JSON and JSON-LD exports of a test IGSN contain the expected identifier and
    core data._

- [ ] IGSN-Registrierungsdialog zeigt den Testmodus und Registrierung
      aktualisiert den sichtbaren Status.

    _The IGSN registration dialog shows test mode and registration updates the
    visible status._

- [ ] `IGSNs Map` zeigt Testproben mit Koordinaten; Marker, Cluster und
      Detailinformation stimmen mit der Liste überein.

    _`IGSNs Map` shows test samples with coordinates; markers, clusters, and
    details agree with the list._

- [ ] `IGSN Editor` bleibt deaktiviert, sofern er im Release nicht ausdrücklich
      aktiviert wurde.

    _`IGSN Editor` remains disabled unless it has explicitly been enabled for the
    release._

### 3.12 Persönliche Einstellungen / Personal Settings

Mit Curator und Admin prüfen.

_Test with curator and administrator._

- [ ] Profilseite zeigt korrekten Testnamen, E-Mail und Rolle.

    _The profile page shows the correct test name, email, and role._

- [ ] Eine harmlose Profiländerung lässt sich speichern und anschließend auf
      den Ausgangswert zurücksetzen.

    _A harmless profile change can be saved and then restored to its original
    value._

- [ ] Passwortseite validiert falsches aktuelles Passwort und nicht
      übereinstimmende neue Passwörter. Das echte Testpasswort nur nach Abstimmung
      ändern und anschließend sicher aktualisieren.

    _The password page validates an incorrect current password and mismatched new
    passwords. Change the actual test password only after coordination and then
    update it securely._

- [ ] Appearance unterstützt Light, Dark und System; Auswahl bleibt nach
      Neuladen erhalten.

    _Appearance supports light, dark, and system modes; the selection persists
    after reload._

- [ ] Schriftgrößen-Umschaltung ist per Maus und Tastatur bedienbar, vergrößert
      die Darstellung und bleibt gespeichert.

    _The font-size toggle works with mouse and keyboard, enlarges the display, and
    persists._

### 3.13 Admin-Navigation und Workspace / Administrator Navigation and Workspace

Als Admin ausführen.

_Run as administrator._

- [ ] Workspace-Umschaltung zwischen Curation und Administration zeigt die
      jeweils passenden Navigationsgruppen.

    _Switching between the Curation and Administration workspaces shows the
    appropriate navigation groups._

- [ ] Admin sieht `Users`, `Editor Settings`, `Landing Pages`, `Assistance`,
      `Assessment`, `Database`, `Statistics`, `Statistics (old)`, `Logs` und
      `Old Datasets`, soweit die Module auf Stage aktiviert sind.

    _The administrator sees `Users`, `Editor Settings`, `Landing Pages`,
    `Assistance`, `Assessment`, `Database`, `Statistics`, `Statistics (old)`,
    `Logs`, and `Old Datasets` where those modules are enabled on stage._

- [ ] Wechsel zwischen Admin- und Curation-Seiten verliert weder Sitzung noch
      Workspace-Zustand unerwartet.

    _Switching between administration and curation pages does not unexpectedly
    lose the session or workspace state._

### 3.14 Benutzerverwaltung / User Management

Nur eigens angelegte `MANUAL-TEST`-Benutzer verändern.

_Modify only purpose-created `MANUAL-TEST` users._

- [ ] Benutzerliste lädt, Suche und sichtbare Filter funktionieren.

    _The user list loads; search and visible filters work._

- [ ] Ein temporärer Testbenutzer mit Testadresse kann angelegt werden;
      Validierung verhindert ungültige oder doppelte Angaben.

    _A temporary test user with a test address can be created; validation
    prevents invalid or duplicate data._

- [ ] Willkommens- oder Passwort-E-Mail erreicht die vorgesehene Testadresse
      und enthält einen funktionierenden, nicht öffentlich dokumentierten Link.

    _The welcome or password email reaches the intended test address and contains
    a working link that is not documented publicly._

- [ ] Rolle eines temporären Benutzers lässt sich im erlaubten Rahmen ändern;
      sichtbares Badge und Rechte aktualisieren sich.

    _The role of a temporary user can be changed within the permitted range;
    visible badge and permissions update._

- [ ] Temporärer Benutzer kann deaktiviert und wieder aktiviert werden;
      Loginverhalten entspricht dem Status.

    _The temporary user can be deactivated and reactivated; login behaviour
    matches the status._

- [ ] Passwort-Reset und Guided-Tour-Zuweisung zeigen eine Bestätigung und
      wirken nur auf den ausgewählten Testbenutzer.

    _Password reset and guided-tour assignment show confirmation and affect only
    the selected test user._

- [ ] Temporäre Benutzer und versendete Testeinladungen sind für die
      Nachbereitung notiert und werden bereinigt, soweit die Oberfläche dies
      erlaubt.

    _Temporary users and sent test invitations are recorded for cleanup and are
    removed where the UI permits._

### 3.15 Editor Settings und externe Vokabulare / Editor Settings and External Vocabularies

- [ ] Editor Settings lädt alle sichtbaren Karten und aktuellen Werte.

    _Editor Settings loads all visible cards and current values._

- [ ] Eine eindeutig gekennzeichnete temporäre Einstellung, Domain oder ein
      Datacenter kann angelegt, im Editor ausgewählt und anschließend entfernt
      werden.

    _A clearly labelled temporary setting, domain, or data centre can be created,
    selected in the editor, and then removed._

- [ ] Contributor-Rollen und Lizenz-/Ressourcentypzuordnungen lassen sich
      anzeigen und eine reversible Teständerung bleibt nach Neuladen erhalten.

    _Contributor roles and licence/resource-type mappings can be viewed, and a
    reversible test change persists after reload._

- [ ] Statusprüfungen für sichtbare Thesauri und PID-Dienste liefern ein
      verständliches Ergebnis.

    _Status checks for visible thesauri and PID services return an understandable
    result._

- [ ] Eine vereinbarte Testaktualisierung zeigt Fortschritt, Erfolg oder Fehler
      und aktualisiert Version beziehungsweise Zeitstempel plausibel.

    _An agreed test update shows progress, success, or failure and updates the
    version or timestamp plausibly._

- [ ] Alle reversiblen Konfigurationsänderungen wurden auf den Ausgangswert
      zurückgesetzt.

    _All reversible configuration changes have been restored to their original
    values._

### 3.16 Landingpage-Vorlagen / Landing Page Templates

- [ ] Liste der Landingpage-Vorlagen lädt Vorschau, Status und Aktionen.

    _The landing page template list loads previews, status, and actions._

- [ ] Eine `MANUAL-TEST`-Vorlage kann angelegt oder geklont, bearbeitet und
      gespeichert werden.

    _A `MANUAL-TEST` template can be created or cloned, edited, and saved._

- [ ] Logo-Upload akzeptiert ein gültiges Bild und weist einen ungültigen
      Dateityp verständlich zurück.

    _Logo upload accepts a valid image and clearly rejects an invalid file type._

- [ ] Die Testvorlage ist im Landingpage-Dialog auswählbar und verändert die
      Vorschau wie erwartet.

    _The test template can be selected in the landing page dialog and changes the
    preview as expected._

- [ ] Abbrechen erhält die Vorlage; bestätigtes Löschen entfernt ausschließlich
      die `MANUAL-TEST`-Vorlage.

    _Cancelling preserves the template; confirmed deletion removes only the
    `MANUAL-TEST` template._

### 3.17 Assistance und Assessment / Assistance and Assessment

Wenn ein Modul nicht konfiguriert oder absichtlich deaktiviert ist, mit `N/A`
und Begründung dokumentieren.

_If a module is not configured or intentionally disabled, record `N/A` and a
reason._

- [ ] `Assistance` lädt offene Aufgaben und Zähler konsistent.

    _`Assistance` loads pending tasks and counts consistently._

- [ ] Eine synthetische Empfehlung kann geöffnet, geprüft, angenommen oder
      abgelehnt werden; Ressource und Zähler aktualisieren sich passend.

    _A synthetic suggestion can be opened, reviewed, accepted, or rejected; the
    resource and counts update appropriately._

- [ ] Externe Vorschlagsdienste zeigen Lade-, Erfolgs-, Leer- und Fehlerzustand
      verständlich.

    _External suggestion services display loading, success, empty, and error
    states clearly._

- [ ] `Assessment` lädt Zusammenfassung und Ressourcen ohne dauerhaften
      Ladezustand.

    _`Assessment` loads the summary and resources without a persistent loading
    state._

- [ ] Beide Assessment-Tabellen zeigen für auswertbare Ergebnisse den Buchstaben
      der größten rohen F-UJI-Lücke (`F`, `A`, `I` oder `R`); Farbe,
      zugänglicher Name und Tooltip geben das mögliche Plus im FAIR-Gesamtscore
      konsistent wieder.

    _Both Assessment tables show the letter for the largest raw F-UJI gap (`F`,
    `A`, `I`, or `R`) for usable results; color, accessible name, and tooltip
    consistently communicate the potential increase in the overall FAIR score._

- [ ] Hover und Tastaturfokus öffnen englische, positiv formulierte Hinweise mit
      höchstens drei tatsächlich punktwirksamen Maßnahmen in korrekter
      Hebel-Reihenfolge; Administrator-Maßnahmen sind erkennbar gekennzeichnet.

    _Hover and keyboard focus reveal English, positively worded guidance with no
    more than three genuinely score-causal actions in the correct leverage order;
    administrator actions are clearly identified._

- [ ] Resource- und IGSN-Hinweise verwenden unterschiedliche Formulierungen.
      Eine physische Probe erhält keine Aufforderung, Downloads, Dateigrößen oder
      Dateiformate einzutragen; nicht anwendbare digitale F-UJI-Prüfungen werden
      neutral erklärt.

    _Resource and IGSN guidance uses distinct wording. A physical sample is never
    asked to add downloads, file sizes, or file formats; inapplicable digital
    F-UJI checks are explained neutrally._

- [ ] Nach einer Metadatenänderung, die neuer als das gespeicherte Assessment ist,
      fordert der Tooltip zunächst zu einem neuen Assessment auf. Vollständige
      und nicht auswertbare Ergebnisse zeigen denselben neutralen Strich, aber
      unterschiedliche zugängliche Erklärungen.

    _After a metadata change newer than the stored assessment, the tooltip first
    asks for reassessment. Complete and unavailable results show the same neutral
    dash but provide distinct accessible explanations._

- [ ] Einzelne Testressource und vorgesehener Sammellauf können geprüft werden;
      Fortschritt und Ergebnis werden aktualisiert.

    _A single test resource and the intended batch run can be assessed; progress
    and result update._

- [ ] Bei nicht verfügbarem F-UJI-Dienst erscheint eine verständliche Meldung
      statt einer endlosen Ladeanzeige oder leeren Seite.

    _If the F-UJI service is unavailable, an understandable message appears
    instead of an endless loading indicator or blank page._

### 3.18 Statistik und Altdaten / Statistics and Legacy Data

- [ ] `Statistics` lädt alle sichtbaren Kennzahlen, Diagramme und Tabellen;
      Werte, Legenden und Tooltips sind plausibel.

    _`Statistics` loads all visible metrics, charts, and tables; values, legends,
    and tooltips are plausible._

- [ ] Leere oder kleine Datenmengen führen nicht zu kaputten Diagrammen.

    _Empty or small datasets do not produce broken charts._

- [ ] `Statistics (old)` öffnet ohne Serverfehler und zeigt plausible
      Vergleichsdaten oder einen erklärten Leerzustand.

    _`Statistics (old)` opens without server errors and shows plausible
    comparison data or an explained empty state._

- [ ] `Old Datasets` lädt Liste, Suche, Filter und Nachladen.

    _`Old Datasets` loads the list, search, filters, and load-more behaviour._

- [ ] Detaildaten wie Autoren, Contributors, Förderung, Beschreibungen, Daten,
      Keywords, räumliche Abdeckung und Related Identifiers können geladen werden.

    _Detail data such as creators, contributors, funding, descriptions, dates,
    keywords, spatial coverage, and related identifiers can be loaded._

- [ ] Übernahme eines synthetischen Altdatensatzes in den Editor befüllt die
      zugehörigen Felder plausibel, ohne die Quelle zu verändern.

    _Transferring a synthetic legacy dataset into the editor populates the
    related fields plausibly without changing the source._

### 3.19 Logs – ausschließlich lesend / Logs – Read Only

- [ ] `Logs` lädt Liste, Zeitstempel, Level und Meldungen ohne Fehler.

    _`Logs` loads the list, timestamps, levels, and messages without errors._

- [ ] Suche, Filter, Sortierung und Detailansicht funktionieren, sofern
      angeboten.

    _Search, filters, sorting, and detail view work where provided._

- [ ] Ein im Test gezielt erzeugter ungefährlicher Fehler lässt sich zeitlich
      zuordnen, ohne vertrauliche Zugangsdaten in der Oberfläche anzuzeigen.

    _A harmless error intentionally triggered during the test can be correlated
    by time without exposing confidential credentials in the UI._

- [ ] **Keine Löschaktion wurde ausgeführt.**

    _**No delete action has been performed.**_

### 3.20 Datenbank-Dumps / Database Dumps

- [ ] `Database` öffnet und zeigt vorhandene Dumps, Zielsysteme, Status und
      Zeitstempel plausibel.

    _`Database` opens and shows existing dumps, target systems, status, and
    timestamps plausibly._

- [ ] Ein Stage-Testdump kann gestartet werden; Fortschritt wechselt
      kontrolliert zu Erfolg oder zeigt einen verständlichen Fehler.

    _A stage test dump can be started; progress changes cleanly to success or
    displays an understandable error._

- [ ] Ein erfolgreicher Testdump kann heruntergeladen werden; Datei ist nicht
      leer und wird nicht in ein öffentliches oder geteiltes Verzeichnis kopiert.

    _A successful test dump can be downloaded; the file is not empty and is not
    copied to a public or shared directory._

- [ ] Heruntergeladene Dumps werden nach der Prüfung sicher vom Testrechner
      entfernt.

    _Downloaded dumps are securely removed from the test machine after
    verification._

### 3.21 Dokumentation und Guided Tours / Documentation and Guided Tours

- [ ] `Documentation` zeigt die Tabs `Getting Started`, `Datasets` und
      `Physical Samples`; Inhalte wechseln ohne Seitenfehler.

    _`Documentation` shows the `Getting Started`, `Datasets`, and `Physical
Samples` tabs; content switches without page errors._

- [ ] Seitennavigation springt zum richtigen Abschnitt und markiert den
      sichtbaren Abschnitt.

    _Section navigation jumps to the correct section and marks the visible
    section._

- [ ] Codebeispiele und interne Links sind vollständig lesbar und führen zum
      erwarteten Ziel.

    _Code examples and internal links are fully readable and lead to the expected
    destination._

- [ ] Eine einem temporären Benutzer zugewiesene Guided Tour startet, lässt
      sich bedienen, schließen und abschließen.

    _A guided tour assigned to a temporary user starts, can be operated, closed,
    and completed._

### 3.22 Firefox-, Mobil- und Barrierefreiheits-Gegencheck / Firefox, Mobile, and Accessibility Cross-Check

In aktuellem Firefox:

_In current Firefox:_

- [ ] Login, Dashboard, Ressourcenliste, Editor einer bestehenden Ressource,
      Speichern einer Änderung, Landingpage-Vorschau und Logout funktionieren.

    _Login, dashboard, resource list, editing an existing resource, saving a
    change, landing page preview, and logout work._

- [ ] Portal-Suche und eine öffentliche Landingpage funktionieren.

    _Portal search and a public landing page work._

In Chrome mit mobilem Viewport:

_In Chrome using a mobile viewport:_

- [ ] Login, Hauptnavigation, Dashboard und Logout bleiben vollständig
      bedienbar; Inhalte überlagern sich nicht horizontal.

    _Login, main navigation, dashboard, and logout remain fully usable; content
    does not overlap horizontally._

- [ ] Portal-Suche, Filteröffnung, Ergebnisliste und öffentliche Landingpage
      sind bedienbar.

    _Portal search, opening filters, result list, and public landing page are
    usable._

- [ ] Dokumentations-Tabs und mobile Changelog-Navigation funktionieren.

    _Documentation tabs and mobile changelog navigation work._

Grundlegende Barrierefreiheit in Chrome:

_Basic accessibility in Chrome:_

- [ ] Login, Navigation, Dialoge und zentrale Formularfelder sind per Tab,
      Enter, Leertaste und Escape bedienbar.

    _Login, navigation, dialogs, and critical form fields can be used with Tab,
    Enter, Space, and Escape._

- [ ] Fokus ist sichtbar und springt beim Öffnen beziehungsweise Schließen
      eines Dialogs sinnvoll.

    _Focus is visible and moves appropriately when a dialog opens or closes._

- [ ] Browser-Zoom bei 200 % lässt kritische Inhalte und Aktionen erreichbar.

    _At 200% browser zoom, critical content and actions remain accessible._

- [ ] Fehlermeldungen sind nicht ausschließlich durch Farbe erkennbar.

    _Error messages are not communicated by colour alone._

### 3.23 Querschnittsprüfung / Cross-Cutting Checks

- [ ] Während des Testlaufs erscheinen keine unerwarteten leeren Seiten,
      `500`-/`502`-/`504`-Fehler oder dauerhaft drehenden Ladeanzeigen.

    _No unexpected blank pages, `500`/`502`/`504` errors, or persistent loading
    indicators appear during the test run._

- [ ] Erfolgs- und Fehlermeldungen passen zur ausgeführten Aktion und
      verschwinden nicht, bevor sie gelesen werden können.

    _Success and error messages match the action performed and do not disappear
    before they can be read._

- [ ] Doppelklick, Abbrechen und Browser-Zurück erzeugen keine Duplikate oder
      unbemerkten Datenverlust.

    _Double-clicking, cancelling, and using browser Back do not create duplicates
    or unnoticed data loss._

- [ ] Sonderzeichen, Umlaute, lange Titel und Zeilenumbrüche werden in Editor,
      Listen, Exporten und Landingpages korrekt dargestellt.

    _Special characters, umlauts, long titles, and line breaks render correctly
    in the editor, lists, exports, and landing pages._

- [ ] Typische Seiten reagieren in vertretbarer Zeit; auffällige Wartezeiten
      werden mit Uhrzeit und Aktion dokumentiert.

    _Typical pages respond within a reasonable time; unusual delays are recorded
    with time and action._

### 3.24 Bereinigung und Abschluss / Cleanup and Completion

- [ ] Alle im Test erzeugten Ressourcen, Entwürfe, IGSNs,
      Landingpage-Konfigurationen und Vorlagen sind gelöscht oder mit Grund zur
      späteren Bereinigung notiert.

    _All resources, drafts, IGSNs, landing page configurations, and templates
    created during the test are deleted or recorded with a reason for later
    cleanup._

- [ ] Temporäre Benutzer, Rollenänderungen, Einstellungen und Testdateien
      wurden bereinigt beziehungsweise zurückgesetzt.

    _Temporary users, role changes, settings, and test files have been cleaned up
    or restored._

- [ ] Extern registrierte Test-DOIs und Test-IGSNs sind vollständig
      dokumentiert.

    _Externally registered test DOIs and test IGSNs are fully documented._

- [ ] Heruntergeladene Datenbank-Dumps wurden sicher entfernt.

    _Downloaded database dumps have been securely removed._

- [ ] Logs wurden nicht gelöscht.

    _Logs have not been deleted._

#### Zusammenfassung / Summary

| Bereich / Area                               | Ergebnis / Result           | Backlog Items / Notes |
| -------------------------------------------- | --------------------------- | --------------------- |
| Smoke-Test / Smoke test                      | PASS / FAIL / BLOCKED       |                       |
| Öffentlich und Auth / Public and auth        | PASS / FAIL / BLOCKED / N/A |                       |
| Curator-Kernabläufe / Curator core workflows | PASS / FAIL / BLOCKED / N/A |                       |
| DOI und Landingpages / DOI and landing pages | PASS / FAIL / BLOCKED / N/A |                       |
| IGSN                                         | PASS / FAIL / BLOCKED / N/A |                       |
| Adminbereiche / Administrator areas          | PASS / FAIL / BLOCKED / N/A |                       |
| Firefox und Mobil / Firefox and mobile       | PASS / FAIL / BLOCKED / N/A |                       |
| Bereinigung / Cleanup                        | PASS / FAIL / BLOCKED       |                       |

| Abschlussfeld / Completion field                                   | Eintrag / Entry                                                                                  |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------ |
| Endzeit / End time                                                 |                                                                                                  |
| Anzahl PASS / Number of PASS                                       |                                                                                                  |
| Anzahl FAIL / Number of FAIL                                       |                                                                                                  |
| Anzahl BLOCKED / Number of BLOCKED                                 |                                                                                                  |
| Anzahl N/A / Number of N/A                                         |                                                                                                  |
| offene Blocker/kritische Fehler / Open blocker or critical defects |                                                                                                  |
| weitere offene Fehler / Other open defects                         |                                                                                                  |
| Restrisiken / Residual risks                                       | Group Leader und Beginner nicht manuell getestet / Group Leader and Beginner not tested manually |
| Empfehlung der Testerin / Tester recommendation                    | GO / GO WITH KNOWN RISKS / NO-GO                                                                 |
| Begründung / Rationale                                             |                                                                                                  |
| An Product Ownerin gesendet am / Sent to Product Owner on          |                                                                                                  |

**Empfehlungsregel:** `GO` nur bei bestandenem Smoke-Test, ohne offene Blocker
oder kritische Fehler und nach erfolgreicher Bereinigung. Abweichungen und
`N/A` müssen nachvollziehbar begründet sein. Die finale Entscheidung liegt bei
Tanja ([anti@gfz.de](mailto:anti@gfz.de)).

_**Recommendation rule:** Use `GO` only after a successful smoke test, with no
open blocker or critical defects, and after successful cleanup. Deviations and
`N/A` results must have a clear rationale. The final decision rests with Tanja
([anti@gfz.de](mailto:anti@gfz.de))._
