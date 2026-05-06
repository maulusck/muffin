<?php
/**
 * Simple NuGet v2 OData endpoint for Chocolatey
 * Place this as index.php and configure PACKAGES_DIR below.
 */

define("PACKAGES_DIR", __DIR__ . "/packages");
define(
    "BASE_URL",
    "http" . (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on" ? "s" : "") . "://" . $_SERVER["HTTP_HOST"],
);

header("Content-Type: application/atom+xml; charset=utf-8");

$path = trim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
$segments = explode("/", $path);

$endpoint = $segments[0] ?? "";

switch ($endpoint) {
    case "":
    case '$metadata':
        serve_metadata();
        break;

    case "Packages":
        serve_packages();
        break;

    case "FindPackagesById()":
    case "FindPackagesById":
        $id = $_GET["id"] ?? ($_GET["Id"] ?? "");
        $id = trim($id, "'\"");
        serve_packages($id);
        break;

    case "GetUpdates()":
    case "GetUpdates":
        serve_get_updates();
        break;

    case "package":
        // Download: /package/{id}/{version}
        $pkgId = $segments[1] ?? "";
        $pkgVersion = $segments[2] ?? "";
        serve_download($pkgId, $pkgVersion);
        break;

    default:
        http_response_code(404);
        echo '<?xml version="1.0"?><error>Not found</error>';
}

// ─────────────────────────────────────────────
// Parse a .nupkg file and extract metadata from the .nuspec inside
// ─────────────────────────────────────────────
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

    if (!$nuspec) {
        return null;
    }

    $xml = @simplexml_load_string($nuspec);
    if (!$xml) {
        return null;
    }

    $m = $xml->metadata;
    return [
        "id" => (string) ($m->id ?? ""),
        "version" => (string) ($m->version ?? ""),
        "title" => (string) ($m->title ?? ($m->id ?? "")),
        "summary" => (string) ($m->summary ?? ($m->description ?? "")),
        "description" => (string) ($m->description ?? ""),
        "authors" => (string) ($m->authors ?? ""),
        "tags" => (string) ($m->tags ?? ""),
        "projectUrl" => (string) ($m->projectUrl ?? ""),
        "licenseUrl" => (string) ($m->licenseUrl ?? ""),
        "iconUrl" => (string) ($m->iconUrl ?? ""),
        "requireLicenseAcceptance" => strtolower((string) ($m->requireLicenseAcceptance ?? "false")),
        "dependencies" => parse_dependencies($m->dependencies ?? null),
        "published" => date("c", filemtime($filepath)),
        "size" => filesize($filepath),
        "filename" => basename($filepath),
    ];
}

function parse_dependencies($deps): string
{
    if (!$deps) {
        return "";
    }
    $out = [];
    foreach ($deps->dependency ?? [] as $dep) {
        $id = (string) ($dep["id"] ?? "");
        $ver = (string) ($dep["version"] ?? "");
        $out[] = $id . ($ver ? ":" . $ver . ":" : "::");
    }
    // Also handle <group> elements
    foreach ($deps->group ?? [] as $group) {
        foreach ($group->dependency ?? [] as $dep) {
            $id = (string) ($dep["id"] ?? "");
            $ver = (string) ($dep["version"] ?? "");
            $out[] = $id . ($ver ? ":" . $ver . ":" : "::");
        }
    }
    return implode("|", $out);
}

// ─────────────────────────────────────────────
// Scan packages dir and return all parsed packages (optionally filtered by id)
// ─────────────────────────────────────────────
function get_packages(string $filterId = ""): array
{
    $dir = PACKAGES_DIR;
    $packages = [];

    if (!is_dir($dir)) {
        return $packages;
    }

    foreach (glob($dir . "/*.nupkg") as $file) {
        $pkg = parse_nupkg($file);
        if (!$pkg) {
            continue;
        }
        if ($filterId && strcasecmp($pkg["id"], $filterId) !== 0) {
            continue;
        }
        $packages[] = $pkg;
    }

    // Sort by id then version
    usort($packages, fn($a, $b) => strcmp($a["id"] . $a["version"], $b["id"] . $b["version"]));

    return $packages;
}

// ─────────────────────────────────────────────
// Render an Atom <entry> for a package
// ─────────────────────────────────────────────
function package_entry(array $pkg): string
{
    $base = BASE_URL;
    $id = htmlspecialchars($pkg["id"]);
    $version = htmlspecialchars($pkg["version"]);
    $title = htmlspecialchars($pkg["title"]);
    $summary = htmlspecialchars($pkg["summary"]);
    $desc = htmlspecialchars($pkg["description"]);
    $authors = htmlspecialchars($pkg["authors"]);
    $tags = htmlspecialchars($pkg["tags"]);
    $projUrl = htmlspecialchars($pkg["projectUrl"]);
    $licUrl = htmlspecialchars($pkg["licenseUrl"]);
    $iconUrl = htmlspecialchars($pkg["iconUrl"]);
    $rla = $pkg["requireLicenseAcceptance"];
    $pub = $pkg["published"];
    $size = $pkg["size"];
    $deps = htmlspecialchars($pkg["dependencies"]);
    $dlUrl = "{$base}/package/{$id}/{$version}";

    return <<<XML
      <entry>
        <id>{$base}/Packages(Id='{$id}',Version='{$version}')</id>
        <title type="text">{$title}</title>
        <summary type="text">{$summary}</summary>
        <updated>{$pub}</updated>
        <author><name>{$authors}</name></author>
        <link rel="edit-media" title="Package" href="Packages(Id='{$id}',Version='{$version}')/\$value" />
        <link rel="edit" title="Package" href="Packages(Id='{$id}',Version='{$version}')" />
        <content type="application/zip" src="{$dlUrl}" />
        <m:properties xmlns:m="http://schemas.microsoft.com/ado/2007/08/dataservices/metadata"
                      xmlns:d="http://schemas.microsoft.com/ado/2007/08/dataservices">
          <d:Id>{$id}</d:Id>
          <d:Version>{$version}</d:Version>
          <d:Title>{$title}</d:Title>
          <d:Description>{$desc}</d:Description>
          <d:Summary>{$summary}</d:Summary>
          <d:Authors>{$authors}</d:Authors>
          <d:Tags>{$tags}</d:Tags>
          <d:ProjectUrl>{$projUrl}</d:ProjectUrl>
          <d:LicenseUrl>{$licUrl}</d:LicenseUrl>
          <d:IconUrl>{$iconUrl}</d:IconUrl>
          <d:RequireLicenseAcceptance m:type="Edm.Boolean">{$rla}</d:RequireLicenseAcceptance>
          <d:Dependencies>{$deps}</d:Dependencies>
          <d:PackageSize m:type="Edm.Int64">{$size}</d:PackageSize>
          <d:Published m:type="Edm.DateTime">{$pub}</d:Published>
          <d:IsLatestVersion m:type="Edm.Boolean">true</d:IsLatestVersion>
          <d:IsAbsoluteLatestVersion m:type="Edm.Boolean">true</d:IsAbsoluteLatestVersion>
          <d:Listed m:type="Edm.Boolean">true</d:Listed>
          <d:DownloadCount m:type="Edm.Int32">0</d:DownloadCount>
        </m:properties>
      </entry>
    XML;
}

// ─────────────────────────────────────────────
// Endpoints
// ─────────────────────────────────────────────
function serve_metadata(): void
{
    // Minimal $metadata response sufficient for Chocolatey
    header("Content-Type: application/xml; charset=utf-8");
    echo <<<XML
    <?xml version="1.0" encoding="utf-8"?>
    <service xmlns="http://www.w3.org/2007/app" xmlns:atom="http://www.w3.org/2005/Atom" xml:base="
    XML;
    echo BASE_URL . '">';
    echo <<<XML

      <workspace>
        <atom:title>Default</atom:title>
        <collection href="Packages">
          <atom:title>Packages</atom:title>
        </collection>
      </workspace>
    </service>
    XML;
}

function serve_packages(string $filterId = ""): void
{
    // Support OData $filter parsing for Chocolatey search
    if (!$filterId && isset($_GET['$filter'])) {
        $filter = $_GET['$filter'];
        if (preg_match("/Id\s+eq\s+'([^']+)'/i", $filter, $m)) {
            $filterId = $m[1];
        } elseif (preg_match("/tolower\(Id\)\s+eq\s+'([^']+)'/i", $filter, $m)) {
            $filterId = $m[1];
        }
    }

    $packages = get_packages($filterId);
    $base = BASE_URL;
    $updated = date("c");
    $count = count($packages);

    echo <<<XML
    <?xml version="1.0" encoding="utf-8"?>
    <feed xmlns="http://www.w3.org/2005/Atom"
          xmlns:d="http://schemas.microsoft.com/ado/2007/08/dataservices"
          xmlns:m="http://schemas.microsoft.com/ado/2007/08/dataservices/metadata"
          xml:base="{$base}">
      <title type="text">Packages</title>
      <id>{$base}/Packages</id>
      <updated>{$updated}</updated>
      <link rel="self" title="Packages" href="Packages" />
      <m:count>{$count}</m:count>

    XML;

    foreach ($packages as $pkg) {
        echo package_entry($pkg) . "\n";
    }

    echo "</feed>";
}

function serve_get_updates(): void
{
    // Chocolatey calls GetUpdates() to check for newer versions.
    // We return an empty feed — clients will find packages via FindPackagesById.
    $base = BASE_URL;
    $updated = date("c");
    echo <<<XML
    <?xml version="1.0" encoding="utf-8"?>
    <feed xmlns="http://www.w3.org/2005/Atom"
          xmlns:d="http://schemas.microsoft.com/ado/2007/08/dataservices"
          xmlns:m="http://schemas.microsoft.com/ado/2007/08/dataservices/metadata"
          xml:base="{$base}">
      <title type="text">GetUpdates</title>
      <id>{$base}/GetUpdates</id>
      <updated>{$updated}</updated>
      <m:count>0</m:count>
    </feed>
    XML;
}

function serve_download(string $id, string $version): void
{
    if (!$id) {
        http_response_code(400);
        exit();
    }

    $dir = PACKAGES_DIR;
    $file = null;

    // Exact filename match first
    $candidate = $dir . "/" . $id . "." . $version . ".nupkg";
    if ($version && file_exists($candidate)) {
        $file = $candidate;
    } else {
        // Scan and match by parsed metadata
        foreach (glob($dir . "/*.nupkg") as $f) {
            $pkg = parse_nupkg($f);
            if (!$pkg) {
                continue;
            }
            if (strcasecmp($pkg["id"], $id) !== 0) {
                continue;
            }
            if ($version && $pkg["version"] !== $version) {
                continue;
            }
            $file = $f;
            break;
        }
    }

    if (!$file || !file_exists($file)) {
        http_response_code(404);
        exit();
    }

    header("Content-Type: application/zip");
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header("Content-Length: " . filesize($file));
    readfile($file);
    exit();
}