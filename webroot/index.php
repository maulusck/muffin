<?php
/**
 * NuGet v2 OData endpoint — Chocolatey-compatible private repository
 *
 * Drop .nupkg files into ./packages/ then hit /rescan.php (or scan.sh)
 * to rebuild the index. All reads use the cached index.json for speed.
 */

declare (strict_types = 1);

define("PACKAGES_DIR", __DIR__ . "/packages");
define("INDEX_FILE", PACKAGES_DIR . "/index.json");

$scheme = (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") ? "https" : "http";
define("BASE_URL", $scheme . "://" . $_SERVER["HTTP_HOST"]);

// ── Router ────────────────────────────────────────────────────────────────────

$uri      = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
$path     = trim($uri, "/");
$segments = $path === "" ? [] : explode("/", $path);
$endpoint = $segments[0] ?? "";

header("Content-Type: application/atom+xml; charset=utf-8");

switch (true) {
    case $endpoint === "" || $endpoint === '$metadata':
        serve_metadata();
        break;

    case $endpoint === "Packages" && isset($segments[1]) && $segments[1] === '$count':
        serve_count();
        break;

    case $endpoint === "Packages":
        serve_packages();
        break;

    case in_array($endpoint, ["FindPackagesById()", "FindPackagesById"], true):
        $id = trim($_GET["id"] ?? ($_GET["Id"] ?? ""), "'\"");
        serve_packages($id);
        break;

    case in_array($endpoint, ["Search()", "Search"], true):
        serve_search();
        break;

    case in_array($endpoint, ["GetUpdates()", "GetUpdates"], true):
        serve_get_updates();
        break;

    case $endpoint === "package":
        serve_download($segments[1] ?? "", $segments[2] ?? "");
        break;

    default:
        http_response_code(404);
        header("Content-Type: application/xml; charset=utf-8");
        echo '<?xml version="1.0"?><error>Not found</error>';
}

// ── Index helpers ─────────────────────────────────────────────────────────────

/**
 * Load the cached index built by rescan.php.
 * Falls back to live scan if index.json is missing.
 */
function load_index(): array
{
    if (file_exists(INDEX_FILE)) {
        $data = json_decode(file_get_contents(INDEX_FILE), true);
        if (is_array($data)) {
            return $data;
        }
    }
    // fallback: live scan (slow but safe)
    return live_scan();
}

function live_scan(): array
{
    $index = [];
    if (! is_dir(PACKAGES_DIR)) {
        return $index;
    }
    foreach (glob(PACKAGES_DIR . "/*.nupkg") as $file) {
        $pkg = parse_nupkg($file);
        if ($pkg) {
            $index[] = $pkg + [
                "file"    => basename($file),
                "updated" => filemtime($file),
                "size"    => filesize($file),
            ];
        }
    }
    usort($index, fn($a, $b) => strcmp($a["id"] . $a["version"], $b["id"] . $b["version"]));
    return $index;
}

/**
 * Parse a .nupkg and return its metadata.
 */
function parse_nupkg(string $filepath): ?array
{
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        return null;
    }

    $nuspec = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (str_ends_with($name, ".nuspec")) {
            $nuspec = $zip->getFromIndex($i);
            break;
        }
    }
    $zip->close();

    if (! $nuspec) {
        return null;
    }

    $xml = @simplexml_load_string($nuspec);
    if (! $xml) {
        return null;
    }

    $m = $xml->metadata;

    // SHA512 checksum for NuGet compliance
    $hash = base64_encode(hash_file("sha512", $filepath, true));

    return [
        "id"                       => (string) ($m->id ?? ""),
        "version"                  => (string) ($m->version ?? ""),
        "title"                    => (string) ($m->title ?? ($m->id ?? "")),
        "summary"                  => (string) ($m->summary ?? ($m->description ?? "")),
        "description"              => (string) ($m->description ?? ""),
        "authors"                  => (string) ($m->authors ?? ""),
        "owners"                   => (string) ($m->owners ?? ($m->authors ?? "")),
        "tags"                     => (string) ($m->tags ?? ""),
        "projectUrl"               => (string) ($m->projectUrl ?? ""),
        "licenseUrl"               => (string) ($m->licenseUrl ?? ""),
        "iconUrl"                  => (string) ($m->iconUrl ?? ""),
        "releaseNotes"             => (string) ($m->releaseNotes ?? ""),
        "requireLicenseAcceptance" => strtolower((string) ($m->requireLicenseAcceptance ?? "false")),
        "dependencies"             => parse_dependencies($m->dependencies ?? null),
        "published"                => date("c", filemtime($filepath)),
        "size"                     => filesize($filepath),
        "hash"                     => $hash,
        "hashAlgorithm"            => "SHA512",
        "filename"                 => basename($filepath),
    ];
}

function parse_dependencies($deps): string
{
    if (! $deps) {
        return "";
    }
    $out = [];
    foreach ($deps->dependency ?? [] as $dep) {
        $id    = (string) ($dep["id"] ?? "");
        $ver   = (string) ($dep["version"] ?? "");
        $out[] = $id . ":" . $ver . ":";
    }
    foreach ($deps->group ?? [] as $group) {
        $tf = (string) ($group["targetFramework"] ?? "");
        foreach ($group->dependency ?? [] as $dep) {
            $id    = (string) ($dep["id"] ?? "");
            $ver   = (string) ($dep["version"] ?? "");
            $out[] = $id . ":" . $ver . ":" . $tf;
        }
    }
    return implode("|", $out);
}

// ── OData filter / query helpers ──────────────────────────────────────────────

/**
 * Apply OData $filter string to the full index and return matching packages.
 */
function apply_filter(array $packages, string $filter): array
{
    // Id eq 'X'
    if (preg_match("/\bId\s+eq\s+'([^']+)'/i", $filter, $m)) {
        $want = $m[1];
        return array_values(array_filter($packages, fn($p) => strcasecmp($p["id"], $want) === 0));
    }
    // tolower(Id) eq 'x'
    if (preg_match("/tolower\s*\(\s*Id\s*\)\s+eq\s+'([^']+)'/i", $filter, $m)) {
        $want = strtolower($m[1]);
        return array_values(array_filter($packages, fn($p) => strtolower($p["id"]) === $want));
    }
    // substringof('term', tolower(Id)) or substringof('term', Id)
    if (preg_match("/substringof\s*\(\s*'([^']+)'\s*,\s*(?:tolower\s*\(\s*)?Id(?:\s*\))?\s*\)/i", $filter, $m)) {
        $term = strtolower($m[1]);
        return array_values(array_filter($packages, fn($p) =>
            str_contains(strtolower($p["id"]), $term) ||
            str_contains(strtolower($p["title"]), $term) ||
            str_contains(strtolower($p["tags"]), $term)
        ));
    }
    // Version eq 'X'
    if (preg_match("/\bVersion\s+eq\s+'([^']+)'/i", $filter, $m)) {
        $want = $m[1];
        return array_values(array_filter($packages, fn($p) => $p["version"] === $want));
    }
    return $packages;
}

/**
 * Apply OData $orderby.
 */
function apply_orderby(array $packages, string $orderby): array
{
    $parts = preg_split('/\s+/', trim($orderby));
    $field = strtolower($parts[0] ?? "id");
    $desc  = strtolower($parts[1] ?? "") === "desc";

    usort($packages, function ($a, $b) use ($field, $desc) {
        $va = strtolower((string) ($a[$field] ?? ""));
        $vb = strtolower((string) ($b[$field] ?? ""));
        return $desc ? strcmp($vb, $va) : strcmp($va, $vb);
    });
    return $packages;
}

/**
 * Parse and apply all relevant OData query params.
 * Returns [$slice, $total].
 */
function apply_odata(array $packages): array
{
    if (isset($_GET['$filter'])) {
        $packages = apply_filter($packages, $_GET['$filter']);
    }

    // searchTerm support (Search() endpoint)
    if (isset($_GET['searchTerm'])) {
        $term = strtolower(trim($_GET['searchTerm'], "'\" "));
        if ($term !== "") {
            $packages = array_values(array_filter($packages, fn($p) =>
                str_contains(strtolower($p["id"]), $term) ||
                str_contains(strtolower($p["title"]), $term) ||
                str_contains(strtolower($p["description"]), $term) ||
                str_contains(strtolower($p["tags"]), $term)
            ));
        }
    }

    // targetFramework / includePrerelease — accepted, ignored (all packages served)

    $total = count($packages);

    if (isset($_GET['$orderby'])) {
        $packages = apply_orderby($packages, $_GET['$orderby']);
    }

    $skip = max(0, (int) ($_GET['$skip'] ?? 0));
    $top  = isset($_GET['$top']) ? max(1, (int) $_GET['$top']) : null;

    $packages = array_slice($packages, $skip);
    if ($top !== null) {
        $packages = array_slice($packages, 0, $top);
    }

    return [$packages, $total];
}

// ── Atom entry renderer ───────────────────────────────────────────────────────

function package_entry(array $pkg): string
{
    $base    = BASE_URL;
    $id      = htmlspecialchars($pkg["id"], ENT_XML1);
    $ver     = htmlspecialchars($pkg["version"], ENT_XML1);
    $title   = htmlspecialchars($pkg["title"], ENT_XML1);
    $summary = htmlspecialchars($pkg["summary"], ENT_XML1);
    $desc    = htmlspecialchars($pkg["description"] ?? $pkg["summary"], ENT_XML1);
    $authors = htmlspecialchars($pkg["authors"] ?? "", ENT_XML1);
    $owners  = htmlspecialchars($pkg["owners"] ?? $pkg["authors"] ?? "", ENT_XML1);
    $tags    = htmlspecialchars($pkg["tags"] ?? "", ENT_XML1);
    $projUrl = htmlspecialchars($pkg["projectUrl"] ?? "", ENT_XML1);
    $licUrl  = htmlspecialchars($pkg["licenseUrl"] ?? "", ENT_XML1);
    $iconUrl = htmlspecialchars($pkg["iconUrl"] ?? "", ENT_XML1);
    $notes   = htmlspecialchars($pkg["releaseNotes"] ?? "", ENT_XML1);
    $rla     = $pkg["requireLicenseAcceptance"] ?? "false";
    $pub     = $pkg["published"] ?? date("c");
    $size    = (int) ($pkg["size"] ?? 0);
    $deps    = htmlspecialchars($pkg["dependencies"] ?? "", ENT_XML1);
    $hash    = htmlspecialchars($pkg["hash"] ?? "", ENT_XML1);
    $hashAlg = htmlspecialchars($pkg["hashAlgorithm"] ?? "SHA512", ENT_XML1);
    $dlUrl   = "{$base}/package/{$id}/{$ver}";

    return <<<XML
  <entry>
    <id>{$base}/Packages(Id='{$id}',Version='{$ver}')</id>
    <title type="text">{$title}</title>
    <summary type="text">{$summary}</summary>
    <updated>{$pub}</updated>
    <author><name>{$authors}</name></author>
    <link rel="edit-media" title="Package" href="Packages(Id='{$id}',Version='{$ver}')/\$value"/>
    <link rel="edit"       title="Package" href="Packages(Id='{$id}',Version='{$ver}')"/>
    <content type="application/zip" src="{$dlUrl}"/>
    <m:properties>
      <d:Id>{$id}</d:Id>
      <d:Version>{$ver}</d:Version>
      <d:Title>{$title}</d:Title>
      <d:Description>{$desc}</d:Description>
      <d:Summary>{$summary}</d:Summary>
      <d:ReleaseNotes>{$notes}</d:ReleaseNotes>
      <d:Authors>{$authors}</d:Authors>
      <d:Owners>{$owners}</d:Owners>
      <d:Tags>{$tags}</d:Tags>
      <d:ProjectUrl>{$projUrl}</d:ProjectUrl>
      <d:LicenseUrl>{$licUrl}</d:LicenseUrl>
      <d:IconUrl>{$iconUrl}</d:IconUrl>
      <d:RequireLicenseAcceptance m:type="Edm.Boolean">{$rla}</d:RequireLicenseAcceptance>
      <d:Dependencies>{$deps}</d:Dependencies>
      <d:PackageSize m:type="Edm.Int64">{$size}</d:PackageSize>
      <d:PackageHash>{$hash}</d:PackageHash>
      <d:PackageHashAlgorithm>{$hashAlg}</d:PackageHashAlgorithm>
      <d:Published m:type="Edm.DateTime">{$pub}</d:Published>
      <d:Created m:type="Edm.DateTime">{$pub}</d:Created>
      <d:LastUpdated m:type="Edm.DateTime">{$pub}</d:LastUpdated>
      <d:IsLatestVersion m:type="Edm.Boolean">true</d:IsLatestVersion>
      <d:IsAbsoluteLatestVersion m:type="Edm.Boolean">true</d:IsAbsoluteLatestVersion>
      <d:IsPrerelease m:type="Edm.Boolean">false</d:IsPrerelease>
      <d:Listed m:type="Edm.Boolean">true</d:Listed>
      <d:DownloadCount m:type="Edm.Int32">0</d:DownloadCount>
      <d:VersionDownloadCount m:type="Edm.Int32">0</d:VersionDownloadCount>
    </m:properties>
  </entry>
XML;
}

// ── Feed wrapper ──────────────────────────────────────────────────────────────

function feed_open(string $title, int $total): void
{
    $base    = BASE_URL;
    $updated = date("c");
    echo <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"
      xmlns:d="http://schemas.microsoft.com/ado/2007/08/dataservices"
      xmlns:m="http://schemas.microsoft.com/ado/2007/08/dataservices/metadata"
      xml:base="{$base}">
  <title type="text">{$title}</title>
  <id>{$base}/{$title}</id>
  <updated>{$updated}</updated>
  <link rel="self" title="{$title}" href="{$title}"/>
  <m:count>{$total}</m:count>
XML;
}

function feed_close(): void
{
    echo "\n</feed>";
}

// ── Endpoint handlers ─────────────────────────────────────────────────────────

function serve_metadata(): void
{
    header("Content-Type: application/xml; charset=utf-8");
    $base = BASE_URL;
    echo <<<XML
<?xml version="1.0" encoding="utf-8"?>
<service xmlns="http://www.w3.org/2007/app"
         xmlns:atom="http://www.w3.org/2005/Atom"
         xml:base="{$base}">
  <workspace>
    <atom:title>Default</atom:title>
    <collection href="Packages">
      <atom:title>Packages</atom:title>
    </collection>
  </workspace>
</service>
XML;
}

function serve_count(): void
{
    header("Content-Type: text/plain; charset=utf-8");
    $all         = load_index();
    [$_, $total] = apply_odata($all);
    echo $total;
}

function serve_packages(string $fixedId = ""): void
{
    $all = load_index();

    if ($fixedId !== "") {
        $all = array_values(array_filter($all, fn($p) => strcasecmp($p["id"], $fixedId) === 0));
    }

    [$slice, $total] = apply_odata($all);

    feed_open("Packages", $total);
    foreach ($slice as $pkg) {
        echo "\n" . package_entry(enrich($pkg));
    }
    feed_close();
}

function serve_search(): void
{
    $all             = load_index();
    [$slice, $total] = apply_odata($all);

    feed_open("Search", $total);
    foreach ($slice as $pkg) {
        echo "\n" . package_entry(enrich($pkg));
    }
    feed_close();
}

/**
 * GetUpdates() — compare client's installed versions against the index.
 * Chocolatey sends: packageIds, versions (pipe-delimited), includePrerelease, targetFramework
 */
function serve_get_updates(): void
{
    $rawIds      = $_GET["packageIds"] ?? $_GET["PackageIds"] ?? "";
    $rawVersions = $_GET["versions"] ?? $_GET["Versions"] ?? "";
    $ids         = $rawIds !== "" ? explode("|", $rawIds) : [];
    $clientVers  = $rawVersions !== "" ? explode("|", $rawVersions) : [];

    $all     = load_index();
    $updates = [];

    foreach ($ids as $i => $clientId) {
        $clientId  = trim($clientId);
        $clientVer = trim($clientVers[$i] ?? "0.0.0");

        // Find the highest version in the index for this id
        $candidates = array_filter($all, fn($p) => strcasecmp($p["id"], $clientId) === 0);
        if (empty($candidates)) {
            continue;
        }
        usort($candidates, fn($a, $b) => version_compare($b["version"], $a["version"]));
        $latest = reset($candidates);

        if (version_compare($latest["version"], $clientVer, ">")) {
            $updates[] = $latest;
        }
    }

    feed_open("GetUpdates", count($updates));
    foreach ($updates as $pkg) {
        echo "\n" . package_entry(enrich($pkg));
    }
    feed_close();
}

function serve_download(string $id, string $version): void
{
    if ($id === "") {
        http_response_code(400);
        exit();
    }

    $dir  = PACKAGES_DIR;
    $file = null;

    // Fast path: conventional filename
    if ($version !== "") {
        $candidate = $dir . "/" . $id . "." . $version . ".nupkg";
        if (file_exists($candidate)) {
            $file = $candidate;
        }
    }

    // Slow path: scan index
    if (! $file) {
        foreach (load_index() as $pkg) {
            if (strcasecmp($pkg["id"], $id) !== 0) {
                continue;
            }
            if ($version !== "" && $pkg["version"] !== $version) {
                continue;
            }
            $candidate = $dir . "/" . $pkg["file"];
            if (file_exists($candidate)) {
                $file = $candidate;
                break;
            }
        }
    }

    if (! $file || ! file_exists($file)) {
        http_response_code(404);
        exit();
    }

    header("Content-Type: application/zip");
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header("Content-Length: " . filesize($file));
    header("X-Content-Type-Options: nosniff");
    readfile($file);
    exit();
}

// ── Utilities ─────────────────────────────────────────────────────────────────

/**
 * Enrich a lean index record with full metadata if needed.
 * Index records built by rescan.php are minimal; live_scan() returns full records.
 */
function enrich(array $pkg): array
{
    // If full fields are missing, re-parse the nupkg
    if (! isset($pkg["description"])) {
        $path = PACKAGES_DIR . "/" . $pkg["file"];
        if (file_exists($path)) {
            $full = parse_nupkg($path);
            if ($full) {
                return $full + $pkg;
            }
        }
    }
    return $pkg;
}
