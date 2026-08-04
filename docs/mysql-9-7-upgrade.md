# MySQL 9.7 Upgrade

MySQL does not support opening an 8.0 data directory directly with 9.7. The supported in-place route is MySQL 8.0 → MySQL 8.4 LTS → MySQL 9.7 LTS. Both intermediate images in this repository are pinned to immutable multi-platform manifest digests.

The upgrade changes the data directory and cannot be downgraded in place. Create and verify a backup first. Do not use `docker compose down -v` anywhere in this procedure.

## Local Development

Set a path outside the repository for the backup, then start only the database with the pinned MySQL 8.0 recovery image:

```bash
export ERNIE_MYSQL_BACKUP_PATH="$PWD/../ernie-pre-mysql-9.sql"
docker compose --env-file .env.docker \
  -f docker-compose.dev.yml \
  -f docker-compose.mysql-8.0-backup.yml \
  up -d --wait db
docker compose --env-file .env.docker \
  -f docker-compose.dev.yml \
  -f docker-compose.mysql-8.0-backup.yml \
  exec -T -e MYSQL_PWD=rootsecret db \
  mysqldump -uroot --all-databases --single-transaction --routines --events --triggers \
  > "$ERNIE_MYSQL_BACKUP_PATH"
test -s "$ERNIE_MYSQL_BACKUP_PATH"
```

Keep that backup until the application has been verified on 9.7. Stop the 8.0 server without removing its volume:

```bash
docker compose --env-file .env.docker \
  -f docker-compose.dev.yml \
  -f docker-compose.mysql-8.0-backup.yml \
  down
```

Start the required 8.4 LTS intermediate release against the same volume:

```bash
docker compose --env-file .env.docker \
  -f docker-compose.dev.yml \
  -f docker-compose.mysql-8.4-upgrade.yml \
  up -d --wait db
docker compose --env-file .env.docker \
  -f docker-compose.dev.yml \
  -f docker-compose.mysql-8.4-upgrade.yml \
  exec -T -e MYSQL_PWD=rootsecret db mysql -uroot -Nse 'SELECT VERSION();'
docker compose --env-file .env.docker \
  -f docker-compose.dev.yml \
  -f docker-compose.mysql-8.4-upgrade.yml \
  logs --no-log-prefix db
```

The version command must report 8.4.x and the logs must show a clean, ready server. Stop it without `-v`, then start the normal 9.7 stack:

```bash
docker compose --env-file .env.docker \
  -f docker-compose.dev.yml \
  -f docker-compose.mysql-8.4-upgrade.yml \
  down
npm run docker:dev:up:d
docker compose --env-file .env.docker -f docker-compose.dev.yml \
  exec -T -e MYSQL_PWD=rootsecret db mysql -uroot -Nse 'SELECT VERSION();'
npm run artisan -- migrate:status
npm run test:php:mysql-sensitive
```

The final version command must report 9.7.x. Once the application, migrations, and MySQL-sensitive tests pass, retain the backup according to the project's normal retention policy.

## Stage And Production

Do not perform an untested in-place production upgrade. First run MySQL Shell's Upgrade Checker against a restored copy of the backup, rehearse both LTS transitions, and verify the application there. During the real maintenance window:

1. Stop all writers, including the web application and queue workers.
2. Take and verify both logical and infrastructure-level backups.
3. Apply `docker-compose.mysql-8.4-upgrade.yml` together with the environment's normal Compose file and wait for a clean 8.4 startup.
4. Stop 8.4 without removing the volume, then start the normal Compose file on 9.7.
5. Verify the server version, migrations, queue health, application smoke tests, and logs before reopening traffic.

If either step fails, do not start an older server against the upgraded data directory. Recreate an empty volume and restore the pre-upgrade backup instead.

References:

- [MySQL 9.7 supported upgrade paths](https://dev.mysql.com/doc/refman/9.7/en/upgrade-paths.html)
- [MySQL 8.4 pre-upgrade backup guidance](https://dev.mysql.com/doc/refman/8.4/en/upgrade-before-you-begin.html)
