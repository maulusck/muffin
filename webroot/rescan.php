<?php
/**
 * Rescan and rebuild packages/index.json
 *
 * Run via CLI:  php rescan.php
 * Run via HTTP: GET /rescan.php  (restrict in production via .htaccess or firewall)
 */

declare (strict_types = 1);

define("PACKAGES_DIR", realpath(__DIR__ . "/packages") ?: (__DIR__ . "/packages"));
define("INDEX_FILE", PACKAGES_DIR . "/index.json");

$isCli = PHP_SAPI === "cli";

if (! $isCli) {
    header("Content-Type: text/plain; charset=utf-8");
    // Basic IP whitelist — adjust or remove as needed
    $allowed = ["127.0.0.1", "::1"];
    if (! in_array($_SERVER["REMOTE_ADDR"] ?? "", $allowed, true)) {
        http_response_code(403);
        exit("Forbidden\n");
    }
}

if (! is_dir(PACKAGES_DIR)) {
    mkdir(PACKAGES_DIR, 0755, true);
}

if (! class_exists("ZipArchive")) {
    err("ZipArchive extension is missing — install php-zip");
    exit(1);
}

$index  = [];
$errors = 0;

foreach (glob(PACKAGES_DIR . "/*.nupkg") ?: [] as $file) {
    $pkg = parse_nupkg($file);
    if (! $pkg) {
        out("SKIP  " . basename($file) . " (parse failed)");
        $errors++;
        continue;
    }
    $index[] = $pkg;
    out("OK    " . $pkg["id"] . " " . $pkg["version"]);
}

usort($index, fn($a, $b) => strcmp(
    strtolower($a["id"]) . $a["version"],
    strtolower($b["id"]) . $b["version"]
));

$tmp = INDEX_FILE . ".tmp." . getmypid();
if (file_put_contents($tmp, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
    err("Cannot write index to " . $tmp);
    exit(1);
}
rename($tmp, INDEX_FILE);

out("");
out("Indexed " . count($index) . " package(s) — " . $errors . " error(s)");
out("Index written to " . INDEX_FILE);

// ── Helpers ───────────────────────────────────────────────────────────────────

function parse_nupkg(string $filepath): ?array
{
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        return null;
    }

    $nuspec = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_ends_with($zip->getNameIndex($i), ".nuspec")) {
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

    $m = $xml->metadata;

    $id      = trim((string) ($m->id ?? ""));
    $version = trim((string) ($m->version ?? ""));
    if ($id === "" || $version === "") {
        return null;
    }

    // SHA512 for NuGet package integrity
    $hash = base64_encode(hash_file("sha512", $filepath, true));

    return [
        "id"                       => $id,
        "version"                  => $version,
        "title"                    => (string) ($m->title ?? $m->id ?? $id),
        "summary"                  => (string) ($m->summary ?? $m->description ?? ""),
        "description"              => (string) ($m->description ?? ""),
        "authors"                  => (string) ($m->authors ?? ""),
        "owners"                   => (string) ($m->owners ?? $m->authors ?? ""),
        "tags"                     => (string) ($m->tags ?? ""),
        "projectUrl"               => (string) ($m->projectUrl ?? ""),
        "licenseUrl"               => (string) ($m->licenseUrl ?? ""),
        "iconUrl"                  => (string) ($m->iconUrl ?? ""),
        "releaseNotes"             => (string) ($m->releaseNotes ?? ""),
        "requireLicenseAcceptance" => strtolower((string) ($m->requireLicenseAcceptance ?? "false")),
        "dependencies"             => parse_dependencies($m->dependencies ?? null),
        "published"                => date("c", (int) filemtime($filepath)),
        "size"                     => (int) filesize($filepath),
        "hash"                     => $hash,
        "hashAlgorithm"            => "SHA512",
        "file"                     => basename($filepath),
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

function out(string $msg): void
{
    echo $msg . "\n";
}

function err(string $msg): void
{
    fwrite(STDERR, "ERROR: $msg\n");
}
