#!/bin/sh
# Build a WordPress test site for this theme inside an existing Bastille jail.
# Runs from a checkout of this repository; reaches the jail's host over ssh
# and uses doas there.
#
#   HOST=<jail host> JAIL=<jail> FQDN=<site name> ADMIN_EMAIL=<you> \
#       tools/provision-jail.sh
#
# Optional: WPPATH (/usr/local/www/wordpress), ADMIN_USER (admin),
# THEME_REPO (the published repository), THEME_REF (main).
#
# Steps: install packages, run tools/provision/setup.sh inside the jail, then
# clone the theme from THEME_REPO into the site and activate it. Safe to
# re-run: an existing theme checkout is moved to THEME_REF (a branch, tag or
# commit) as fetched from THEME_REPO.
set -eu

: "${HOST:?set HOST to the machine that runs the jail}"
: "${JAIL:?set JAIL to the Bastille jail name}"
: "${FQDN:?set FQDN to the site host name}"
: "${ADMIN_EMAIL:?set ADMIN_EMAIL}"
WPPATH=${WPPATH:-/usr/local/www/wordpress}
ADMIN_USER=${ADMIN_USER:-admin}
THEME_REPO=${THEME_REPO:-https://github.com/revytechinc/openzfs-summit-theme.git}
THEME_REF=${THEME_REF:-main}

# These values are pasted into remote shell strings: plain values only.
check() { # name value extended-regex
	case "$2" in *"
"*) echo "provision-jail.sh: bad $1: contains a newline" >&2; exit 2 ;; esac
	printf '%s' "$2" | grep -Eq "^($3)\$" || { echo "provision-jail.sh: bad $1: '$2' (must match: $3)" >&2; exit 2; }
}
check HOST "$HOST" '[A-Za-z0-9][A-Za-z0-9._@-]*'
check JAIL "$JAIL" '[A-Za-z0-9][A-Za-z0-9_.-]*'
check FQDN "$FQDN" '[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?'
check ADMIN_EMAIL "$ADMIN_EMAIL" '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+'
check WPPATH "$WPPATH" '/[A-Za-z0-9_/.-]+'
case "$WPPATH" in *..*) echo "provision-jail.sh: bad WPPATH: '$WPPATH'" >&2; exit 2 ;; esac
check ADMIN_USER "$ADMIN_USER" '[A-Za-z0-9][A-Za-z0-9_.-]*'
check THEME_REPO "$THEME_REPO" 'https://[A-Za-z0-9._/-]+'
check THEME_REF "$THEME_REF" '[A-Za-z0-9][A-Za-z0-9._/-]*'

HERE=$(cd "$(dirname "$0")" && pwd)
PROV="$HERE/provision"
THEMES="$WPPATH/wp-content/themes"

echo "==> packages"
# No pipe to tail/grep here: that would report the pipe's status, not pkg's.
ssh "$HOST" "doas jexec $JAIL pkg install -qy $(tr '\n' ' ' < "$PROV/packages.txt")"

echo "==> WordPress, PHP, MariaDB, nginx"
tar -C "$PROV" -cf - setup.sh nginx.conf.in |
	ssh "$HOST" "doas jexec $JAIL sh -c 'rm -rf /root/provision && mkdir -p /root/provision && tar -C /root/provision -xf -'"
ssh "$HOST" "doas jexec $JAIL env FQDN=$FQDN ADMIN_EMAIL=$ADMIN_EMAIL WPPATH=$WPPATH ADMIN_USER=$ADMIN_USER sh /root/provision/setup.sh"

echo "==> theme from $THEME_REPO ($THEME_REF)"
ssh "$HOST" "doas jexec $JAIL su -m www -c '
set -e
export HOME=/tmp
cd $THEMES
[ -d openzfs-summit/.git ] || git clone -q $THEME_REPO openzfs-summit
git -C openzfs-summit remote set-url origin $THEME_REPO
git -C openzfs-summit fetch -q origin $THEME_REF
git -C openzfs-summit checkout -q --detach FETCH_HEAD
git -C openzfs-summit log -1 --format=\"theme at %h %s\"
wp --path=$WPPATH theme activate openzfs-summit
'"
echo "==> done. Next: tools/import-content.sh, then compose-pages.php (see README)."
