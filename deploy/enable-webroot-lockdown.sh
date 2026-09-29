#!/usr/bin/env bash
#
# Installs the Social Hub web-root lockdown into the Apache default site.
# Idempotent: re-running it updates the block instead of duplicating it.
#
#   sudo bash /var/www/html/PHP_projects/social-hub/deploy/enable-webroot-lockdown.sh
#
set -euo pipefail

PROJECT_DIR="/var/www/html/PHP_projects/social-hub"
SOURCE_CONF="$PROJECT_DIR/deploy/apache/socialhub-directory.conf"
SITE_CONF="/etc/apache2/sites-available/000-default.conf"
MARKER="social-hub-webroot-lockdown"

if [[ $EUID -ne 0 ]]; then
  echo "This script needs root. Re-run with: sudo bash $0" >&2
  exit 1
fi

if [[ ! -r "$SOURCE_CONF" ]]; then
  echo "Cannot read $SOURCE_CONF" >&2
  exit 1
fi

if [[ ! -f "$SITE_CONF" ]]; then
  echo "Cannot find $SITE_CONF (is this a Debian/Ubuntu Apache?)" >&2
  exit 1
fi

BACKUP="${SITE_CONF}.$(date +%Y%m%d-%H%M%S).bak"
cp -a "$SITE_CONF" "$BACKUP"
echo "Backed up $SITE_CONF -> $BACKUP"

# Remove a previous copy of the block, then append the current one.
python3 - "$SITE_CONF" "$SOURCE_CONF" "$MARKER" <<'PY'
import sys
site, source, marker = sys.argv[1], sys.argv[2], sys.argv[3]
text = open(site).read()
start = text.find('# BEGIN ' + marker)
if start != -1:
    end = text.find('# END ' + marker)
    if end == -1:
        sys.exit('Found a start marker without an end marker; fix ' + site + ' by hand')
    end = text.index('\n', end) + 1
    text = text[:start] + text[end:]
block = ('\n# BEGIN ' + marker + '\n'
         + open(source).read().rstrip() + '\n'
         + '# END ' + marker + '\n')
open(site, 'w').write(text.rstrip() + '\n' + block)
print('Block written to ' + site)
PY

echo "Testing configuration..."
if ! apache2ctl configtest; then
  echo "Config test failed - restoring backup" >&2
  cp -a "$BACKUP" "$SITE_CONF"
  apache2ctl configtest && systemctl reload apache2
  echo "Restored the previous configuration." >&2
  exit 1
fi

systemctl reload apache2
echo "Apache reloaded."

cat <<'EOF'

Verify the lockdown (everything except public/ should be 403/404):

  for p in .env config/secrets.php config/database.php storage/logs/app-log.php \
           database/schema.sql app/Helpers/Auth.php README.md frontend/package.json; do
    printf "%-32s %s\n" "$p" \
      "$(curl -s -o /dev/null -w '%{http_code}' http://localhost/PHP_projects/social-hub/$p)"
  done
  curl -s -o /dev/null -w 'public/  -> %{http_code}\n' http://localhost/PHP_projects/social-hub/public/
EOF
