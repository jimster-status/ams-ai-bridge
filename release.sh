#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

plugin=ams-ai-bridge/ams-ai-bridge.php
version=$(sed -n 's/^ \* Version: //p' "$plugin" | tr -d '\r')
constant=$(sed -n "s/^    const VERSION = '\(.*\)';.*/\1/p" "$plugin" | tr -d '\r')
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ && "$version" == "$constant" ]] ||
    { echo 'Plugin header and version constant must match.' >&2; exit 1; }
php -l "$plugin"
php -l ams-ai-bridge/updater.php
python3 - "$version" "${1:-Update}" <<'PY'
import hashlib
import html
import json
import sys
from datetime import date
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

version, note = sys.argv[1:]
package = Path(f'releases/ams-ai-bridge-{version}.zip')
manifest = Path('update.json')
previous = json.loads(manifest.read_text(encoding='utf-8')) if manifest.exists() else {}
package.parent.mkdir(exist_ok=True)
with ZipFile(package, 'x', ZIP_DEFLATED) as archive:
    for path in sorted(Path('ams-ai-bridge').glob('*.php')):
        archive.write(path, path.as_posix())

today = date.today().isoformat()
log = [{'version': version, 'date': today, 'note': note}, *previous.get('changelog', [])][:20]
data = {
    'version': version,
    'download_url': 'https://github.com/jimster-status/ams-ai-bridge/raw/main/' + package.as_posix(),
    'sha256': hashlib.sha256(package.read_bytes()).hexdigest(),
    'released': today,
    'requires_php': '7.4',
    'changelog': log,
    'changelog_html': ''.join(
        f'<h4>{html.escape(entry["version"])} ({html.escape(entry["date"])})</h4>'
        f'<p>{html.escape(entry["note"])}</p>' for entry in log
    ),
}
manifest.write_text(json.dumps(data, indent=2) + '\n', encoding='utf-8')
print(f'Released {version} at {package}')
PY
