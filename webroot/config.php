<?php
/**
 * NuGet server configuration.
 * Accessible only by internal require — direct HTTP requests receive 403.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);exit('Forbidden');
}

// ── Required settings ─────────────────────────────────────────────────────────

/**
 * Public base URL of this server — no trailing slash.
 * If null, auto-detected from HTTP_HOST (fine for single-domain setups).
 */
define('CFG_BASE_URL', null);

/**
 * URL subpath this script is mounted at — no trailing slash, empty string for root.
 * Examples: ''  '/nuget'  '/chocolatey/packages'
 */
define('CFG_BASE_PATH', '');

/**
 * Absolute path to the directory holding .nupkg files and index.json.
 */
define('CFG_PACKAGES_DIR', __DIR__ . '/packages');

// ── Optional settings ─────────────────────────────────────────────────────────

/** Repository name shown in feeds and v3 index. */
define('CFG_REPO_NAME', 'Private NuGet Repository');

/**
 * API key required for package push (PUT/POST).
 * Set to null to disable push entirely.
 * Set to '' to allow unauthenticated push (not recommended).
 */
define('CFG_API_KEY', null);

/**
 * Default page size for OData feeds ($top default when not specified by client).
 * NuGet.org uses 30; Chocolatey often requests specific sizes.
 */
define('CFG_PAGE_SIZE', 30);
