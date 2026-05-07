#!/usr/bin/env bash
set -e
LOGS="/var/log/apache2/error.log /var/log/php82/error.log"
[ "${1-}" = "-a" ] && LOGS="$LOGS /var/log/apache2/access.log"
exec podman exec -it websrv tail -f $LOGS