@echo off
setlocal
docker compose --env-file .env.docker -f docker-compose.dev.yml exec -T app php %*
