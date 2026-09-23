#!/bin/sh
# Replicate the public content of https://summit.openzfs.org into the
# a WordPress site in a Bastille jail. Re-runnable / idempotent.
#
#   tools/fetch-source.sh                    # (optional) refresh source-snapshot/export
#   HOST=<jail host> tools/import-content.sh # install plugins + import content
#
# HOST (the machine running the jail, reached over ssh with doas) is required.
# Optional: JAIL, WPUSER, WPPATH (WordPress path inside the jail),
# ZFS_IMPORT_FORCE=1 (overwrite pages edited since the last import, re-apply
# one-time site setup, and proceed despite missing/unrewritable media).
set -eu

die() { echo "import-content.sh: $*" >&2; exit 1; }

: "${HOST:?set HOST to the machine that runs the jail}"
: "${JAIL:?set JAIL to the Bastille jail name}"
WPUSER=${WPUSER:-admin}
WPPATH=${WPPATH:-/usr/local/www/wordpress}
ZFS_IMPORT_FORCE=${ZFS_IMPORT_FORCE:-0}

# These values are interpolated into ssh/doas/su command strings: allow only
# plain names and absolute paths. grep matches per line, so a value holding a
# newline is refused first.
NL='
'
for v in "$HOST" "$JAIL" "$WPUSER" "$WPPATH" "$ZFS_IMPORT_FORCE"; do
  case "$v" in *"$NL"*) die "values must not contain a newline" ;; esac
done
printf '%s' "$HOST" | grep -Eqx '[A-Za-z0-9][A-Za-z0-9._@-]*' || die "invalid HOST: $HOST (expected an ssh host name: letters, digits, . _ @ -, not starting with -)"
printf '%s' "$JAIL" | grep -Eqx '[A-Za-z0-9][A-Za-z0-9_.-]*' || die "invalid JAIL: $JAIL (expected letters, digits, . _ -, not starting with -)"
printf '%s' "$WPUSER" | grep -Eqx '[A-Za-z0-9][A-Za-z0-9_.-]*' || die "invalid WPUSER: $WPUSER (expected letters, digits, . _ -, not starting with -)"
printf '%s' "$WPPATH" | grep -Eqx '/[A-Za-z0-9_./-]+' || die "invalid WPPATH: $WPPATH (expected an absolute path)"
case "$WPPATH" in *..*) die "WPPATH must not contain '..': $WPPATH" ;; esac
case "$ZFS_IMPORT_FORCE" in 0|1) ;; *) die "ZFS_IMPORT_FORCE must be 0 or 1" ;; esac

JAILROOT=/usr/local/bastille/jails/$JAIL/root
STAGE=/var/tmp/zfsimport

HERE=$(cd "$(dirname "$0")" && pwd)
EXPORT="$HERE/../source-snapshot/export"
# Fetch when there is no complete export of the current layout.
grep -qs '^layout=2 ' "$EXPORT/export.ok" || "$HERE/fetch-source.sh"
grep -qs '^layout=2 ' "$EXPORT/export.ok" || die "fetch-source.sh did not complete; no import run"


if [ -s "$EXPORT/media-dead.txt" ]; then
  echo "WARNING: the source site itself returns 404/410 for these media URLs;" >&2
  echo "         they cannot be imported. Continuing: no imported page is expected" >&2
  echo "         to use them, and the import stops if one does:" >&2
  sed 's/^/  /' "$EXPORT/media-dead.txt" >&2
fi
if [ -s "$EXPORT/media-missing.txt" ] && [ "$ZFS_IMPORT_FORCE" != 1 ]; then
  echo "import-content.sh: these source media URLs failed to download:" >&2
  sed 's/^/  /' "$EXPORT/media-missing.txt" >&2
  die "refusing to import with missing media (re-run fetch-source.sh, or set ZFS_IMPORT_FORCE=1)"
fi

# Plugins: same versions as the source site (see report / readme probes).
# slug:version:activate(1/0)
PLUGINS="
woocommerce:11.1.2:1
my-calendar:3.8.6:1
easy-product-bundles-for-woocommerce:6.21.0:1
woo-extra-product-options:3.3.8:1
captcha-for-contact-form-7:2.15.9:1
raratheme-companion:1.4.4:1
woocommerce-gateway-stripe:10.9.1:0
printful-shipping-for-woocommerce:2.2.12:0
"

echo "==> staging export + helper into jail:$STAGE"
ssh "$HOST" "doas rm -rf $JAILROOT$STAGE && doas mkdir -p $JAILROOT$STAGE/export"
tar -C "$EXPORT" -cf - . | ssh "$HOST" "doas tar -C $JAILROOT$STAGE/export -xf -"
ssh "$HOST" "doas tee $JAILROOT$STAGE/import-content.php >/dev/null" < "$HERE/import-content.php"
ssh "$HOST" "doas chown -R 80:80 $JAILROOT$STAGE"

echo "==> plugins"
ssh "$HOST" "doas jexec $JAIL sh -s" <<EOF
set -e
wp() { su -m www -c "env HOME=/tmp /usr/local/bin/wp --path=$WPPATH \$*"; }
for spec in $(echo $PLUGINS); do
  slug=\${spec%%:*}; rest=\${spec#*:}; ver=\${rest%%:*}; act=\${rest#*:}
  cur=\$(wp plugin get \$slug --field=version 2>/dev/null || true)
  if [ "\$cur" != "\$ver" ]; then
    wp plugin install \$slug --version=\$ver --force
  fi
  if [ "\$act" = 1 ] && ! wp plugin is-active \$slug; then
    wp plugin activate \$slug
  fi
done
wp plugin list --fields=name,status,version
EOF

echo "==> content"
ssh "$HOST" "doas jexec $JAIL su -m www -c 'env HOME=/tmp ZFS_IMPORT_FORCE=$ZFS_IMPORT_FORCE /usr/local/bin/wp --path=$WPPATH eval-file $STAGE/import-content.php $STAGE/export --user=$WPUSER'"
ssh "$HOST" "doas jexec $JAIL su -m www -c 'env HOME=/tmp /usr/local/bin/wp --path=$WPPATH cache flush'" || true
echo "==> done"
