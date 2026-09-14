#!/usr/bin/env bash
# Legt eine frische WordPress-Installation mit SQLite fuer die Integrationstests
# an (tests-integration/, Sicherheits-Review 2026-09-14, Phase 5.2) - nie die
# eigene Testumgebung, denn die Tests speichern Einstellungen und rufen
# uninstall.php auf.
#
#   bin/integration-setup.sh <verzeichnis>
#
# Umgebung:
#   WP_CLI              Aufruf von WP-CLI (Standard: wp), z. B. "php wp-cli.phar"
#   SQLITE_PLUGIN_SRC   vorhandenes sqlite-database-integration-Verzeichnis statt Download
set -euo pipefail

TARGET=${1:?Zielverzeichnis fehlt}
WP_CLI=${WP_CLI:-wp}
PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd)

wp() {
    # shellcheck disable=SC2086 # WP_CLI darf aus mehreren Woertern bestehen
    $WP_CLI --path="$TARGET" "$@"
}

rm -rf "$TARGET"
mkdir -p "$TARGET"

wp core download --skip-content --force

mkdir -p "$TARGET/wp-content/plugins" "$TARGET/wp-content/database"

if [ -n "${SQLITE_PLUGIN_SRC:-}" ]; then
    cp -R "$SQLITE_PLUGIN_SRC" "$TARGET/wp-content/plugins/sqlite-database-integration"
else
    curl -fsSL -o "$TARGET/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
    unzip -q "$TARGET/sqlite.zip" -d "$TARGET/wp-content/plugins"
    rm "$TARGET/sqlite.zip"
fi

cp "$TARGET/wp-content/plugins/sqlite-database-integration/db.copy" "$TARGET/wp-content/db.php"

wp config create --dbname=wordpress --dbuser=unused --dbpass=unused --skip-check --force \
    --extra-php <<'PHP'
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', 'integration.sqlite' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
PHP

wp core install --url=http://example.test --title=Integration --admin_user=admin \
    --admin_password="$(php -r 'echo bin2hex(random_bytes(12));')" --admin_email=admin@example.test --skip-email

ln -s "$PLUGIN_DIR" "$TARGET/wp-content/plugins/churchtools-plugin"
wp plugin activate churchtools-plugin

echo "WordPress fuer Integrationstests liegt in $TARGET"
