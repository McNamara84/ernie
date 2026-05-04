#!/bin/sh

set -eu

if [ -z "${FUJI_USERNAME:-}" ] || [ -z "${FUJI_PASSWORD:-}" ]; then
    echo "[F-UJI] FUJI_USERNAME and FUJI_PASSWORD must be set." >&2
    exit 1
fi

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