#!/usr/bin/env bash
set -e
cd "$(dirname ${0})/../"
podman build -t websrv .
podman image prune -f
exec podman run --name websrv --replace -d --rm -v $(pwd)/webroot:/var/www/html:Z -p 8080:80 websrv