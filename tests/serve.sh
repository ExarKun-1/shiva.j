#!/bin/sh
# Složi probnu stranicu iz repozitorija i ispitnih podataka (tests/fixtures) i pokreni PHP poslužitelj.
#   sh tests/serve.sh [mapa]        (zadano: /tmp/shivaj-test; poslužitelj na http://127.0.0.1:8090/)
# Samo složi, bez pokretanja:  SHIVAJ_NO_SERVE=1 sh tests/serve.sh
set -e
R=$(cd "$(dirname "$0")/.." && pwd)
S=${1:-${SHIVAJ_SITE:-/tmp/shivaj-test}}
rm -rf "$S" && mkdir -p "$S/admin" "$S/api" "$S/data" "$S/js" "$S/img"
cp "$R/index.html" "$R/router.php" "$S/"
cp "$R/admin/index.php" "$R/admin/lib.php" "$S/admin/"
cp "$R/api/index.php" "$S/api/"
cp "$R/js/"*.js "$S/js/"
cp "$R/img/"*.jpg "$R/img/"*.png "$S/img/"
cp "$R/tests/fixtures/torbe.json" "$S/torbe.json"
cp "$R/tests/fixtures/proizvodi.json" "$R/tests/fixtures/config.json" "$S/data/"
echo "probna stranica: $S"
[ -n "$SHIVAJ_NO_SERVE" ] && exit 0
exec php -S 127.0.0.1:8090 -t "$S" "$S/router.php"
