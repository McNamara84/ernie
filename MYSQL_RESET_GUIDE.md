# MySQL Volume Reset für ERNIE

## Problem
Das MySQL-Volume wurde bereits mit anderen Credentials initialisiert und ignoriert daher die neuen Environment-Variablen.

## Lösung: Volume zurücksetzen

### Option 1: Über Portainer UI
1. **Stack stoppen**
2. **Gehe zu "Volumes"** in Portainer
3. **Finde Volume**: `ernie_dockerized-laravel-data` 
4. **Volume löschen** (⚠️ Vorsicht: Alle DB-Daten gehen verloren!)
5. **Stack neu starten**

### Option 2: Über Kommandozeile
```bash
# Stack stoppen (ersetze "ernie" mit deinem Stack-Namen)
docker-compose down

# Volume löschen
docker volume rm ernie_dockerized-laravel-data

# Stack neu starten
docker-compose up -d
```

### Option 3: Volume-Name in docker-compose ändern (Empfohlen)
Ändere in der docker-compose.prod.yml:

```yaml
volumes:
  dockerized-laravel-data-v2:  # <- Neuer Name
    driver: local
```

Und im db-Service:
```yaml
volumes:
  - dockerized-laravel-data-v2:/var/lib/mysql  # <- Neuer Name
```

## Nach dem Reset
- Neues Volume wird mit korrekten Credentials initialisiert
- Init-Script (`init.sql`) wird automatisch ausgeführt  
- Laravel-User wird korrekt erstellt
- Migration sollte funktionieren

## Verifikation
Nach dem Start sollte der Health-Check des DB-Containers erfolgreich sein und die Migration automatisch laufen.