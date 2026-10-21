#!/bin/bash
# Issue #480 — every public/.htaccess Pinakes ever shipped, on a real Apache, before and after
# the 0.8.6 heal (App\Support\PrivateUploadsRewriteHeal).
#
# For each file in tests/fixtures/htaccess-history/ a throwaway Apache serves a site whose
# uploads/digital, uploads/archives/documents and uploads/copertine hold real files and whose
# index.php is a plain marker, at the domain root and in a subfolder. After the heal:
#   - every private upload (digital, archive documents, the /uploads/Digital/ spelling) must
#     reach index.php, never the file on disk;
#   - a public upload (a cover) must still be served as a static file.
# "before" is printed for information: every format older than 0.8.6 leaks somewhere.
#
# Needs httpd with mod_rewrite (Homebrew: /opt/homebrew/bin/httpd). Uses port 18480.
# Run:  bash tests/htaccess-heal-apache-480.test.sh
set -u
REPO="$(cd "$(dirname "$0")/.." && pwd)"
HTTPD="${HTTPD:-$(command -v httpd || true)}"
MAIN_CONF="${HTTPD_MAIN_CONF:-/opt/homebrew/etc/httpd/httpd.conf}"
PORT="${PORT:-18480}"
if [ -z "$HTTPD" ] || [ ! -f "$MAIN_CONF" ]; then
    echo "SKIP: no httpd / $MAIN_CONF (set HTTPD and HTTPD_MAIN_CONF)"
    exit 0
fi
W="$(mktemp -d)"
trap '"$HTTPD" -f "$W/httpd.conf" -k stop >/dev/null 2>&1; rm -rf "$W"' EXIT
grep -E "^LoadModule" "$MAIN_CONF" > "$W/modules.conf"
SERVER_ROOT="$("$HTTPD" -V 2>/dev/null | sed -n 's/.*HTTPD_ROOT="\(.*\)"/\1/p')"
MIME="$(dirname "$MAIN_CONF")/mime.types"

site() { # $1 docroot, $2 .htaccess
    mkdir -p "$1/uploads/digital" "$1/uploads/archives/documents" "$1/uploads/copertine"
    printf 'SECRET-DIGITAL' > "$1/uploads/digital/secret.pdf"
    printf 'SECRET-ARCHIVE' > "$1/uploads/archives/documents/doc.pdf"
    printf 'PUBLIC-COVER' > "$1/uploads/copertine/a.jpg"
    printf 'ROUTED-TO-INDEX' > "$1/index.php"
    cp "$2" "$1/.htaccess"
}
probe() { # $1 path -> LEAK | php | static | httpNNN
    local body code
    body="$(curl -s "http://127.0.0.1:$PORT$1")"
    code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT$1")"
    case "$body" in
        SECRET-*) echo LEAK ;;
        ROUTED-TO-INDEX) echo php ;;
        PUBLIC-COVER) echo static ;;
        *) echo "http$code" ;;
    esac
}

PRIVATE=(/uploads/digital/secret.pdf /uploads/Digital/secret.pdf /uploads/archives/documents/doc.pdf
         /sub/uploads/digital/secret.pdf /sub/uploads/Digital/secret.pdf /sub/uploads/archives/documents/doc.pdf)
PUBLIC=(/uploads/copertine/a.jpg /sub/uploads/copertine/a.jpg)
fail=0
checks=0
for f in "$REPO"/tests/fixtures/htaccess-history/*.htaccess; do
    v="$(basename "$f" .htaccess)"
    php -r 'require $argv[1] . "/vendor/autoload.php"; echo App\Support\PrivateUploadsRewriteHeal::apply(file_get_contents($argv[2]));' "$REPO" "$f" > "$W/healed-$v"
    for state in before after; do
        src="$f"; [ "$state" = after ] && src="$W/healed-$v"
        D="$W/site-$v-$state"; site "$D" "$src"; site "$D/sub" "$src"
        cat > "$W/httpd.conf" <<EOF
ServerRoot "$SERVER_ROOT"
Listen 127.0.0.1:$PORT
Include $W/modules.conf
PidFile $W/httpd.pid
ErrorLog $W/error.log
ServerName localhost
TypesConfig $MIME
DocumentRoot "$D"
<Directory "$D">
    AllowOverride All
    Require all granted
</Directory>
EOF
        "$HTTPD" -f "$W/httpd.conf" -k start || { echo "FAIL $v: httpd did not start"; exit 1; }
        sleep 0.6
        line=""
        for p in "${PRIVATE[@]}"; do
            r="$(probe "$p")"; line="$line $r"
            if [ "$state" = after ]; then
                checks=$((checks + 1))
                # On a case-sensitive disk /uploads/Digital/ is simply another, missing path.
                if [ "$r" != php ] && ! { [[ "$p" == *Digital* ]] && [ "$r" = http404 ]; }; then
                    echo "  FAIL $v after heal: $p -> $r"; fail=$((fail + 1))
                fi
            fi
        done
        for p in "${PUBLIC[@]}"; do
            r="$(probe "$p")"; line="$line $r"
            if [ "$state" = after ]; then
                checks=$((checks + 1))
                [ "$r" = static ] || { echo "  FAIL $v after heal: $p -> $r (public file not served)"; fail=$((fail + 1)); }
            fi
        done
        printf '  %-12s %-6s%s\n' "$v" "$state" "$line"
        "$HTTPD" -f "$W/httpd.conf" -k stop >/dev/null 2>&1
        sleep 0.6
    done
done
echo
if [ "$fail" -eq 0 ]; then
    echo "ALL $checks PASS (every shipped .htaccess, after the heal, on a real Apache)"
else
    echo "$fail FAILED of $checks"
fi
exit $((fail > 0))
