# MUFFIN

**Minimal Unified Feed For Indexed NuGet** — a self-hosted Chocolatey / NuGet v2+v3 repository that fits in a single PHP file.

Drop `.nupkg` files in. Run one script. Point Chocolatey at it. That's it.

---

## Quickstart

```sh
./scripts/reload.sh                        # build image + start container
cp my-tool.1.0.0.nupkg webroot/packages/  # drop packages in
./scripts/scan.sh                          # rebuild index
./scripts/test.sh                          # verify everything works
```

Point Chocolatey at it:

```powershell
choco source add --name=local --source=http://<host>:8080/
choco install git --source=local
choco upgrade git --source=local
```

---

## Project layout

```
.
├── Dockerfile
├── scripts/
│   ├── get-nuget.sh   — install mono + nuget CLI on Linux
│   ├── logs.sh        — tail Apache/PHP logs from the running container
│   ├── perms.sh       — fix permissions (dirs 755 / files 644)
│   ├── reload.sh      — rebuild image + replace running container
│   ├── scan.sh        — re-index packages inside the running container
│   └── test.sh        — full NuGet v2/v3 compliance test suite
└── webroot/
    ├── .htaccess      — route all requests through index.php
    ├── config.php     — configuration (base URL, API key, page size, …)
    ├── index.php      — NuGet v2 OData + v3 JSON-LD endpoint
    ├── rescan.php     — walks packages/ and writes index.json
    ├── test.php       — PHP environment debug page
    └── packages/      — *.nupkg files live here (git-ignored)
        └── index.json — auto-generated; do not commit
```

---

## How it works

### Indexing

`rescan.php` walks `webroot/packages/*.nupkg`, reads the embedded `.nuspec`, computes a SHA-512 hash, and writes everything to `packages/index.json`.

`index.php` reads that file on every request (cheap). If the index is missing it falls back to a live scan, so the server is never broken.

Re-index whenever packages change:

```sh
./scripts/scan.sh                          # via container exec (preferred)
php webroot/rescan.php                     # directly on the host
curl http://localhost:8080/rescan.php      # via HTTP — localhost only
```

### NuGet v2 endpoints

| Method | URL | Description |
|---|---|---|
| GET | `/` or `/$metadata` | Service document / EDMX schema |
| GET | `/Packages` | List all packages (OData) |
| GET | `/Packages/$count` | Package count |
| GET | `/Packages?$filter=…` | Filtered listing |
| GET | `/Packages(Id='x',Version='y')` | Single entity |
| GET | `/FindPackagesById()?id='x'` | Exact-id lookup |
| GET | `/Search()?searchTerm='q'` | Full-text search |
| GET | `/GetUpdates()?packageIds=…&versions=…` | Update check |
| GET | `/package/<id>/<version>` | Download `.nupkg` |
| PUT | `/` | Push (requires `CFG_API_KEY`) |

### OData filter support

Filters are evaluated compositionally — multiple clauses are AND-ed in order:

| Filter | Behaviour |
|---|---|
| `Id eq 'x'` / `tolower(Id) eq 'x'` | Exact id match |
| `substringof('q', tolower(Id\|Description\|Tags))` | Substring search per field |
| `startswith(Id, 'x')` | Id prefix match |
| `IsLatestVersion eq true` | Latest stable per id |
| `IsAbsoluteLatestVersion eq true` | Latest (including pre-release) per id |
| `Version eq 'x'` | Exact version match |
| `$top=N` / `$skip=N` | Pagination |
| `$orderby=field [asc\|desc]` | Sorting |

The compound filter Chocolatey sends during `choco search` is handled correctly:

```
substringof('q',tolower(Id)) or substringof('q',tolower(Description)) or
substringof(' q ',tolower(Tags)) and IsLatestVersion
```

### NuGet v3 endpoints

| URL | Description |
|---|---|
| `/v3/index.json` | Service index |
| `/v3/query?q=x` | Search |
| `/v3/registration/<id>/index.json` | Registration index |
| `/v3/registration/<id>/<version>.json` | Registration leaf |
| `/v3/flatcontainer/<id>/index.json` | Version list |
| `/v3/flatcontainer/<id>/<ver>/<id>.<ver>.nupkg` | Download |

### GetUpdates

Chocolatey sends pipe-delimited `packageIds` and `versions`. MUFFIN compares installed versions against the index with `version_compare()` and returns only packages where a newer version exists.

---

## Configuration

Edit `webroot/config.php`:

| Constant | Default | Description |
|---|---|---|
| `CFG_BASE_URL` | `null` | Public base URL. `null` = auto-detect from `HTTP_HOST`. |
| `CFG_BASE_PATH` | `''` | URL subpath, e.g. `/choco`. No trailing slash. |
| `CFG_PACKAGES_DIR` | `__DIR__ . '/packages'` | Absolute path to `.nupkg` directory. |
| `CFG_REPO_NAME` | `'Private NuGet Repository'` | Name shown in feeds. |
| `CFG_API_KEY` | `null` | Push API key. `null` = push disabled. `''` = unauthenticated push. |
| `CFG_PAGE_SIZE` | `30` | Default OData page size. |

The container maps `webroot/` as a volume — file changes take effect immediately without a rebuild.

---

## Adding packages

Any standard `.nupkg` works. Build one with:

```sh
# Linux — mono + nuget CLI
./scripts/get-nuget.sh
./bin/nuget pack MyPackage.nuspec

# Windows / NuGet CLI
nuget pack MyPackage.nuspec

# Chocolatey
choco pack MyPackage.nuspec
```

Copy the `.nupkg` to `webroot/packages/`, then run `./scripts/scan.sh`.

---

## Scripts

**`reload.sh`** — Builds the Docker image, prunes dangling images, and starts (or replaces) the `websrv` container. Volume-mounts `webroot/` so file changes don't require a rebuild.

**`scan.sh`** — Runs `rescan.php` inside the running container via `podman exec`.

**`test.sh [base-url [package-id [version]]]`** — Full compliance suite. Requires `curl` and `xmllint`. Covers service document, feed structure, OData `$filter`/`$top`/`$skip`/`$orderby`, `$count`, `FindPackagesById`, `Search()`, `GetUpdates()`, ZIP integrity, and NuGet CLI search.

```sh
./scripts/test.sh http://192.168.1.10:8080 7zip 26.0.0
```

**`logs.sh [-a]`** — Tails the Apache error log. Pass `-a` to include the access log.

**`perms.sh`** — Sets directories to `755`, files to `644`, `scripts/` and `bin/` to `755`. Run from the project root after editing files on a Windows host.

**`get-nuget.sh`** — Installs Mono and downloads `nuget.exe`, then creates a `bin/nuget` wrapper. Only needed on a Linux host for NuGet CLI testing.

---

## Security

- `rescan.php` via HTTP is restricted to `127.0.0.1` / `::1`. Adjust or remove HTTP access for production.
- `packages/index.json` is git-ignored. Never commit it.
- The container runs as the `apache` user, not root.
- For internet-facing deployments, put the server behind nginx/Caddy with TLS and HTTP Basic Auth.

---

## Requirements

| Requirement | Notes |
|---|---|
| Podman or Docker | `reload.sh` uses `podman`; swap to `docker` if needed |
| `curl` + `xmllint` | For `test.sh` |
| Mono | Only for `get-nuget.sh` / NuGet CLI on Linux |
| PHP 8.2+ | Provided by the container (Alpine + `php82`) |
| PHP extensions | `zip`, `simplexml`, `json` — all installed in the image |