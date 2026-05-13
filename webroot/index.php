<?php
/**
 * NuGet v2 (OData/Atom, Chocolatey-compatible) + v3 (JSON-LD) server
 *
 * Drop .nupkg files into ./packages/ and run rescan.php to rebuild the index.
 * Configure via config.php (see that file for all options).
 *
 * V2 endpoints  (OData):
 *   GET  /                               — service document
 *   GET  /$metadata                      — EDMX schema
 *   GET  /Packages                       — list / filter
 *   GET  /Packages(Id='X',Version='Y')   — single entity
 *   GET  /Packages/$count                — total count
 *   GET  /FindPackagesById()?id='X'      — by id
 *   GET  /Search()?searchTerm='X'        — search
 *   GET  /GetUpdates()                   — update check (Chocolatey)
 *   GET  /package/{id}/{version}         — download
 *   PUT  /                               — push (if API key configured)
 *
 * V3 endpoints  (JSON):
 *   GET  /v3/index.json                  — service index
 *   GET  /v3/query?q=X                   — search
 *   GET  /v3/registration/{id}/index.json — registration
 *   GET  /v3/registration/{id}/{version}.json — leaf
 *   GET  /v3/flatcontainer/{id}/index.json   — version list
 *   GET  /v3/flatcontainer/{id}/{ver}/{id}.{ver}.nupkg — download
 */

declare (strict_types = 1);

require_once __DIR__ . '/config.php';

// ── Bootstrap ─────────────────────────────────────────────────────────────────

define('PACKAGES_DIR', rtrim(CFG_PACKAGES_DIR, '/\\'));
define('INDEX_FILE', PACKAGES_DIR . '/index.json');

function base_url(): string
{
    static $url = null;
    if ($url !== null) {
        return $url;
    }

    if (CFG_BASE_URL !== null) {
        return $url = rtrim(CFG_BASE_URL, '/') . CFG_BASE_PATH;
    }

    $scheme     = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $url = $scheme . '://' . $host . CFG_BASE_PATH;
}

// ── Router ────────────────────────────────────────────────────────────────────

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = CFG_BASE_PATH;

// Strip base path prefix
if ($base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base));
}
$uri      = '/' . ltrim($uri, '/');
$path     = trim($uri, '/');
$segments = $path === '' ? [] : explode('/', $path);
$seg0     = $segments[0] ?? '';
$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ── V3 router ─────────────────────────────────────────────────────────────────

if ($seg0 === 'v3') {
    route_v3($segments, $method);
    exit;
}

// ── V2 router ─────────────────────────────────────────────────────────────────

// Package push (PUT to root or /api/v2/package)
if ($method === 'PUT' || ($method === 'POST' && $seg0 === '' && isset($_FILES['package']))) {
    route_push();
    exit;
}

header('Content-Type: application/atom+xml; charset=utf-8');

// Normalise function-style endpoints: strip trailing "()" for matching
$ep = preg_replace('/\(\)$/', '', $seg0);

switch (true) {
    // Service document
    case $path === '' || $path === 'api/v2':
        serve_service_doc();
        break;

    // EDMX metadata
    case $ep === '$metadata':
        serve_metadata();
        break;

    // Packages/$count
    case $ep === 'Packages' && ($segments[1] ?? '') === '$count':
        serve_count();
        break;

    // Packages(Id='X',Version='Y') — single entity
    case preg_match("/^Packages\(Id='([^']+)',\s*Version='([^']+)'\)$/i", $path, $pkm):
        serve_single_entity($pkm[1], $pkm[2]);
        break;

    // Packages
    case strcasecmp($ep, 'Packages') === 0:
        serve_packages();
        break;

    // FindPackagesById
    case strcasecmp($ep, 'FindPackagesById') === 0:
        $id = trim($_GET['id'] ?? ($_GET['Id'] ?? ''), "'\" ");
        serve_packages($id);
        break;

    // Search
    case strcasecmp($ep, 'Search') === 0:
        serve_search();
        break;

    // GetUpdates
    case strcasecmp($ep, 'GetUpdates') === 0:
        serve_get_updates();
        break;

    // Package download
    case $seg0 === 'package':
        serve_download($segments[1] ?? '', $segments[2] ?? '');
        break;

    default:
        http_response_code(404);
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0"?><error>Not found</error>';
}

// ═════════════════════════════════════════════════════════════════════════════
// V3 Handlers
// ═════════════════════════════════════════════════════════════════════════════

function route_v3(array $segs, string $method): void
{
    header('Access-Control-Allow-Origin: *');
    $s1 = $segs[1] ?? '';

    // /v3/index.json
    if ($s1 === 'index.json') {v3_service_index();return;}

    // /v3/query
    if ($s1 === 'query') {v3_search();return;}

    // /v3/registration/{id}/index.json  or  /{id}/{version}.json
    if ($s1 === 'registration') {
        $id = strtolower($segs[2] ?? '');
        $s3 = $segs[3] ?? '';
        if ($id === '') {json_404();return;}
        if ($s3 === 'index.json' || $s3 === '') {v3_registration_index($id);return;}
        if (str_ends_with($s3, '.json')) {v3_registration_leaf($id, basename($s3, '.json'));return;}
        json_404();return;
    }

    // /v3/flatcontainer/{id}/index.json  or  /{id}/{ver}/{id}.{ver}.nupkg
    if ($s1 === 'flatcontainer') {
        $id = strtolower($segs[2] ?? '');
        $s3 = $segs[3] ?? '';
        $s4 = $segs[4] ?? '';
        if ($id === '') {json_404();return;}
        if ($s3 === 'index.json') {v3_flatcontainer_versions($id);return;}
        if ($s3 !== '' && $s4 !== '') {
            // Download via flat container path
            $ver = $s3;
            serve_download($id, $ver);
            return;
        }
        json_404();return;
    }

    json_404();
}

function v3_service_index(): void
{
    $b = base_url();
    json_out([
        'version'   => '3.0.0',
        'resources' => [
            ['@id' => "$b/v3/query", '@type' => 'SearchQueryService', 'comment' => 'Query endpoint'],
            ['@id' => "$b/v3/query", '@type' => 'SearchQueryService/3.5.0', 'comment' => 'Query endpoint'],
            ['@id' => "$b/v3/registration/", '@type' => 'RegistrationsBaseUrl', 'comment' => 'Registration base'],
            ['@id' => "$b/v3/registration/", '@type' => 'RegistrationsBaseUrl/3.0.0-beta', 'comment' => 'Registration base'],
            ['@id' => "$b/v3/flatcontainer/", '@type' => 'PackageBaseAddress/3.0.0', 'comment' => 'Flat container'],
            ['@id' => "$b/", '@type' => 'LegacyGallery', 'comment' => 'V2 feed'],
            ['@id' => "$b/", '@type' => 'LegacyGallery/2.0.0', 'comment' => 'V2 feed'],
            ['@id' => "$b/package", '@type' => 'PackagePublish/2.0.0', 'comment' => 'Push endpoint'],
        ],
    ]);
}

function v3_search(): void
{
    $q          = strtolower(trim($_GET['q'] ?? ''));
    $skip       = max(0, (int) ($_GET['skip'] ?? 0));
    $take       = min(1000, max(1, (int) ($_GET['take'] ?? CFG_PAGE_SIZE)));
    $prerelease = filter_var($_GET['prerelease'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

    $all = load_index();
    $all = latest_versions($all, $prerelease);

    if ($q !== '') {
        $all = array_values(array_filter($all, fn($p) =>
            str_contains(strtolower($p['id']), $q) ||
            str_contains(strtolower($p['title'] ?? ''), $q) ||
            str_contains(strtolower($p['description'] ?? ''), $q) ||
            str_contains(strtolower($p['tags'] ?? ''), $q)
        ));
    }

    $total = count($all);
    $paged = array_slice($all, $skip, $take);
    $b     = base_url();

    $data = array_map(fn($p) => v3_package_summary($p, $b), $paged);

    json_out(['totalHits' => $total, 'data' => $data]);
}

function v3_package_summary(array $p, string $b): array
{
    $id   = $p['id'];
    $lid  = strtolower($id);
    $all  = load_index();
    $vers = array_values(array_filter($all, fn($x) => strtolower($x['id']) === $lid));
    usort($vers, fn($a, $z) => version_compare($z['version'], $a['version']));
    $versions = array_map(fn($v) => [
        'version' => $v['version'],
        '@id'     => "$b/v3/registration/$lid/{$v['version']}.json",
        'downloads'                  => 0,
    ], $vers);
    return [
        '@id'            => "$b/v3/registration/$lid/index.json",
        '@type'          => 'Package',
        'registration'   => "$b/v3/registration/$lid/index.json",
        'id'             => $id,
        'version'        => $p['version'],
        'description'    => $p['description'] ?? '',
        'summary'        => $p['summary'] ?? '',
        'title'          => $p['title'] ?? $id,
        'iconUrl'        => $p['iconUrl'] ?? '',
        'licenseUrl'     => $p['licenseUrl'] ?? '',
        'projectUrl'     => $p['projectUrl'] ?? '',
        'tags'           => $p['tags'] !== '' ? explode(' ', $p['tags']) : [],
        'authors'        => $p['authors'] !== '' ? explode(',', $p['authors']) : [],
        'totalDownloads' => 0,
        'versions'       => $versions,
    ];
}

function v3_registration_index(string $lid): void
{
    $all  = load_index();
    $pkgs = array_values(array_filter($all, fn($p) => strtolower($p['id']) === $lid));
    if (empty($pkgs)) {json_404();return;}
    usort($pkgs, fn($a, $b) => version_compare($a['version'], $b['version']));

    $b     = base_url();
    $items = array_map(fn($p) => v3_registration_leaf_data($p, $b, $lid), $pkgs);

    json_out([
        '@id'   => "$b/v3/registration/$lid/index.json",
        '@type' => ['catalog:CatalogRoot', 'PackageRegistration'],
        'count' => 1,
        'items' => [[
            '@id'   => "$b/v3/registration/$lid/index.json",
            '@type' => 'catalog:CatalogPage',
            'count' => count($items),
            'lower' => $pkgs[0]['version'],
            'upper' => end($pkgs)['version'],
            'items' => $items,
        ]],
    ]);
}

function v3_registration_leaf(string $lid, string $version): void
{
    $all  = load_index();
    $pkgs = array_filter($all, fn($p) => strtolower($p['id']) === $lid && $p['version'] === $version);
    $pkg  = reset($pkgs);
    if (! $pkg) {json_404();return;}
    $b = base_url();
    json_out(v3_registration_leaf_data($pkg, $b, $lid));
}

function v3_registration_leaf_data(array $p, string $b, string $lid): array
{
    $ver = $p['version'];
    $id  = $p['id'];
    return [
        '@id'            => "$b/v3/registration/$lid/$ver.json",
        '@type'          => ['Package', 'http://schema.nuget.org/catalog#Permalink'],
        'listed'         => true,
        'packageContent' => "$b/v3/flatcontainer/$lid/$ver/$lid.$ver.nupkg",
        'published'      => $p['published'] ?? date('c'),
        'registration'   => "$b/v3/registration/$lid/index.json",
        'catalogEntry'   => [
            '@id'                      => "$b/v3/registration/$lid/$ver.json",
            '@type'                    => 'PackageDependencyGroup',
            'id'                       => $id,
            'version'                  => $ver,
            'title'                    => $p['title'] ?? $id,
            'description'              => $p['description'] ?? '',
            'summary'                  => $p['summary'] ?? '',
            'authors'                  => $p['authors'] ?? '',
            'owners'                   => $p['owners'] ?? '',
            'tags'                     => $p['tags'] ?? '',
            'projectUrl'               => $p['projectUrl'] ?? '',
            'licenseUrl'               => $p['licenseUrl'] ?? '',
            'iconUrl'                  => $p['iconUrl'] ?? '',
            'requireLicenseAcceptance' => ($p['requireLicenseAcceptance'] ?? 'false') === 'true',
            'dependencyGroups'         => v3_dependency_groups($p['dependencies'] ?? ''),
            'packageHash'              => $p['hash'] ?? '',
            'packageHashAlgorithm'     => $p['hashAlgorithm'] ?? 'SHA512',
            'packageSize'              => $p['size'] ?? 0,
            'published'                => $p['published'] ?? date('c'),
            'listed'                   => true,
            'isPrerelease'             => is_prerelease($ver),
        ],
    ];
}

function v3_dependency_groups(string $deps): array
{
    if ($deps === '') {
        return [];
    }

    $byTf = [];
    foreach (explode('|', $deps) as $raw) {
        [$did, $dver, $tf] = array_pad(explode(':', $raw, 3), 3, '');
        if ($did === '') {
            continue;
        }

        $byTf[$tf][] = ['id' => $did, 'range' => $dver];
    }
    $out = [];
    foreach ($byTf as $tf => $pkgs) {
        $g = ['@type' => 'PackageDependencyGroup', 'dependencies' => $pkgs];
        if ($tf !== '') {
            $g['targetFramework'] = $tf;
        }

        $out[] = $g;
    }
    return $out;
}

function v3_flatcontainer_versions(string $lid): void
{
    $all  = load_index();
    $vers = array_values(array_map(
        fn($p) => $p['version'],
        array_filter($all, fn($p) => strtolower($p['id']) === $lid)
    ));
    if (empty($vers)) {json_404();return;}
    usort($vers, 'version_compare');
    json_out(['versions' => array_map('strtolower', $vers)]);
}

function json_out(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function json_404(): void
{
    http_response_code(404);
    json_out(['error' => 'Not found']);
}

// ═════════════════════════════════════════════════════════════════════════════
// V2 Handlers
// ═════════════════════════════════════════════════════════════════════════════

function serve_service_doc(): void
{
    header('Content-Type: application/xml; charset=utf-8');
    $b = base_url();
    echo <<<XML
<?xml version="1.0" encoding="utf-8"?>
<service xmlns="http://www.w3.org/2007/app"
         xmlns:atom="http://www.w3.org/2005/Atom"
         xml:base="{$b}/">
  <workspace>
    <atom:title type="text">Default</atom:title>
    <collection href="Packages">
      <atom:title type="text">Packages</atom:title>
    </collection>
  </workspace>
</service>
XML;
}

function serve_metadata(): void
{
    header('Content-Type: application/xml; charset=utf-8');
    $b = base_url();
    echo <<<XML
<?xml version="1.0" encoding="utf-8"?>
<edmx:Edmx Version="1.0"
           xmlns:edmx="http://schemas.microsoft.com/ado/2007/06/edmx">
  <edmx:DataServices m:DataServiceVersion="2.0"
                     xmlns:m="http://schemas.microsoft.com/ado/2007/08/dataservices/metadata">
    <Schema Namespace="NuGetGallery"
            xmlns="http://schemas.microsoft.com/ado/2006/04/edm"
            xmlns:m="http://schemas.microsoft.com/ado/2007/08/dataservices/metadata"
            xmlns:d="http://schemas.microsoft.com/ado/2007/08/dataservices">
      <EntityType Name="V2FeedPackage" m:HasStream="true">
        <Key><PropertyRef Name="Id"/><PropertyRef Name="Version"/></Key>
        <Property Name="Id"                       Type="Edm.String"   Nullable="false"/>
        <Property Name="Version"                  Type="Edm.String"   Nullable="false"/>
        <Property Name="NormalizedVersion"        Type="Edm.String"/>
        <Property Name="Title"                    Type="Edm.String"/>
        <Property Name="Description"              Type="Edm.String"/>
        <Property Name="Summary"                  Type="Edm.String"/>
        <Property Name="ReleaseNotes"             Type="Edm.String"/>
        <Property Name="Authors"                  Type="Edm.String"/>
        <Property Name="Owners"                   Type="Edm.String"/>
        <Property Name="Tags"                     Type="Edm.String"/>
        <Property Name="ProjectUrl"               Type="Edm.String"/>
        <Property Name="LicenseUrl"               Type="Edm.String"/>
        <Property Name="IconUrl"                  Type="Edm.String"/>
        <Property Name="Copyright"                Type="Edm.String"/>
        <Property Name="RequireLicenseAcceptance" Type="Edm.Boolean" Nullable="false"/>
        <Property Name="IsPrerelease"             Type="Edm.Boolean" Nullable="false"/>
        <Property Name="IsLatestVersion"          Type="Edm.Boolean" Nullable="false"/>
        <Property Name="IsAbsoluteLatestVersion"  Type="Edm.Boolean" Nullable="false"/>
        <Property Name="Listed"                   Type="Edm.Boolean"/>
        <Property Name="Dependencies"             Type="Edm.String"/>
        <Property Name="PackageSize"              Type="Edm.Int64"    Nullable="false"/>
        <Property Name="PackageHash"              Type="Edm.String"/>
        <Property Name="PackageHashAlgorithm"     Type="Edm.String"/>
        <Property Name="Published"                Type="Edm.DateTime" Nullable="false"/>
        <Property Name="Created"                  Type="Edm.DateTime"/>
        <Property Name="LastUpdated"              Type="Edm.DateTime"/>
        <Property Name="LastEdited"               Type="Edm.DateTime"/>
        <Property Name="DownloadCount"            Type="Edm.Int32"    Nullable="false"/>
        <Property Name="VersionDownloadCount"     Type="Edm.Int32"    Nullable="false"/>
        <Property Name="Language"                 Type="Edm.String"/>
        <Property Name="MinClientVersion"         Type="Edm.String"/>
        <Property Name="LicenseNames"             Type="Edm.String"/>
        <Property Name="LicenseReportUrl"         Type="Edm.String"/>
      </EntityType>
      <EntityContainer Name="V2FeedContext" m:IsDefaultEntityContainer="true">
        <EntitySet Name="Packages" EntityType="NuGetGallery.V2FeedPackage"/>
        <FunctionImport Name="Search"            ReturnType="Collection(NuGetGallery.V2FeedPackage)" EntitySet="Packages" m:HttpMethod="GET">
          <Parameter Name="searchTerm"      Type="Edm.String"/>
          <Parameter Name="targetFramework" Type="Edm.String"/>
          <Parameter Name="includePrerelease" Type="Edm.Boolean"/>
        </FunctionImport>
        <FunctionImport Name="FindPackagesById" ReturnType="Collection(NuGetGallery.V2FeedPackage)" EntitySet="Packages" m:HttpMethod="GET">
          <Parameter Name="id" Type="Edm.String"/>
        </FunctionImport>
        <FunctionImport Name="GetUpdates"       ReturnType="Collection(NuGetGallery.V2FeedPackage)" EntitySet="Packages" m:HttpMethod="GET">
          <Parameter Name="packageIds"          Type="Edm.String"/>
          <Parameter Name="versions"            Type="Edm.String"/>
          <Parameter Name="includePrerelease"   Type="Edm.Boolean"/>
          <Parameter Name="includeAllVersions"  Type="Edm.Boolean"/>
          <Parameter Name="targetFrameworks"    Type="Edm.String"/>
          <Parameter Name="versionConstraints"  Type="Edm.String"/>
        </FunctionImport>
      </EntityContainer>
    </Schema>
  </edmx:DataServices>
</edmx:Edmx>
XML;
}

function serve_count(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    $all       = load_index();
    [, $total] = apply_odata($all);
    echo $total;
}

function serve_single_entity(string $id, string $version): void
{
    $all  = load_index();
    $pkgs = array_filter($all, fn($p) =>
        strcasecmp($p['id'], $id) === 0 && $p['version'] === $version
    );
    $pkg = reset($pkgs);
    if (! $pkg) {
        http_response_code(404);
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0"?><error>Not found</error>';
        return;
    }
    $b        = base_url();
    $updated  = date('c');
    $enriched = compute_flags(enrich($pkg), $all);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo <<<XML
<?xml version="1.0" encoding="utf-8"?>
<entry xmlns="http://www.w3.org/2005/Atom"
       xmlns:d="http://schemas.microsoft.com/ado/2007/08/dataservices"
       xmlns:m="http://schemas.microsoft.com/ado/2007/08/dataservices/metadata"
       xml:base="{$b}/">
XML;
    echo "\n" . package_entry($enriched) . "\n</entry>";
}

function serve_packages(string $fixedId = ''): void
{
    $all = load_index();
    if ($fixedId !== '') {
        $all = array_values(array_filter($all, fn($p) => strcasecmp($p['id'], $fixedId) === 0));
    }
    [$slice, $total] = apply_odata($all);
    $full            = load_index();
    feed_open('Packages', $total);
    foreach ($slice as $pkg) {
        echo "\n" . package_entry(compute_flags(enrich($pkg), $full));
    }
    feed_close();
}

function serve_search(): void
{
    $all             = load_index();
    [$slice, $total] = apply_odata($all);
    $full            = $all;
    feed_open('Search', $total);
    foreach ($slice as $pkg) {
        echo "\n" . package_entry(compute_flags(enrich($pkg), $full));
    }
    feed_close();
}

function serve_get_updates(): void
{
    $rawIds      = $_GET['packageIds'] ?? ($_GET['PackageIds'] ?? '');
    $rawVersions = $_GET['versions'] ?? ($_GET['Versions'] ?? '');
    $inclPre     = filter_var($_GET['includePrerelease'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    $inclAll     = filter_var($_GET['includeAllVersions'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

    $ids      = $rawIds !== '' ? explode('|', $rawIds) : [];
    $clientVs = $rawVersions !== '' ? explode('|', $rawVersions) : [];

    $all     = load_index();
    $updates = [];

    foreach ($ids as $i => $clientId) {
        $clientId  = trim($clientId);
        $clientVer = trim($clientVs[$i] ?? '0.0.0');

        $candidates = array_filter($all, fn($p) =>
            strcasecmp($p['id'], $clientId) === 0 &&
            ($inclPre || ! is_prerelease($p['version']))
        );
        if (empty($candidates)) {
            continue;
        }

        if ($inclAll) {
            foreach ($candidates as $c) {
                if (version_compare($c['version'], $clientVer, '>')) {
                    $updates[] = $c;
                }
            }
        } else {
            usort($candidates, fn($a, $b) => version_compare($b['version'], $a['version']));
            $latest = reset($candidates);
            if (version_compare($latest['version'], $clientVer, '>')) {
                $updates[] = $latest;
            }
        }
    }

    feed_open('GetUpdates', count($updates));
    foreach ($updates as $pkg) {
        echo "\n" . package_entry(compute_flags(enrich($pkg), $all));
    }
    feed_close();
}

function serve_download(string $id, string $version): void
{
    if ($id === '') {http_response_code(400);exit;}

    $dir  = PACKAGES_DIR;
    $file = null;

    // 1. Exact conventional filename
    if ($version !== '') {
        $c = "$dir/$id.$version.nupkg";
        if (file_exists($c)) {
            $file = $c;
        }

        // Also try lowercase id (flat-container style)
        if (! $file) {$c = "$dir/" . strtolower($id) . ".$version.nupkg";if (file_exists($c)) {
            $file = $c;
        }}
    }

    // 2. Scan index
    if (! $file) {
        foreach (load_index() as $pkg) {
            if (strcasecmp($pkg['id'], $id) !== 0) {
                continue;
            }

            if ($version !== '' && strcasecmp($pkg['version'], $version) !== 0) {
                continue;
            }

            $c = "$dir/" . ($pkg['file'] ?? '');
            if (file_exists($c)) {$file = $c;
                break;}
        }
    }

    if (! $file) {http_response_code(404);exit;}

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

// Push handler — PUT to / or /api/v2/package
function route_push(): void
{
    if (CFG_API_KEY === null) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo "Push is disabled on this server.\n";
        return;
    }
    if (CFG_API_KEY !== '') {
        $key = $_SERVER['HTTP_X_NUGET_APIKEY'] ?? ($_SERVER['HTTP_APIKEY'] ?? '');
        if ($key !== CFG_API_KEY) {
            http_response_code(401);
            header('Content-Type: text/plain');
            echo "Invalid API key.\n";
            return;
        }
    }

    // NuGet clients send the package as multipart/form-data field "package"
    $tmpFile = $_FILES['package']['tmp_name'] ?? null;
    if (! $tmpFile && ($method ?? '') === 'PUT') {
        // Some clients stream raw body
        $tmpFile = tempnam(sys_get_temp_dir(), 'nupkg_');
        file_put_contents($tmpFile, file_get_contents('php://input'));
    }

    if (! $tmpFile || ! file_exists($tmpFile)) {
        http_response_code(400);
        echo "No package file received.\n";
        return;
    }

    // Validate it's a real nupkg
    $zip = new ZipArchive();
    if ($zip->open($tmpFile) !== true) {
        http_response_code(400);
        echo "Invalid package.\n";return;
    }
    $nuspec = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_ends_with($zip->getNameIndex($i), '.nuspec')) {
            $nuspec = $zip->getFromIndex($i);
            break;
        }
    }
    $zip->close();
    if (! $nuspec) {http_response_code(400);
        echo "Missing .nuspec.\n";return;}

    $xml = @simplexml_load_string($nuspec);
    $id  = trim((string) ($xml->metadata->id ?? ''));
    $ver = trim((string) ($xml->metadata->version ?? ''));
    if ($id === '' || $ver === '') {http_response_code(400);
        echo "Invalid nuspec.\n";return;}

    if (! is_dir(PACKAGES_DIR)) {
        mkdir(PACKAGES_DIR, 0755, true);
    }

    $dest = PACKAGES_DIR . "/$id.$ver.nupkg";
    if (! move_uploaded_file($tmpFile, $dest)) {
        copy($tmpFile, $dest);
        unlink($tmpFile);
    }

    // Trigger index rebuild
    @include_once __DIR__ . '/rescan.php';

    http_response_code(201);
    header('Content-Type: text/plain');
    echo "Package $id $ver pushed successfully.\n";
}

// ═════════════════════════════════════════════════════════════════════════════
// Index / data helpers
// ═════════════════════════════════════════════════════════════════════════════

function load_index(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    if (file_exists(INDEX_FILE)) {
        $data = json_decode(file_get_contents(INDEX_FILE), true);
        if (is_array($data)) {
            return $cache = $data;
        }

    }
    return $cache = live_scan();
}

function live_scan(): array
{
    $index = [];
    if (! is_dir(PACKAGES_DIR)) {
        return $index;
    }

    foreach (glob(PACKAGES_DIR . '/*.nupkg') ?: [] as $file) {
        $pkg = parse_nupkg($file);
        if ($pkg) {
            $index[] = $pkg;
        }

    }
    usort($index, fn($a, $b) => strcmp(
        strtolower($a['id']) . $a['version'],
        strtolower($b['id']) . $b['version']
    ));
    return $index;
}

function parse_nupkg(string $filepath): ?array
{
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        return null;
    }

    $nuspec = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_ends_with($zip->getNameIndex($i), '.nuspec')) {
            $nuspec = $zip->getFromIndex($i);
            break;
        }
    }
    $zip->close();
    if (! $nuspec) {
        return null;
    }

    $xml = @simplexml_load_string($nuspec);
    if (! $xml || ! isset($xml->metadata)) {
        return null;
    }

    $m   = $xml->metadata;
    $id  = trim((string) ($m->id ?? ''));
    $ver = trim((string) ($m->version ?? ''));
    if ($id === '' || $ver === '') {
        return null;
    }

    return [
        'id'                       => $id,
        'version'                  => $ver,
        'title'                    => (string) ($m->title ?? $m->id ?? $id),
        'summary'                  => (string) ($m->summary ?? $m->description ?? ''),
        'description'              => (string) ($m->description ?? ''),
        'authors'                  => (string) ($m->authors ?? ''),
        'owners'                   => (string) ($m->owners ?? $m->authors ?? ''),
        'tags'                     => (string) ($m->tags ?? ''),
        'projectUrl'               => (string) ($m->projectUrl ?? ''),
        'licenseUrl'               => (string) ($m->licenseUrl ?? ''),
        'iconUrl'                  => (string) ($m->iconUrl ?? ''),
        'releaseNotes'             => (string) ($m->releaseNotes ?? ''),
        'copyright'                => (string) ($m->copyright ?? ''),
        'language'                 => (string) ($m->language ?? ''),
        'minClientVersion'         => (string) ($m->{'minClientVersion'} ?? ''),
        'requireLicenseAcceptance' => strtolower((string) ($m->requireLicenseAcceptance ?? 'false')),
        'dependencies'             => parse_dependencies($m->dependencies ?? null),
        'published'                => date('c', (int) filemtime($filepath)),
        'size'                     => (int) filesize($filepath),
        'hash'                     => base64_encode(hash_file('sha512', $filepath, true)),
        'hashAlgorithm'            => 'SHA512',
        'file'                     => basename($filepath),
    ];
}

function parse_dependencies($deps): string
{
    if (! $deps) {
        return '';
    }

    $out = [];
    foreach ($deps->dependency ?? [] as $dep) {
        $out[] = (string) ($dep['id'] ?? '') . ':' . (string) ($dep['version'] ?? '') . ':';
    }
    foreach ($deps->group ?? [] as $group) {
        $tf = (string) ($group['targetFramework'] ?? '');
        foreach ($group->dependency ?? [] as $dep) {
            $out[] = (string) ($dep['id'] ?? '') . ':' . (string) ($dep['version'] ?? '') . ':' . $tf;
        }
    }
    return implode('|', $out);
}

/**
 * Compute IsLatestVersion, IsAbsoluteLatestVersion, IsPrerelease for a package
 * relative to the full index.
 */
function compute_flags(array $pkg, array $all): array
{
    $id       = $pkg['id'];
    $ver      = $pkg['version'];
    $pre      = is_prerelease($ver);
    $siblings = array_filter($all, fn($p) => strcasecmp($p['id'], $id) === 0);

    // Latest stable
    $stables = array_filter($siblings, fn($p) => ! is_prerelease($p['version']));
    usort($stables, fn($a, $b) => version_compare($b['version'], $a['version']));
    $latestStable = reset($stables);

    // Absolute latest (including pre)
    $allSorted = array_values($siblings);
    usort($allSorted, fn($a, $b) => version_compare($b['version'], $a['version']));
    $absLatest = reset($allSorted);

    $pkg['isPrerelease']            = $pre;
    $pkg['isLatestVersion']         = $latestStable && $latestStable['version'] === $ver;
    $pkg['isAbsoluteLatestVersion'] = $absLatest && $absLatest['version'] === $ver;
    return $pkg;
}

function is_prerelease(string $version): bool
{
    return (bool) preg_match('/-/', $version);
}

/** Return only one record per id: latest stable, or absolute latest if no stable. */
function latest_versions(array $all, bool $includePrerelease = false): array
{
    $byId = [];
    foreach ($all as $p) {
        if (! $includePrerelease && is_prerelease($p['version'])) {
            continue;
        }

        $lid = strtolower($p['id']);
        if (! isset($byId[$lid]) || version_compare($p['version'], $byId[$lid]['version'], '>')) {
            $byId[$lid] = $p;
        }
    }
    return array_values($byId);
}

function enrich(array $pkg): array
{
    if (! isset($pkg['description'])) {
        $path = PACKAGES_DIR . '/' . ($pkg['file'] ?? '');
        if (file_exists($path)) {
            $full = parse_nupkg($path);
            if ($full) {
                return $full + $pkg;
            }

        }
    }
    return $pkg;
}

// ═════════════════════════════════════════════════════════════════════════════
// OData query helpers
// ═════════════════════════════════════════════════════════════════════════════

function apply_odata(array $packages): array
{
    if (isset($_GET['$filter'])) {
        $packages = apply_filter($packages, $_GET['$filter']);
    }

    if (isset($_GET['searchTerm'])) {
        $term = strtolower(trim($_GET['searchTerm'], "'\" "));
        if ($term !== '') {
            $packages = array_values(array_filter($packages, fn($p) =>
                str_contains(strtolower($p['id']), $term) ||
                str_contains(strtolower($p['title'] ?? ''), $term) ||
                str_contains(strtolower($p['description'] ?? ''), $term) ||
                str_contains(strtolower($p['tags'] ?? ''), $term)
            ));
        }
    }

    $inclPre = filter_var($_GET['includePrerelease'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
    if (! $inclPre) {
        $packages = array_values(array_filter($packages, fn($p) => ! is_prerelease($p['version'])));
    }

    $total = count($packages);

    if (isset($_GET['$orderby'])) {
        $packages = apply_orderby($packages, $_GET['$orderby']);
    }

    $skip     = max(0, (int) ($_GET['$skip'] ?? 0));
    $top      = isset($_GET['$top']) ? max(1, (int) $_GET['$top']) : CFG_PAGE_SIZE;
    $packages = array_slice(array_slice($packages, $skip), 0, $top);

    return [$packages, $total];
}

function apply_filter(array $packages, string $filter): array
{
    // Id eq 'X' or tolower(Id) eq 'x'
    if (preg_match("/(?:tolower\s*\(\s*)?Id(?:\s*\))?\s+eq\s+'([^']+)'/i", $filter, $m)) {
        $want = strtolower($m[1]);
        return array_values(array_filter($packages, fn($p) => strtolower($p['id']) === $want));
    }
    // substringof('term', Id/Description/Tags)
    if (preg_match("/substringof\s*\(\s*'([^']+)'/i", $filter, $m)) {
        $term = strtolower($m[1]);
        return array_values(array_filter($packages, fn($p) =>
            str_contains(strtolower($p['id']), $term) ||
            str_contains(strtolower($p['title'] ?? ''), $term) ||
            str_contains(strtolower($p['tags'] ?? ''), $term)
        ));
    }
    // startswith(Id, 'X')
    if (preg_match("/startswith\s*\(\s*Id\s*,\s*'([^']+)'\s*\)/i", $filter, $m)) {
        $prefix = strtolower($m[1]);
        return array_values(array_filter($packages, fn($p) => str_starts_with(strtolower($p['id']), $prefix)));
    }
    // IsLatestVersion eq true / IsAbsoluteLatestVersion eq true
    if (preg_match('/IsAbsoluteLatestVersion\s+eq\s+true/i', $filter)) {
        $all = $packages;
        return array_values(array_filter(array_map(fn($p) => compute_flags($p, $all), $packages), fn($p) => $p['isAbsoluteLatestVersion']));
    }
    if (preg_match('/IsLatestVersion\s+eq\s+true/i', $filter)) {
        $all = $packages;
        return array_values(array_filter(array_map(fn($p) => compute_flags($p, $all), $packages), fn($p) => $p['isLatestVersion']));
    }
    // Version eq 'X'
    if (preg_match("/\bVersion\s+eq\s+'([^']+)'/i", $filter, $m)) {
        $want = $m[1];
        return array_values(array_filter($packages, fn($p) => $p['version'] === $want));
    }
    return $packages;
}

function apply_orderby(array $packages, string $orderby): array
{
    $parts = preg_split('/\s+/', trim($orderby));
    $field = strtolower($parts[0] ?? 'id');
    $desc  = strtolower($parts[1] ?? '') === 'desc';
    usort($packages, function ($a, $b) use ($field, $desc) {
        $va = strtolower((string) ($a[$field] ?? ''));
        $vb = strtolower((string) ($b[$field] ?? ''));
        return $desc ? strcmp($vb, $va) : strcmp($va, $vb);
    });
    return $packages;
}

// ═════════════════════════════════════════════════════════════════════════════
// Atom feed rendering
// ═════════════════════════════════════════════════════════════════════════════

function feed_open(string $title, int $total): void
{
    $b           = base_url();
    $updated     = date('c');
    $inlinecount = isset($_GET['$inlinecount']) && $_GET['$inlinecount'] === 'allpages'
        ? "\n  <m:count>$total</m:count>" : '';
    echo <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"
      xmlns:d="http://schemas.microsoft.com/ado/2007/08/dataservices"
      xmlns:m="http://schemas.microsoft.com/ado/2007/08/dataservices/metadata"
      xml:base="{$b}/">{$inlinecount}
  <title type="text">{$title}</title>
  <id>{$b}/{$title}</id>
  <updated>{$updated}</updated>
  <link rel="self" title="{$title}" href="{$title}"/>
XML;
}

function feed_close(): void
{echo "\n</feed>";}

function package_entry(array $pkg): string
{
    $b       = base_url();
    $id      = htmlspecialchars($pkg['id'], ENT_XML1);
    $ver     = htmlspecialchars($pkg['version'], ENT_XML1);
    $normVer = htmlspecialchars(normalize_version($pkg['version']), ENT_XML1);
    $title   = htmlspecialchars($pkg['title'] ?? $pkg['id'], ENT_XML1);
    $summary = htmlspecialchars($pkg['summary'] ?? '', ENT_XML1);
    $desc    = htmlspecialchars($pkg['description'] ?? $summary, ENT_XML1);
    $authors = htmlspecialchars($pkg['authors'] ?? '', ENT_XML1);
    $owners  = htmlspecialchars($pkg['owners'] ?? $authors, ENT_XML1);
    $tags    = htmlspecialchars($pkg['tags'] ?? '', ENT_XML1);
    $projUrl = htmlspecialchars($pkg['projectUrl'] ?? '', ENT_XML1);
    $licUrl  = htmlspecialchars($pkg['licenseUrl'] ?? '', ENT_XML1);
    $iconUrl = htmlspecialchars($pkg['iconUrl'] ?? '', ENT_XML1);
    $notes   = htmlspecialchars($pkg['releaseNotes'] ?? '', ENT_XML1);
    $copy    = htmlspecialchars($pkg['copyright'] ?? '', ENT_XML1);
    $lang    = htmlspecialchars($pkg['language'] ?? '', ENT_XML1);
    $minCv   = htmlspecialchars($pkg['minClientVersion'] ?? '', ENT_XML1);
    $rla     = ($pkg['requireLicenseAcceptance'] ?? 'false') === 'true' ? 'true' : 'false';
    $pre     = ($pkg['isPrerelease'] ?? is_prerelease($pkg['version'])) ? 'true' : 'false';
    $latest  = ($pkg['isLatestVersion'] ?? false) ? 'true' : 'false';
    $absLat  = ($pkg['isAbsoluteLatestVersion'] ?? false) ? 'true' : 'false';
    $pub     = $pkg['published'] ?? date('c');
    $size    = (int) ($pkg['size'] ?? 0);
    $deps    = htmlspecialchars($pkg['dependencies'] ?? '', ENT_XML1);
    $hash    = htmlspecialchars($pkg['hash'] ?? '', ENT_XML1);
    $hashAlg = htmlspecialchars($pkg['hashAlgorithm'] ?? 'SHA512', ENT_XML1);
    $dlUrl   = "{$b}/package/{$id}/{$ver}";

    return <<<XML
  <entry>
    <id>{$b}/Packages(Id='{$id}',Version='{$ver}')</id>
    <title type="text">{$title}</title>
    <summary type="text">{$summary}</summary>
    <updated>{$pub}</updated>
    <author><name>{$authors}</name></author>
    <link rel="edit-media" title="Package" href="Packages(Id='{$id}',Version='{$ver}')/\$value"/>
    <link rel="edit"       title="Package" href="Packages(Id='{$id}',Version='{$ver}')"/>
    <category term="NuGetGallery.V2FeedPackage" scheme="http://schemas.microsoft.com/ado/2007/08/dataservices/scheme"/>
    <content type="application/zip" src="{$dlUrl}"/>
    <m:properties>
      <d:Id>{$id}</d:Id>
      <d:Version>{$ver}</d:Version>
      <d:NormalizedVersion>{$normVer}</d:NormalizedVersion>
      <d:Title>{$title}</d:Title>
      <d:Description>{$desc}</d:Description>
      <d:Summary>{$summary}</d:Summary>
      <d:ReleaseNotes>{$notes}</d:ReleaseNotes>
      <d:Authors>{$authors}</d:Authors>
      <d:Owners>{$owners}</d:Owners>
      <d:Tags>{$tags}</d:Tags>
      <d:Copyright>{$copy}</d:Copyright>
      <d:Language>{$lang}</d:Language>
      <d:MinClientVersion>{$minCv}</d:MinClientVersion>
      <d:ProjectUrl>{$projUrl}</d:ProjectUrl>
      <d:LicenseUrl>{$licUrl}</d:LicenseUrl>
      <d:IconUrl>{$iconUrl}</d:IconUrl>
      <d:RequireLicenseAcceptance m:type="Edm.Boolean">{$rla}</d:RequireLicenseAcceptance>
      <d:IsPrerelease            m:type="Edm.Boolean">{$pre}</d:IsPrerelease>
      <d:IsLatestVersion         m:type="Edm.Boolean">{$latest}</d:IsLatestVersion>
      <d:IsAbsoluteLatestVersion m:type="Edm.Boolean">{$absLat}</d:IsAbsoluteLatestVersion>
      <d:Listed                  m:type="Edm.Boolean">true</d:Listed>
      <d:Dependencies>{$deps}</d:Dependencies>
      <d:PackageSize             m:type="Edm.Int64">{$size}</d:PackageSize>
      <d:PackageHash>{$hash}</d:PackageHash>
      <d:PackageHashAlgorithm>{$hashAlg}</d:PackageHashAlgorithm>
      <d:Published               m:type="Edm.DateTime">{$pub}</d:Published>
      <d:Created                 m:type="Edm.DateTime">{$pub}</d:Created>
      <d:LastUpdated             m:type="Edm.DateTime">{$pub}</d:LastUpdated>
      <d:LastEdited              m:type="Edm.DateTime">{$pub}</d:LastEdited>
      <d:DownloadCount           m:type="Edm.Int32">0</d:DownloadCount>
      <d:VersionDownloadCount    m:type="Edm.Int32">0</d:VersionDownloadCount>
      <d:LicenseNames></d:LicenseNames>
      <d:LicenseReportUrl></d:LicenseReportUrl>
    </m:properties>
  </entry>
XML;
}

/** Strip build metadata, normalize 3-part version for NuGet NormalizedVersion field. */
function normalize_version(string $ver): string
{
    // Strip build metadata after '+'
    $ver = preg_replace('/\+.*$/', '', $ver) ?? $ver;
    // Ensure at least 3 parts before any pre-release tag
    [$numeric, $pre] = array_pad(explode('-', $ver, 2), 2, null);
    $parts           = explode('.', $numeric);
    while (count($parts) < 3) {
        $parts[] = '0';
    }

    $out = implode('.', array_slice($parts, 0, 3));
    return $pre !== null ? "$out-$pre" : $out;
}
