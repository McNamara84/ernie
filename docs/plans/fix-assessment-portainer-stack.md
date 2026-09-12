# Implementierungsplan: FAIR-Assessment in Portainer-Stacks wiederherstellen

## Status

Die Portainer-Topologie wurde auf Stage ausgerollt. Der dabei gefundene
Deadlock-Folgefehler wurde am 10. September 2026 lokal implementiert und gegen
MySQL 9.7 validiert; dessen erneuter Stage-Rollout sowie Produktion stehen noch
aus.

## Zusammenfassung

Der ursprüngliche Fehler lag nicht in der Verarbeitung eines einzelnen
Resources, sondern in der Deployment-Topologie von Stage und Produktion.
Portainer erstellt beim normalen Stack-Deployment nur Dienste ohne aktiviertes
Compose-Profil. Die beiden für Assessments notwendigen Dienste `fuji` und
`assessment-queue` trugen in `docker-compose.stage.yml` und
`docker-compose.prod.yml` das Profil `assessment` und wurden deshalb nicht
erstellt.

Gleichzeitig ist die Anwendung mit `FUJI_ENABLED=true` und standardmäßig `FUJI_BASE_URL=http://fuji:1071` konfiguriert. Daraus entstehen die beiden beobachteten Symptome:

1. Der Webprozess kann den nicht vorhandenen Host `fuji` nicht auflösen beziehungsweise keine Verbindung aufbauen und zeigt den F-UJI-Fehler an.
2. Der Start eines persistenten Assessment-Laufs legt einen `PrepareAssessmentRunSnapshotJob` auf der dedizierten Datenbank-Queue `assessments` ab. Der normale Queue-Container konsumiert diese Queue nicht. Ohne `assessment-queue` bleibt der Lauf deshalb im Status `preparing` bei `0/0`.

Der Screenshot aus Portainer bestätigt die Diagnose: Im Stack laufen nur `app`, `db`, `queue`, `redis`, `scheduler` und `webserver`; `fuji` und `assessment-queue` fehlen vollständig.

## Technischer Hintergrund

- `AssessmentRunService::dispatch()` sendet Snapshot- und Dispatcher-Jobs explizit an die in `fuji.assessment` konfigurierte Queue-Verbindung und Queue.
- Die Standard-Queue-Worker von Stage und Produktion konsumieren `datacite`, `vocabularies`, `database-dumps` beziehungsweise `default` und `imports`, aber nicht `assessments`.
- Nur `assessment-queue` konsumiert `assessments`.
- `fuji` und `assessment-queue` besaßen vor dem Fix in beiden Runtime-Compose-Dateien `profiles: [assessment]`.
- Der Scheduler versucht aktive Läufe minütlich erneut zu dispatchen. Sobald die fehlenden Worker vorhanden sind, kann der bestehende persistente Lauf deshalb grundsätzlich ohne Neuanlage fortgesetzt werden.
- Docker Compose startet profilierte Dienste nur mit `--profile` oder `COMPOSE_PROFILES`; Dienste ohne Profil sind standardmäßig aktiv. Docker empfiehlt, Kerndienste einer Anwendung nicht hinter Profile zu legen: <https://docs.docker.com/compose/how-tos/profiles/>.
- Portainer führt Git-basierte Standalone-Stack-Updates als Compose-`up` ohne projektspezifisches `--profile` aus: <https://docs.portainer.io/faqs/troubleshooting/stacks-deployments-and-updates/how-do-automatic-updates-for-stacks-applications-work>.
- Eine dauerhafte Abhängigkeit von `COMPOSE_PROFILES` in Portainer ist nicht empfehlenswert, weil Portainer seit Version 2.24 einen bestätigten Fehler bei der Aktivierung von Compose-Profilen über Stack-Umgebungsvariablen dokumentiert: <https://github.com/portainer/portainer/issues/12416>.

## Zielbild

- Ein normales Portainer-Deployment von `docker-compose.stage.yml` beziehungsweise `docker-compose.prod.yml` erstellt F-UJI und die dedizierten Assessment-Worker automatisch.
- Die lokale Entwicklung behält ihre optionalen Profile `assessment` und `parity`; ein normaler lokaler Start wird nicht schwerer oder ressourcenintensiver.
- Die Anwendung bleibt unabhängig von der F-UJI-Gesundheit startfähig. Nur die Assessment-Worker warten über `depends_on` auf einen gesunden F-UJI-Dienst.
- Bereits persistierte Assessment-Läufe und deren Fortschritt werden nicht gelöscht. Nach dem Deployment werden sie durch den Scheduler beziehungsweise die vorhandenen Queue-Jobs wieder aufgenommen.

## Umgesetzte Änderungen

### 1. Stage- und Produktions-Topologie Portainer-kompatibel machen

In `docker-compose.stage.yml` und `docker-compose.prod.yml`:

- `profiles: [assessment]` vom Dienst `fuji` entfernen.
- `profiles: [assessment]` vom Dienst `assessment-queue` entfernen.
- Die bestehende Abhängigkeit von `assessment-queue` zu einem gesunden `fuji`, `app`, `db` und `redis` beibehalten.
- Die dedizierte Queue-Verbindung beibehalten und die konservative Replikazahl auf `${FUJI_ASSESSMENT_CONCURRENCY:-1}` setzen.
- `app` nicht von `fuji` abhängig machen. Ein Ausfall des optionalen externen Prüfdienstes darf die gesamte ERNIE-Anwendung nicht am Start hindern.
- Die Profile in `docker-compose.dev.yml` unverändert lassen, weil sie dort über die vorhandenen npm-Kommandos bewusst aktiviert werden.

Diese Änderung macht F-UJI und seine Worker zu festen Bestandteilen der Stage-/Produktions-Stacks. `FUJI_ENABLED` bleibt weiterhin der Anwendungsschalter für die Assessment-Funktion. Falls der Schalter `false` ist, werden keine neuen Läufe zugelassen; die Container bleiben jedoch Bestandteil der Runtime-Topologie. Dieser geringe Ressourcen-Trade-off ist robuster als ein Portainer-versionsabhängiger Profilmechanismus.

### 2. Deployment-Vertrag durch Tests absichern

`tests/pest/Unit/Deployment/AssessmentQueueDeploymentTest.php` erweitern:

- Für Stage und Produktion sicherstellen, dass `fuji` und `assessment-queue` kein `profiles`-Attribut besitzen.
- Für die lokale Compose-Datei sicherstellen, dass beide Dienste weiterhin den Profilen `assessment` und `parity` zugeordnet sind.
- Die vorhandenen Assertions für Queue-Name, persistente Queue-Verbindung, Replikazahl, Timeout, Redis-Cache und Limiter-Einstellungen beibehalten.
- Zusätzlich absichern, dass der normale Queue-Worker nicht versehentlich auch `assessments` konsumiert. Dadurch bleibt die für F-UJI konfigurierte Parallelitäts- und Rate-Limit-Grenze wirksam.

Optional kann ein gerenderter Compose-Smoke-Test ergänzt werden, sofern er in der bestehenden Docker-Testumgebung deterministisch ohne echte Secrets ausgeführt werden kann. Die YAML-Vertragstests bleiben die verbindliche und schnelle Regression-Abdeckung.

### 3. Deployment-Dokumentation korrigieren

`docs/production-runtime-performance.md` anpassen:

- Die Stage-/Produktionsbefehle mit `--profile assessment` entfernen.
- Dokumentieren, dass ein normales Portainer-Stack-Deployment beide Assessment-Dienste erstellt.
- `FUJI_ENABLED=true`, `FUJI_USERNAME` und `FUJI_PASSWORD` als notwendige Runtime-Konfiguration für nutzbare Assessments nennen.
- Den Unterschied klar festhalten: Profile sind nur noch eine lokale Entwicklungsfunktion; Stage und Produktion enthalten die Dienste immer.
- Einen kurzen Portainer-Rollout- und Diagnoseabschnitt mit den erwarteten Diensten, Health-Zuständen und zwei Assessment-Worker-Instanzen ergänzen.

`docs/local-development.md` nur dort präzisieren, wo die bestehende Formulierung den Eindruck erwecken könnte, dass das lokale Profil auch für Portainer-Deployments gilt. Die lokalen Startkommandos selbst bleiben unverändert.

`docs/pre-release-testing.md` um einen Runtime-Smoke-Test ergänzen:

- `fuji` ist vorhanden und gesund.
- Die konfigurierte Zahl `assessment-queue`-Worker läuft.
- `/assessment` meldet den Dienst nach Ablauf des maximal 30 Sekunden alten Health-Caches als verfügbar.
- Ein kleiner Stage-Lauf verlässt `preparing`, erhält einen Gesamtwert größer als null und erhöht anschließend `processed`.

### 4. Bestehenden Lauf nach dem Stage-Deployment kontrolliert fortsetzen

Nach dem Portainer-Update des Stage-Stacks:

1. Prüfen, dass `ernie-fuji-stage` gesund ist und zwei `assessment-queue`-Container laufen.
2. Bis zu 30 Sekunden für den gecachten F-UJI-Healthcheck und bis zu eine Minute für den Scheduler-Recovery-Zyklus einplanen.
3. Den bestehenden Lauf zunächst nicht löschen und nicht neu anlegen. Sein Snapshot ist persistent und die Vorbereitung ist idempotent.
4. Beobachten, dass der Lauf von `preparing` nach `queued`/`running` wechselt, `total` gesetzt wird und `processed` ansteigt.
5. Wegen der minütlichen Recovery-Versuche kann die Queue mehrere redundante Vorbereitungsjobs enthalten. Diese Jobs dürfen kontrolliert auslaufen; die Implementierung ignoriert sie, sobald der Lauf nicht mehr `preparing` ist. Keine Queue- oder Assessment-Daten manuell löschen.
6. F-UJI- und Worker-Logs auf Authentifizierungsfehler, HTTP 429, Timeouts, Neustarts oder Speicherprobleme prüfen.

Erst nach erfolgreichem Stage-Smoke-Test denselben Stack-Update und dieselben Prüfungen auf Produktion durchführen.

## Ergänzung nach dem Stage-Rollout: Deadlock bei parallelen Deferrals

Der Stage-Rollout hat einen zweiten, unabhängigen Fehler sichtbar gemacht. Zwei
Assessment-Worker erhielten nach jeweils rund 120 Sekunden einen wiederholbaren
F-UJI-Transportfehler. Beim Zurücksetzen eines Items von `processing` auf
`pending` kollidierte `AssessResourceRunItemJob::defer()` mit dem parallelen
Dispatcher. MySQL meldete einen Deadlock (`SQLSTATE[40001]`, Fehler 1213).

Da der Item-Worker bewusst mit `--tries=1` läuft, behandelte Laravel den nicht
abgefangenen Deadlock als endgültigen Jobfehler. Der `failed()`-Handler setzte
daraufhin alle offenen Daten korrekt auf einen fortsetzbaren Zustand, pausierte
aber den gesamten Lauf. Der beobachtete Endzustand war deshalb konsistent:
`paused`, 231 `pending` Items und keine Jobs in der Queue `assessments`.

Zusätzliche Umsetzung:

- `defer()` sperrt zuerst den zugehörigen Assessment-Lauf und aktualisiert danach
  das Item. Diese Reihenfolge entspricht dem Dispatcher und den terminalen
  Item-Updates.
- Datenbanktransaktionen des Item-Jobs erhalten drei Versuche, sodass MySQL-
  Deadlocks automatisch mit einer frischen Transaktion wiederholt werden.
- Auch der `failed()`-Handler verwendet die Reihenfolge Lauf vor Item. Dadurch
  erzeugt gerade die Fehlerbehandlung keinen neuen inversen Lock-Pfad.
- Ein Regressionstest zeichnet die SQL-Reihenfolge beim Zurückstellen eines
  transient fehlgeschlagenen Items auf und sichert `assessment_runs ... for
  update` vor dem Update von `assessment_run_items` ab.

Nach Auslieferung dieses Zusatzfixes wird der pausierte Stage-Lauf über
`Resume Resources` fortgesetzt. Zunächst bleibt nur ein Assessment-Worker aktiv,
um F-UJI auf dem Host mit einer sichtbaren CPU nicht gleichzeitig mit zwei
langlaufenden Prüfungen zu belasten. Die Parallelität bleibt über
`FUJI_ASSESSMENT_CONCURRENCY` konfigurierbar.

## Ergänzung nach dem Langzeittest: langsame F-UJI-Antworten

Der Stage-Lauf verarbeitete über Nacht nur 130 von 231 Resources. Von diesen
waren 85 als fehlgeschlagen markiert. Ein direkter Wiederholungstest einer
solchen Resource lieferte nach 69 Sekunden erfolgreich einen Score von 73,08.
Das gepinnte Container-Image wurde als F-UJI 3.5.1 verifiziert; ein vermutetes
Upgrade auf Version 4 ist daher nicht die Ursache.

Die Laufzeit ergibt sich aus dem bisherigen Fehlervertrag: Jeder vollständige
120-Sekunden-Timeout wurde bis zu dreimal wiederholt und anschließend wie ein
Resource-Fehler gezählt. Der zusätzliche Fix erhöht das HTTP-Fenster auf 300
Sekunden, startet standardmäßig nur einen Worker und wiederholt einen cURL-28-
Antwort-Timeout nach Verbrauch des vollständigen Fensters nicht unmittelbar.
Kurze DNS-/Verbindungsfehler sowie HTTP 429/5xx bleiben begrenzt wiederholbar.

Technische Servicefehler werden separat gezählt. Run-Items speichern
Fehlerklasse, Fehlercode, das bereinigte Ursprungsdetail und die Dauer des
letzten Versuchs. Nach Abschluss eines Laufs kann die Oberfläche ausschließlich
diese Servicefehler erneut einreihen, ohne erfolgreiche Assessments oder echte
Resource-Fehler zu wiederholen.

## Validierung

### Automatisiert

Focused Feedback Loop:

```bash
npm run test:php -- tests/pest/Unit/Deployment/AssessmentQueueDeploymentTest.php
npm run test:php -- tests/pest/Feature/Assessment/AssessmentRunTest.php
```

Abschließende Backend-Validierung gemäß Repository-Vorgabe:

```bash
npm run check:backend
```

Zusätzlich beide Runtime-Dateien rendern und prüfen, dass die Dienste ohne Profilaktivierung enthalten sind:

```bash
docker compose -f docker-compose.stage.yml config --services
docker compose -f docker-compose.prod.yml config --services
```

Die Ausgabe muss jeweils `fuji` und `assessment-queue` enthalten. Notwendige Geheimnisse werden dafür ausschließlich über sichere lokale beziehungsweise CI-Umgebungsvariablen bereitgestellt und nicht eingecheckt.

Ergebnisse des Deadlock-Fixes:

- Assessment-Featuretests unter SQLite: 30 bestanden, 169 Assertions.
- Assessment-Featuretests unter MySQL 9.7: 30 bestanden, 170 Assertions.
- Vollständiges `npm run check:backend`: Pest erfolgreich mit 14 bewusst
  übersprungenen Tests und 41.189 Assertions; PHPStan 958/958 ohne Fehler.

### Manuell auf Stage

- Portainer zeigt `fuji` als `healthy`.
- Portainer zeigt die konfigurierte Anzahl Assessment-Worker als `running`.
- Der Assessment-Health-Hinweis verschwindet spätestens nach Ablauf des Cachefensters.
- Der vorhandene Ressourcenlauf setzt `total` und verarbeitet mindestens ein Element.
- Page Reload, Abmelden und erneutes Öffnen von `/assessment` verlieren den Laufstatus nicht.
- Ein Testlauf lässt sich abbrechen und ein neuer Lauf danach starten.

### Manuell auf Produktion

- Dieselben Container- und Health-Prüfungen wie auf Stage.
- Den bestehenden Lauf beobachten, statt unmittelbar einen zweiten Lauf zu starten.
- Durchsatz, Fehlerrate, 429-Antworten sowie CPU- und Speichernutzung prüfen.

## Akzeptanzkriterien

- Ein Portainer-Redeploy der unveränderten Stage-/Produktions-Stack-Art erstellt `fuji` und `assessment-queue` ohne zusätzliche Profiloption.
- Der F-UJI-Healthcheck ist erfolgreich und der interne Host `fuji` ist aus `app` und `assessment-queue` erreichbar.
- Ein Assessment-Lauf bleibt nicht mehr mangels Queue-Consumer bei `preparing` und `0/0` stehen.
- Ein vor dem Fix begonnener persistenter Lauf kann ohne Datenverlust weiterlaufen.
- Stage und Produktion besitzen weiterhin die konfigurierte Anzahl dedizierter Assessment-Worker und den gemeinsamen Redis-Limiter.
- Der normale lokale Entwicklungsstart erstellt F-UJI und Assessment-Worker weiterhin nicht, solange kein lokales Assessment-/Parity-Profil aktiviert wurde.
- Dokumentation und Deployment-Tests bilden die tatsächlich über Portainer verwendete Topologie ab.

## Risiken und Gegenmaßnahmen

- **Zusätzlicher Grundverbrauch auf Stage/Produktion:** F-UJI wird künftig bei jedem Stack-Start erstellt. Das ist ein bewusster Trade-off, weil Assessment dort eine erwartete Anwendungsfunktion ist. CPU- und Speicherverbrauch werden zuerst auf Stage geprüft.
- **F-UJI ist beim Deployment unhealthy:** `assessment-queue` wartet auf den Healthcheck, aber `app` bleibt verfügbar. Damit beeinträchtigt ein F-UJI-Problem nicht den übrigen ERNIE-Betrieb.
- **Aufgestaute Recovery-Jobs:** Redundante Vorbereitungsjobs sind aufgrund der Statusprüfung idempotent. Die Queue wird beobachtet, aber nicht manuell bereinigt.
- **Konfigurationsfehler bei Zugangsdaten:** Stage wird vor Produktion validiert; Secrets erscheinen weder in Git noch in Diagnoseausgaben.
- **Portainer-Verhaltensänderungen:** Der Fix hängt nicht länger von Profilaktivierung oder einer bestimmten Portainer-Version ab.

## Nicht Bestandteil dieses Fixes

- Änderungen am F-UJI-Scoring oder an der Auswahl der Resources/IGSNs.
- Änderung des persistenten Queue-Treibers oder der
  F-UJI-HTTP-/Rate-Limit-Strategie.
- Manuelles Löschen bestehender Runs, Run-Items, Queue-Jobs oder Assessment-Ergebnisse.
- Ein neuer Worker-Heartbeat oder eine allgemeine Queue-Monitoring-Funktion. Das kann separat geplant werden, falls die UI künftig explizit zwischen „F-UJI nicht erreichbar“ und „Assessment-Worker fehlt“ unterscheiden soll.
