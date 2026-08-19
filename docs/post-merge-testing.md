# Post-Merge-Testing von ERNIE / Post-Merge Testing of ERNIE

Diese Anleitung dient zur gezielten manuellen Prüfung eines Features, Fixes
oder Hotfixes, nachdem der zugehörige Pull Request gemergt und auf Stage
bereitgestellt wurde. Sie ersetzt nicht den vollständigen
Pre-Release-Regressionstest.

_This guide is used for targeted manual testing of a feature, fix, or hotfix
after its pull request has been merged and deployed to stage. It does not
replace the full pre-release regression test._

> **Wichtig / Important:** Vor einem Testlauf eine lokale Kopie dieser Datei
> anlegen und nur die Kopie ausfüllen, zum Beispiel
> `post-merge-testing-2026-07-17-pr-123.md`. Ausgefüllte Testläufe werden nicht
> in diesem Repository gepflegt.
>
> _Before starting a test run, create a local copy of this file and fill in only
> that copy, for example `post-merge-testing-2026-07-17-pr-123.md`. Completed
> test runs are not maintained in this repository._

## 1. Grundregeln / Ground Rules

- Ausschließlich auf <https://ernie.rz-vm182.gfz.de/> testen und vor Beginn
  bestätigen lassen, dass der erwartete PR auf Stage deployed wurde.

    _Test only at <https://ernie.rz-vm182.gfz.de/> and confirm before starting
    that the expected PR has been deployed to stage._

- Dedizierte Admin- oder Curator-Testkonten verwenden. Group Leader und Beginner
  sind nicht Bestandteil der regelmäßigen manuellen Tests.

    _Use dedicated administrator or curator test accounts. Group Leader and
    Beginner are not part of regular manual testing._

- Nur synthetische Daten mit einer eindeutigen Kennung wie
  `MANUAL-TEST-20260717-AB` verwenden und anschließend bereinigen.

    _Use only synthetic data with a unique identifier such as
    `MANUAL-TEST-20260717-AB` and clean it up afterwards._

- Stage wird mit `DATACITE_TEST_MODE=true` erwartet. Vor einer DOI- oder
  IGSN-Registrierung muss der Dialog den Testmodus eindeutig anzeigen. Fehlt
  der Hinweis, die Registrierung nicht bestätigen und den Test blockieren.

    _Stage is expected to use `DATACITE_TEST_MODE=true`. Before registering a DOI
    or IGSN, the dialog must clearly show test mode. If the notice is missing, do
    not confirm the registration and block the test._

- Alle sichtbaren Stage-Aktionen dürfen mit Testdaten ausgeführt werden.
  **Logs dürfen weder einzeln noch vollständig gelöscht werden.**

    _All visible stage actions may be performed using test data.
    **Logs must not be deleted, either individually or in bulk.**_

- Zugangsdaten niemals in Testprotokolle, Screenshots oder Backlog Items
  übernehmen.

    _Never include credentials in test logs, screenshots, or backlog items._

### 1.1 Ergebnisse und Fehler / Results and Defects

Verwendete Status:

_Statuses used:_

- **PASS:** Erwartetes Ergebnis erreicht. / _Expected result achieved._
- **FAIL:** Erwartetes Ergebnis nicht erreicht. / _Expected result not achieved._
- **BLOCKED:** Test wegen fehlender Voraussetzung oder anderem Fehler nicht
  ausführbar. / _Test cannot be executed because of a missing prerequisite or
  another defect._
- **N/A:** Bewusst nicht anwendbar; Begründung ist Pflicht. / _Intentionally not
  applicable; a reason is required._

Für jedes einzelne Problem ein eigenes Backlog Item gemäß dem internen Cheat
Sheet **„Creating Backlog Items“** erstellen. Mindestens Umgebung und
Deploy-Version, Rolle, Browser, Testdatenkennung, Reproduktionsschritte,
Soll-/Ist-Ergebnis, Häufigkeit und einen bereinigten Screenshot dokumentieren.

_Create a separate backlog item for each problem according to the internal
cheat sheet **“Creating Backlog Items”**. Document at least the environment and
deployment version, role, browser, test data identifier, reproduction steps,
expected and actual result, frequency, and a sanitised screenshot._

## 2. Ablauf nach dem Merge / Post-Merge Process

1. Prüfen, dass der PR gemergt und das zugehörige Deployment auf Stage
   abgeschlossen ist.  
   _Verify that the PR is merged and its deployment to stage is complete._
2. Den Smoke-Test aus
   [`pre-release-testing.md`](pre-release-testing.md) ausführen. Bei `FAIL` oder
   `BLOCKED` den gezielten Test nicht als bestanden bewerten.  
   _Run the smoke test from
   [`pre-release-testing.md`](pre-release-testing.md). If it returns `FAIL` or
   `BLOCKED`, do not consider the targeted test successful._
3. Änderung, Akzeptanzkriterien, betroffene Rollen und Risikostufe mit der
   Entwicklung klären.  
   _Clarify the change, acceptance criteria, affected roles, and risk level
   with development._
4. Das Template in Abschnitt 3 ausfüllen und direkte sowie angrenzende
   Testfälle ausführen.  
   _Complete the template in section 3 and run direct and adjacent test cases._
5. Abweichungen als einzelne Backlog Items erfassen, Testdaten bereinigen und
   `ACCEPT`, `ACCEPT WITH KNOWN RISKS` oder `REJECT` empfehlen.  
   _Record deviations as separate backlog items, clean up test data, and
   recommend `ACCEPT`, `ACCEPT WITH KNOWN RISKS`, or `REJECT`._
6. Soll die Version anschließend nach Prod, zusätzlich den vollständigen
   Pre-Release-Regressionstest ausführen.  
   _If the version is intended for production, also run the full pre-release
   regression test._

### 2.1 Mindestumfang nach Risiko / Minimum Scope by Risk

| Risiko / Risk   | Beispiele / Examples                                                                                                                 | Mindestumfang / Minimum scope                                                                                                          |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------- |
| Niedrig / Low   | Text, Icon, nicht-interaktive Darstellung / Copy, icon, non-interactive presentation                                                 | Smoke + direkte Akzeptanzkriterien / Smoke + direct acceptance criteria                                                                |
| Mittel / Medium | Filter, Dialog, Formularfeld, Export / Filter, dialog, form field, export                                                            | Smoke + positive und negative Fälle + angrenzende Seite / Smoke + positive and negative cases + adjacent page                          |
| Hoch / High     | Login, Rollen, Speichern, Migration, DOI/IGSN, Import, Landingpage / Login, roles, saving, migration, DOI/IGSN, import, landing page | Smoke + vollständiger betroffener End-to-End-Ablauf + betroffene Rolle / Smoke + complete affected end-to-end workflow + affected role |

---

## 3. Feature-/Fix-Test (nach einem PR-Merge) / Feature or Fix Test (After a PR Merge)

Für jeden Feature- oder Fix-Test diesen gesamten Abschnitt in eine neue lokale
Datei kopieren. Der Test beginnt immer mit dem Smoke-Test aus der
[Pre-Release-Anleitung](pre-release-testing.md).

_For every feature or fix test, copy this entire section into a new local file.
Testing always starts with the smoke test from the
[pre-release guide](pre-release-testing.md)._

### 3.1 Auftrag / Request

| Feld / Field                                            | Eintrag / Entry        |
| ------------------------------------------------------- | ---------------------- |
| Titel / Title                                           |                        |
| Typ / Type                                              | Feature / Fix / Hotfix |
| Issue/Backlog Item                                      |                        |
| PR                                                      |                        |
| Commit oder Version / Commit or version                 |                        |
| Stage-Deploy abgeschlossen / Stage deployment completed |                        |
| Auftraggeberin oder Auftraggeber / Requester            |                        |
| Testerin / Tester                                       |                        |
| Testdatum / Test date                                   |                        |
| Rolle(n) / Role(s)                                      | Admin / Curator        |
| Browser und Viewport / Browser and viewport             |                        |
| Testdatenkennung / Test data identifier                 | `MANUAL-TEST-`         |

### 3.2 Änderung verstehen / Understand the Change

**Kurzbeschreibung / Short description**

>

**Problem vor der Änderung / Problem before the change**

>

**Erwartetes Verhalten nach der Änderung / Expected behaviour after the change**

>

**Bewusst nicht Bestandteil / Explicitly out of scope**

>

**Technische oder fachliche Hinweise der Entwicklung / Technical or functional notes from development**

>

### 3.3 Risiko und Testumfang / Risk and Test Scope

| Frage / Question                                                                                                            | Antwort / Answer                              |
| --------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------- |
| Welche Seiten oder Module sind direkt betroffen? / Which pages or modules are directly affected?                            |                                               |
| Welche gespeicherten Daten sind betroffen? / Which stored data is affected?                                                 |                                               |
| Ändert sich Rollen- oder Zugriffsschutz? / Does role or access control change?                                              | Ja / Nein – Yes / No                          |
| Betrifft es Import, Export oder Migration? / Does it affect import, export, or migration?                                   | Ja / Nein – Yes / No                          |
| Betrifft es DOI, IGSN oder einen externen Dienst? / Does it affect DOI, IGSN, or an external service?                       | Ja / Nein – Yes / No                          |
| Betrifft es responsive Darstellung oder einen bestimmten Browser? / Does it affect responsive design or a specific browser? |                                               |
| Risikostufe / Risk level                                                                                                    | Niedrig / Mittel / Hoch – Low / Medium / High |
| Begründung / Rationale                                                                                                      |                                               |

**Ausgewählter Testumfang / Selected test scope**

- [ ] Smoke-Test aus der [Pre-Release-Anleitung](pre-release-testing.md) / _Smoke test from the [pre-release guide](pre-release-testing.md)_
- [ ] Direkte Akzeptanzkriterien / _Direct acceptance criteria_
- [ ] Positiver Hauptfall / _Positive main case_
- [ ] Negativ- und Validierungsfälle / _Negative and validation cases_
- [ ] Rollen- und Zugriffsschutz / _Roles and access control_
- [ ] Speichern, Neuladen und erneutes Bearbeiten / _Save, reload, and edit again_
- [ ] Import/Export / _Import/export_
- [ ] Angrenzende Regression / _Adjacent regression_
- [ ] Firefox / _Firefox_
- [ ] Mobiler Viewport / _Mobile viewport_
- [ ] Vollständiger betroffener End-to-End-Ablauf / _Complete affected end-to-end workflow_

### 3.4 Voraussetzungen und Testdaten / Preconditions and Test Data

| Voraussetzung oder Testdatum / Precondition or test data | Wert und erwarteter Zustand / Value and expected state |
| -------------------------------------------------------- | ------------------------------------------------------ |
| Testkonto / Test account                                 |                                                        |
| Ausgangsseite / Starting page                            |                                                        |
| vorhandene Ressource / Existing resource                 |                                                        |
| neu anzulegende Ressource / Resource to create           |                                                        |
| Dateien / Files                                          |                                                        |
| externe Dienste / External services                      |                                                        |
| Feature-Konfiguration / Feature configuration            |                                                        |
| weitere Voraussetzung / Other precondition               |                                                        |

- [ ] Alle Voraussetzungen sind erfüllt oder als `BLOCKED` dokumentiert.

    _All preconditions are met or documented as `BLOCKED`._

- [ ] Testdaten sind synthetisch, eindeutig gekennzeichnet und können
      anschließend bereinigt werden.

    _Test data is synthetic, uniquely labelled, and can be cleaned up afterwards._

### 3.5 Akzeptanzkriterien / Acceptance Criteria

Jedes Kriterium muss beobachtbar und einzeln entscheidbar sein.

_Each criterion must be observable and independently decidable._

| ID    | Akzeptanzkriterium / Acceptance criterion | Status                      | Nachweis oder Backlog Item / Evidence or backlog item |
| ----- | ----------------------------------------- | --------------------------- | ----------------------------------------------------- |
| AC-01 |                                           | PASS / FAIL / BLOCKED / N/A |                                                       |
| AC-02 |                                           | PASS / FAIL / BLOCKED / N/A |                                                       |
| AC-03 |                                           | PASS / FAIL / BLOCKED / N/A |                                                       |
| AC-04 |                                           | PASS / FAIL / BLOCKED / N/A |                                                       |
| AC-05 |                                           | PASS / FAIL / BLOCKED / N/A |                                                       |

### 3.6 Detaillierte Testfälle / Detailed Test Cases

Für jeden Testfall konkrete Eingaben und ein überprüfbares Ergebnis angeben.
Bei Bedarf Zeilen kopieren.

_For each test case, specify concrete inputs and a verifiable result. Copy rows
as needed._

| ID    | Ausgangslage / Preconditions | Schritte und Eingaben / Steps and input | Erwartetes Ergebnis / Expected result | Status                      | Nachweis oder Backlog Item / Evidence or backlog item |
| ----- | ---------------------------- | --------------------------------------- | ------------------------------------- | --------------------------- | ----------------------------------------------------- |
| TC-01 |                              |                                         |                                       | PASS / FAIL / BLOCKED / N/A |                                                       |
| TC-02 |                              |                                         |                                       | PASS / FAIL / BLOCKED / N/A |                                                       |
| TC-03 |                              |                                         |                                       | PASS / FAIL / BLOCKED / N/A |                                                       |
| TC-04 |                              |                                         |                                       | PASS / FAIL / BLOCKED / N/A |                                                       |
| TC-05 |                              |                                         |                                       | PASS / FAIL / BLOCKED / N/A |                                                       |

Mindestens berücksichtigen:

_Consider at least:_

- [ ] Happy Path mit gültigen Eingaben.

    _Happy path using valid input._

- [ ] Leere, ungültige und grenzwertige Eingaben.

    _Empty, invalid, and boundary input._

- [ ] Abbrechen, Zurück, Neuladen und wiederholtes Klicken.

    _Cancel, Back, reload, and repeated clicking._

- [ ] Speichern und Kontrolle nach erneutem Öffnen.

    _Save and verify after reopening._

- [ ] Fehlermeldung und Erholung nach einem Fehler.

    _Error message and recovery after an error._

- [ ] Betroffene Admin- und/oder Curator-Perspektive.

    _Affected administrator and/or curator perspective._

### 3.7 Fix-spezifischer Nachtest / Fix-Specific Retest

Bei einem Fix ausfüllen, bei einem reinen Feature mit `N/A` begründen.

_Complete for a fix; for a feature-only change, record `N/A` and a reason._

| Feld / Field                                                        | Eintrag / Entry |
| ------------------------------------------------------------------- | --------------- |
| ursprüngliches Problem / Original problem                           |                 |
| ursprüngliche Reproduktionsschritte / Original reproduction steps   |                 |
| erwarteter Fehler vor dem Fix / Expected failure before the fix     |                 |
| Verhalten nach dem Fix / Behaviour after the fix                    |                 |
| ursprünglicher Datensatz oder Randfall / Original data or edge case |                 |

- [ ] Ursprüngliche Schritte führen nicht mehr zum Fehler.

    _The original steps no longer reproduce the defect._

- [ ] Der ursprüngliche Randfall wurde mit denselben relevanten Eingaben
      wiederholt.

    _The original edge case was repeated using the same relevant input._

- [ ] Ein Gegenbeispiel beziehungsweise Negativfall verhält sich weiterhin
      korrekt.

    _A counterexample or negative case still behaves correctly._

- [ ] Es wurde geprüft, dass der Fix nicht nur die sichtbare Meldung, sondern
      auch gespeicherte Daten beziehungsweise Status korrigiert.

    _It was verified that the fix corrects not only the visible message but also
    stored data or status._

### 3.8 Angrenzende Regression / Adjacent Regression

| Angrenzender Bereich / Adjacent area                      | Warum betroffen? / Why affected? | Prüfschritt / Check | Status                      |
| --------------------------------------------------------- | -------------------------------- | ------------------- | --------------------------- |
| vorheriger Schritt / Previous step                        |                                  |                     | PASS / FAIL / BLOCKED / N/A |
| nachfolgender Schritt / Next step                         |                                  |                     | PASS / FAIL / BLOCKED / N/A |
| Speichern und Laden / Save and load                       |                                  |                     | PASS / FAIL / BLOCKED / N/A |
| Liste, Suche oder Filter / List, search, or filters       |                                  |                     | PASS / FAIL / BLOCKED / N/A |
| Export oder öffentliche Ausgabe / Export or public output |                                  |                     | PASS / FAIL / BLOCKED / N/A |
| andere Rolle / Other role                                 |                                  |                     | PASS / FAIL / BLOCKED / N/A |

Für Änderungen an einem kritischen Bereich den passenden vollständigen Ablauf
zusätzlich ausführen:

_For changes to a critical area, also run the corresponding complete workflow:_

- [ ] Login/Rollen: Abschnitt 3.3 der Pre-Release-Anleitung / _Login/roles: section 3.3 of the pre-release guide_
- [ ] Dashboard/Upload: Abschnitt 3.4 der Pre-Release-Anleitung / _Dashboard/upload: section 3.4 of the pre-release guide_
- [ ] Editor/Speichern: Abschnitte 3.5–3.7 der Pre-Release-Anleitung / _Editor/saving: sections 3.5–3.7 of the pre-release guide_
- [ ] Landingpage/DOI/Portal: Abschnitte 3.8–3.10 der Pre-Release-Anleitung / _Landing page/DOI/portal:
      sections 3.8–3.10 of the pre-release guide_
- [ ] IGSN: Abschnitt 3.11 der Pre-Release-Anleitung / _IGSN: section 3.11 of the pre-release guide_
- [ ] Adminfunktion: passender Abschnitt 3.13–3.21 der Pre-Release-Anleitung / _Administrator function:
      corresponding section 3.13–3.21 of the pre-release guide_

### 3.9 Abweichungen und Backlog Items / Deviations and Backlog Items

Jedes Problem einzeln gemäß **„Creating Backlog Items“** erfassen.

_Record each problem separately according to **“Creating Backlog Items”**._

| Backlog Item | Schweregrad / Severity | betroffener Testfall / Affected test case | Kurzbeschreibung / Summary | Status       |
| ------------ | ---------------------- | ----------------------------------------- | -------------------------- | ------------ |
|              |                        |                                           |                            | offen / open |
|              |                        |                                           |                            | offen / open |
|              |                        |                                           |                            | offen / open |

### 3.10 Bereinigung / Cleanup

- [ ] Neu angelegte Ressourcen, IGSNs und Landingpage-Konfigurationen wurden
      entfernt oder dokumentiert.

    _Newly created resources, IGSNs, and landing page configurations were removed
    or documented._

- [ ] Temporäre Benutzer, Rollen und Einstellungen wurden zurückgesetzt.

    _Temporary users, roles, and settings were restored._

- [ ] Externe Testregistrierungen und versendete Test-E-Mails wurden notiert.

    _External test registrations and sent test emails were recorded._

- [ ] Downloads und Datenbank-Dumps wurden sicher entfernt.

    _Downloads and database dumps were securely removed._

- [ ] Logs wurden nicht gelöscht.

    _Logs were not deleted._

### 3.11 Ergebnis und Empfehlung / Result and Recommendation

| Abschlussfeld / Completion field                                   | Eintrag / Entry                           |
| ------------------------------------------------------------------ | ----------------------------------------- |
| Smoke-Test / Smoke test                                            | PASS / FAIL / BLOCKED                     |
| Akzeptanzkriterien / Acceptance criteria                           | PASS / FAIL / BLOCKED                     |
| Angrenzende Regression / Adjacent regression                       | PASS / FAIL / BLOCKED / N/A               |
| Bereinigung / Cleanup                                              | PASS / FAIL / BLOCKED                     |
| offene Blocker/kritische Fehler / Open blocker or critical defects |                                           |
| bekannte normale/geringe Fehler / Known major/minor defects        |                                           |
| nicht getestete Punkte und Grund / Untested items and reason       |                                           |
| Restrisiko / Residual risk                                         |                                           |
| Endzeit / End time                                                 |                                           |
| Empfehlung der Testerin / Tester recommendation                    | ACCEPT / ACCEPT WITH KNOWN RISKS / REJECT |
| Begründung / Rationale                                             |                                           |

**Weiteres Vorgehen / Next action**

- [ ] `ACCEPT`: Änderung erfüllt die Kriterien und kann im nächsten Release
      berücksichtigt werden.

    _`ACCEPT`: The change meets the criteria and can be included in the next
    release._

- [ ] `ACCEPT WITH KNOWN RISKS`: Abweichungen sind als Backlog Items erfasst
      und das Restrisiko ist beschrieben.

    _`ACCEPT WITH KNOWN RISKS`: Deviations are recorded as backlog items and the
    residual risk is described._

- [ ] `REJECT`: Mindestens ein erforderliches Kriterium ist fehlgeschlagen oder
      blockiert; Nachbesserung und erneutes Stage-Deployment erforderlich.

    _`REJECT`: At least one required criterion failed or is blocked; correction
    and another stage deployment are required._

Name und Datum der Testerin / Tester name and date:

>
