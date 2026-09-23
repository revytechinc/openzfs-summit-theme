#!/bin/sh
# Provision WordPress inside a FreeBSD jail: MariaDB, PHP-FPM 8.4, nginx
# (Cloudflare-aware) and WordPress core pinned to a version. Run as root
# INSIDE the jail, from a directory holding this script and nginx.conf.in.
# Packages come first: pkg install -y $(cat packages.txt)
#
#   FQDN=summit.example.org ADMIN_EMAIL=you@example.org sh setup.sh
#
# Optional: WPPATH (/usr/local/www/wordpress), WP_VERSION (7.1.2),
# ADMIN_USER (admin), SITE_TITLE ("OpenZFS Developer Summit").
#
# Safe to re-run: every step checks what is already there. Generated secrets
# stay in root-only files and are never printed or passed on a command line
# (wp-cli reads them from stdin): /root/.wpdb (database password) and
# /root/.wpadmin (initial admin password).
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
: "${FQDN:?set FQDN to the site's host name}"
: "${ADMIN_EMAIL:?set ADMIN_EMAIL}"
WPPATH=${WPPATH:-/usr/local/www/wordpress}
WP_VERSION=${WP_VERSION:-7.1.2}
ADMIN_USER=${ADMIN_USER:-admin}
SITE_TITLE=${SITE_TITLE:-OpenZFS Developer Summit}

# Everything below lands in config files and in `su -c` strings: accept
# only plain values.
check() { # name value extended-regex
	case "$2" in *"
"*) echo "setup.sh: bad $1: contains a newline" >&2; exit 2 ;; esac
	printf '%s' "$2" | grep -Eq "^($3)\$" || { echo "setup.sh: bad $1: '$2' (must match: $3)" >&2; exit 2; }
}
check FQDN "$FQDN" '[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?'
check ADMIN_EMAIL "$ADMIN_EMAIL" '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+'
check WPPATH "$WPPATH" '/[A-Za-z0-9_/.-]+'
case "$WPPATH" in *..*) echo "setup.sh: bad WPPATH: '$WPPATH'" >&2; exit 2 ;; esac
check WP_VERSION "$WP_VERSION" '[0-9]+(\.[0-9]+){1,2}'
check ADMIN_USER "$ADMIN_USER" '[A-Za-z0-9_.-]+'
check SITE_TITLE "$SITE_TITLE" "[A-Za-z0-9 &.,:()-]+"

WP="env HOME=/tmp /usr/local/bin/wp --path=$WPPATH"
www() { su -m www -c "$*"; }

sysrc -q mysql_enable=YES php_fpm_enable=YES nginx_enable=YES >/dev/null
sysrc -q mysql_args="--bind-address=127.0.0.1" >/dev/null
service mysql-server status >/dev/null 2>&1 || service mysql-server start >/dev/null

# PHP
[ -f /usr/local/etc/php.ini ] || cp /usr/local/etc/php.ini-production /usr/local/etc/php.ini
sed -i '' \
	-e 's/^upload_max_filesize.*/upload_max_filesize = 64M/' \
	-e 's/^post_max_size.*/post_max_size = 64M/' \
	-e 's/^memory_limit.*/memory_limit = 256M/' \
	-e 's/^;*expose_php.*/expose_php = Off/' \
	-e 's|^;*mysqli.default_socket.*|mysqli.default_socket = /var/run/mysql/mysql.sock|' \
	/usr/local/etc/php.ini
sed -i '' \
	-e 's|^listen = .*|listen = /var/run/php-fpm.sock|' \
	-e 's|^;listen.owner.*|listen.owner = www|' \
	-e 's|^;listen.group.*|listen.group = www|' \
	-e 's|^;listen.mode.*|listen.mode = 0660|' \
	/usr/local/etc/php-fpm.d/www.conf

# Database. The password lives only in wp-config.php and a root-only file,
# and that file is written only after the SQL succeeded, so a failed run is
# retried in full next time.
if [ ! -f /root/.wpdb ]; then
	P=$(openssl rand -hex 24)
	mysql -uroot <<SQL
DELETE FROM mysql.global_priv WHERE User='';
DROP DATABASE IF EXISTS test;
CREATE DATABASE IF NOT EXISTS wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'wordpress'@'localhost' IDENTIFIED BY '$P';
ALTER USER 'wordpress'@'localhost' IDENTIFIED BY '$P';
GRANT ALL ON wordpress.* TO 'wordpress'@'localhost';
FLUSH PRIVILEGES;
SQL
	( umask 077; printf '%s\n' "$P" > /root/.wpdb.new && mv /root/.wpdb.new /root/.wpdb )
fi

# wp-cli
if [ ! -x /usr/local/bin/wp ]; then
	fetch -qo /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	chmod 755 /usr/local/bin/wp
fi

# nginx. Cloudflare's published edge ranges give nginx the real client
# address, and decide whether a request came through Cloudflare at all (only
# then is its CF-Visitor header believed).
CF_RANGES=$(for u in ips-v4 ips-v6; do fetch -qo - "https://www.cloudflare.com/$u"; echo; done |
	grep -E '^[0-9a-fA-F.:]+/[0-9]+$' || true)
if [ -z "$CF_RANGES" ]; then
	echo "setup.sh: could not fetch Cloudflare's address ranges from" >&2
	echo "  https://www.cloudflare.com/ips-v4 and /ips-v6. Check the jail's outbound" >&2
	echo "  HTTPS and DNS (fetch -o - https://www.cloudflare.com/ips-v4), then re-run." >&2
	exit 1
fi
{
	echo "# Cloudflare edge ranges (fetched $(date -u +%F))"
	printf '%s\n' "$CF_RANGES" | sed 's|.*|set_real_ip_from &;|'
	echo "real_ip_header CF-Connecting-IP;"
} > /usr/local/etc/nginx/cloudflare-realip.conf
{
	echo "# 1 when the TCP peer is a Cloudflare edge (fetched $(date -u +%F))"
	echo 'geo $realip_remote_addr $from_cloudflare {'
	echo '    default 0;'
	printf '%s\n' "$CF_RANGES" | sed 's|.*|    & 1;|'
	echo '}'
} > /usr/local/etc/nginx/cloudflare-geo.conf

mkdir -p /usr/local/etc/nginx/ssl /usr/local/www/acme
# Keep a vhost certbot has already edited; write it only the first time.
if ! grep -q "server_name $FQDN;" /usr/local/etc/nginx/nginx.conf 2>/dev/null; then
	sed -e "s|@FQDN@|$FQDN|g" -e "s|@WPPATH@|$WPPATH|g" "$HERE/nginx.conf.in" > /usr/local/etc/nginx/nginx.conf
fi

# A short-lived self-signed certificate, so nginx can start before
# `certbot --nginx` installs the real one.
if [ ! -f /usr/local/etc/nginx/ssl/site.fullchain.pem ]; then
	( umask 077
	  openssl req -x509 -newkey rsa:2048 -nodes -days 7 -subj "/CN=$FQDN" \
		-keyout /usr/local/etc/nginx/ssl/site.key.pem \
		-out /usr/local/etc/nginx/ssl/site.fullchain.pem 2>/dev/null )
	chmod 644 /usr/local/etc/nginx/ssl/site.fullchain.pem
fi

# WordPress core
mkdir -p "$WPPATH"
chown www:www "$WPPATH"
[ -f "$WPPATH/wp-load.php" ] || www "$WP core download --version=$WP_VERSION" >/dev/null
if [ ! -f "$WPPATH/wp-config.php" ]; then
	www "$WP config create --dbname=wordpress --dbuser=wordpress --dbhost=localhost --skip-check --prompt=dbpass" < /root/.wpdb >/dev/null
	www "$WP config set FS_METHOD direct" >/dev/null
	www "$WP config set DISALLOW_FILE_EDIT true --raw" >/dev/null
	chmod 640 "$WPPATH/wp-config.php"
fi
if ! www "$WP core is-installed" 2>/dev/null; then
	( umask 077; openssl rand -base64 18 > /root/.wpadmin )
	www "$WP core install --url=https://$FQDN --title='$SITE_TITLE' --admin_user=$ADMIN_USER --admin_email=$ADMIN_EMAIL --skip-email --prompt=admin_password" < /root/.wpadmin >/dev/null
fi

nginx -t
service php_fpm restart >/dev/null
if service nginx status >/dev/null 2>&1; then service nginx reload >/dev/null; else service nginx start >/dev/null; fi
echo "WordPress $(www "$WP core version") at $WPPATH for $FQDN (admin password: /root/.wpadmin)"
