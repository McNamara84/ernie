#!/bin/sh

set -eu

fuji_enabled=$(printf '%s' "${FUJI_ENABLED:-true}" | tr '[:upper:]' '[:lower:]')

if [ "$fuji_enabled" = "false" ] || [ "$fuji_enabled" = "0" ] || [ "$fuji_enabled" = "no" ] || [ "$fuji_enabled" = "off" ]; then
    echo "[F-UJI] Disabled via FUJI_ENABLED; starting health stub." >&2

    exec python3 <<'PY'
from http.server import BaseHTTPRequestHandler, HTTPServer


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == '/fuji/api/v1/ui/':
            self.send_response(200)
            self.send_header('Content-Type', 'text/plain; charset=utf-8')
            self.end_headers()
            self.wfile.write(b'F-UJI disabled')
            return

        self.send_response(404)
        self.end_headers()

    def log_message(self, format, *args):
        return


HTTPServer(('0.0.0.0', 1071), Handler).serve_forever()
PY
fi

if [ -z "${FUJI_USERNAME:-}" ] || [ -z "${FUJI_PASSWORD:-}" ]; then
    echo "[F-UJI] FUJI_USERNAME and FUJI_PASSWORD must be set." >&2
    exit 1
fi

tika_log_path="${TIKA_LOG_PATH:-/tmp/tika}"
mkdir -p "$tika_log_path"
export TIKA_LOG_PATH="$tika_log_path"

python3 <<'PY'
import json
import os
from pathlib import Path

user = os.environ['FUJI_USERNAME']
password = os.environ['FUJI_PASSWORD']

content = "# user dictionary: key = username value = password\nfuji_users = " + json.dumps({user: password}) + "\n"
Path('/usr/src/app/fuji_server/config/users.py').write_text(content, encoding='utf-8')
PY

exec python3 -m fuji_server -c /usr/src/app/fuji_server/config/server.ini