#!/bin/sh
# Replicate the public content of https://summit.openzfs.org into the
# a WordPress site in a Bastille jail. Re-runnable / idempotent.
#
#   tools/fetch-source.sh                    # (optional) refresh source-snapshot/export
#   HOST=<jail host> tools/import-content.sh # install plugins + import content
#
# HOST (the machine running the jail, reached over ssh with doas) is required.
# Optional: JAIL, WPUSER.
set -eu

: "${HOST:?set HOST to the machine that runs the jail}"
JAIL=${JAIL:-zfssummit}
WPUSER=${WPUSER:-admin}
WPPATH=/usr/local/www/zfssummit
JAILROOT=/usr/local/bastille/jails/$JAIL/root
STAGE=/var/tmp/zfsimport

HERE=$(cd "$(dirname "$0")" && pwd)
EXPORT="$HERE/../source-snapshot/export"
[ -f "$EXPORT/pages.json" ] || "$HERE/fetch-source.sh"

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
  if [ "\$act" = 1 ]; then wp plugin activate \$slug || true; fi
done
wp plugin list --fields=name,status,version
EOF

echo "==> content"
ssh "$HOST" "doas jexec $JAIL su -m www -c 'env HOME=/tmp ZFS_IMPORT_FORCE=${ZFS_IMPORT_FORCE:-0} /usr/local/bin/wp --path=$WPPATH eval-file $STAGE/import-content.php $STAGE/export --user=$WPUSER'"
ssh "$HOST" "doas jexec $JAIL su -m www -c 'env HOME=/tmp /usr/local/bin/wp --path=$WPPATH cache flush'" || true
echo "==> done"
