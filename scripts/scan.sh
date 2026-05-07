#!/usr/bin/env bash
set -e
exec podman exec -it websrv php82 /var/www/html/rescan.php