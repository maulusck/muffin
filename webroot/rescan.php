<?php

declare(strict_types=1);

// --------------------------------------------------
// NuGet / Chocolatey package index builder
// --------------------------------------------------

define("PACKAGES_DIR", realpath(__DIR__ . "/packages"));
define("INDEX_FILE", PACKAGES_DIR . "/index.json");

if (!PACKAGES_DIR || !is_dir(PACKAGES_DIR)) {
    fwrite(STDERR, "ERROR: packages directory not found\n");
    exit(1);
}

if (!class_exists("ZipArchive")) {
    fwrite(STDERR, "ERROR: ZipArchive extension missing\n");
    exit(1);
}

$index = [];

foreach (glob(PACKAGES_DIR . "/*.nupkg") as $file) {
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        continue;
    }

    $nuspecXml = null;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (str_ends_with($name, ".nuspec")) {
            $nuspecXml = $zip->getFromIndex($i);
            break;
        }
    }

    $zip->close();

    if (!$nuspecXml) {
        continue;
    }

    $xml = @simplexml_load_string($nuspecXml);
    if (!$xml || !isset($xml->metadata)) {
        continue;
    }

    $m = $xml->metadata;

    $id = strtolower(trim((string) $m->id));
    $version = trim((string) $m->version);

    if ($id === "" || $version === "") {
        continue;
    }

    $index[] = [
        "id" => $id,
        "version" => $version,
        "file" => basename($file),
        "updated" => filemtime($file),
        "size" => filesize($file),
    ];
}

// sort: stable order for clients
usort($index, function ($a, $b) {
    return strcmp($a["id"] . $a["version"], $b["id"] . $b["version"]);
});

// ensure directory exists
if (!is_dir(PACKAGES_DIR)) {
    mkdir(PACKAGES_DIR, 0777, true);
}

// atomic write (prevents corrupted index)
$tmp = INDEX_FILE . ".tmp";

file_put_contents($tmp, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

rename($tmp, INDEX_FILE);

echo "Reindex complete: " . count($index) . " packages\n";