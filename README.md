# MUFFIN • Minimal Unified Feed For Indexed NuGet

A self-hosted [Chocolatey](https://chocolatey.org/) / NuGet v2 package repository.  
Drop `.nupkg` files into `webroot/packages/`, run the container, point Chocolatey at it.

---

## Quick start

```sh
# Build and start
./scripts/reload.sh

# Drop packages into webroot/packages/
cp my-tool.1.0.0.nupkg webroot/packages/

# Re-index
./scripts/scan.sh

# Verify
./scripts/test.sh
```

Point Chocolatey at the server:

```powershell
choco source add --name=local --source=http://<host>:8080/Packages
choco install git.install --source=local
choco upgrade git.install --source=local
```

---

## Structure

```
.
├── Dockerfile
├── scripts/
│   ├── get-nuget.sh   # install mono + nuget CLI (Linux host)
│   ├── logs.sh        # tail Apache logs from running container
│   ├── perms.sh       # fix file permissions (dirs 755, files 644)
│   ├── reload.sh      # rebuild image + replace running container
│   ├── scan.sh        # re-index packages inside running container
│   └── test.sh        # full NuGet v2 compliance test suite
└── webroot/
    ├── .htaccess      # route everything through index.php
    ├── index.php      # NuGet v2 OData endpoint
    ├── rescan.php     # builds packages/index.json
    ├── test.php       # PHP environment debug page
    └── packages/      # *.nupkg files go here (git-ignored)
        └── index.json # auto-generated; do not commit
```

---

## How it works

### Indexing

`rescan.php` walks `webroot/packages/*.nupkg`, reads each `.nuspec` inside the ZIP, computes a SHA-512 hash, and writes the full metadata to `packages/index.json`.

`index.php` reads `index.json` on every request (fast). If no index exists it falls back to a live scan so the server is never broken.

Re-index whenever you add or remove packages:

```sh
./scripts/scan.sh          # via container exec
php webroot/rescan.php     # directly on host
curl http://localhost:8080/rescan.php   # via HTTP (localhost only)
```

### NuGet v2 endpoints

| Method | URL | Description |
|---|---|---|
| GET | `/` or `/$metadata` | Service document |
| GET | `/Packages` | List all packages (OData) |
| GET | `/Packages/$count` | Package count |
| GET | `/Packages?$filter=…` | Filtered listing |
| GET | `/FindPackagesById()?id='<id>'` | Exact-id lookup |
| GET | `/Search()?searchTerm='<q>'` | Full-text search |
| GET | `/GetUpdates()?packageIds=…&versions=…` | Update check |
| GET | `/package/<id>/<version>` | Download `.nupkg` |

### OData query parameters

| Param | Effect |
|---|---|
| `$filter=Id eq 'x'` | Exact id match |
| `$filter=substringof('x',tolower(Id))` | Fuzzy id/title/tags match |
| `$top=N` | Return at most N results |
| `$skip=N` | Skip first N results |
| `$orderby=id asc\|desc` | Sort by any field |

### GetUpdates

Chocolatey calls `GetUpdates()` with pipe-delimited `packageIds` and `versions`.  
The server compares installed versions against the index using `version_compare()` and returns only packages where a newer version exists.

---

## Adding packages

Any standard `.nupkg` is supported. Build yours with:

```sh
# Linux: mono + nuget CLI
./scripts/get-nuget.sh
./bin/nuget pack MyPackage.nuspec

# Windows / NuGet CLI
nuget pack MyPackage.nuspec

# choco pack
choco pack MyPackage.nuspec
```

Copy the `.nupkg` to `webroot/packages/` then run `./scripts/scan.sh`.

---

## Configuration

| Item | Where | Default |
|---|---|---|
| Package directory | `PACKAGES_DIR` constant in `index.php` / `rescan.php` | `./packages` |
| Host port | `reload.sh` `-p` flag | `8080` |
| rescan HTTP access | IP whitelist in `rescan.php` | `127.0.0.1`, `::1` |

---

## Scripts reference

### `reload.sh`
Builds the Docker image, prunes dangling images, and starts (or replaces) the `websrv` container.  
Volume-mounts `webroot/` so file changes take effect without a rebuild.

### `scan.sh`
Runs `rescan.php` inside the running container via `podman exec`.

### `test.sh [base-url]`
Full compliance test suite. Requires `curl` and `xq` (XML-aware jq).  
Defaults to `http://localhost:8080`; pass a custom base URL as the first argument.

```sh
./scripts/test.sh http://192.168.1.10:8080
```

Tests cover: service document, feed structure, OData `$filter` / `$top` / `$skip` / `$orderby`, `$count`, `FindPackagesById`, `Search()`, `GetUpdates()`, package hashes, ZIP integrity, and NuGet CLI search.

### `logs.sh [-a]`
Tails the Apache error log. Pass `-a` to also tail the access log.

### `perms.sh`
Sets directories to `755` and files to `644`, with `scripts/` and `bin/` at `755`.  
Run from the project root after cloning or editing files on Windows hosts.

### `get-nuget.sh`
Installs Mono and downloads `nuget.exe`, then creates a `bin/nuget` wrapper script.  
Only needed on the Linux host for testing with the NuGet CLI.

---

## Security notes

- `rescan.php` via HTTP is restricted to `127.0.0.1` / `::1`. Adjust the allowlist or remove HTTP access entirely for production.  
- `packages/index.json` is git-ignored. Commit only source `.nuspec` / build scripts.  
- The container runs as the `apache` user, not root.  
- Consider placing the server behind a reverse proxy (nginx/Caddy) with TLS and HTTP Basic Auth for internet-facing deployments.

---

## Requirements

| Requirement | Notes |
|---|---|
| Podman (or Docker) | `reload.sh` uses `podman`; swap to `docker` if needed |
| `curl` + `xq` | For `test.sh` |
| Mono | Only for `get-nuget.sh` / NuGet CLI on Linux |
| PHP 8.2+ | Provided by the container (Alpine + `php82`) |
| PHP extensions | `zip`, `simplexml`, `json` — all installed in the image |
