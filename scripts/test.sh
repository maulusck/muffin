#!/usr/bin/env bash
set -euo pipefail

BASE="${1:-http://localhost:8080}"
FAIL=0

GREEN="\033[0;32m"
RED="\033[0;31m"
NC="\033[0m"

ok()   { printf "  %-36s ${GREEN}OK${NC}\n"   "$1"; }
fail() { printf "  %-36s ${RED}FAIL${NC}\n" "$1"; FAIL=1; }
hdr()  { echo ""; echo "== $1 =="; }

http_code() {
    curl -s -o /dev/null -w "%{http_code}" "$1"
}

check_http() {
    local name=$1 url=$2 expect=${3:-200}
    local code; code=$(http_code "$url")
    [[ "$code" == "$expect" ]] && ok "$name (HTTP $code)" || { echo "    got HTTP $code"; fail "$name"; }
}

check_xml() {
    local name=$1 url=$2 xpath=$3
    curl -s "$url" | xq -x "$xpath" >/dev/null 2>&1 && ok "$name" || fail "$name"
}

check_zip() {
    local name=$1 url=$2
    local tmp; tmp=$(mktemp /tmp/chk_XXXXXX.nupkg)
    local bytes=0

    curl -fsSL -o "$tmp" "$url" 2>/dev/null && bytes=$(wc -c < "$tmp") || true

    if [[ "$bytes" -gt 0 ]]; then
        ok "$name (${bytes}B)"
    else
        fail "$name (empty/download failed)"
        rm -f "$tmp"
        return
    fi

    # ZIP local-file header magic: 50 4B 03 04
    local magic; magic=$(od -An -tx1 -N4 "$tmp" | tr -d ' \n')
    rm -f "$tmp"

    if [[ "$magic" == "504b0304" ]]; then
        ok "$name (ZIP sig)"
    else
        fail "$name (bad ZIP sig, got: $magic)"
    fi
}

nuget_has() {
    local term=$1 expect=$2
    ./bin/nuget search "$term" -Source "$BASE/Packages" -NonInteractive 2>/dev/null \
        | tr -d '\r' \
        | grep -qi "$expect" && ok "nuget search '$term'" || fail "nuget search '$term'"
}

# ── Service document / metadata ───────────────────────────────────────────────
hdr "SERVICE DOCUMENT"
check_xml "root collection href"   "$BASE/"          "//collection[@href='Packages']"
check_xml "\$metadata collection"  "$BASE/\$metadata" "//collection[@href='Packages']"

# ── Feed structure ────────────────────────────────────────────────────────────
hdr "PACKAGES FEED"
check_xml "Packages feed element"  "$BASE/Packages"  "//*[local-name()='feed']"
check_xml "Packages m:count"       "$BASE/Packages"  "//*[local-name()='count']"

# ── OData filter ─────────────────────────────────────────────────────────────
hdr "ODATA \$filter"
check_xml "Id eq exact"      "$BASE/Packages?\$filter=Id%20eq%20'git.install'"        "//*[local-name()='Id'][text()='git.install']"
check_xml "tolower eq"       "$BASE/Packages?\$filter=tolower(Id)%20eq%20'git.install'" "//*[local-name()='Id']"
check_xml "substringof"      "$BASE/Packages?\$filter=substringof('git',tolower(Id))"  "//*[local-name()='Id']"
check_xml "7zip exact"       "$BASE/Packages?\$filter=Id%20eq%20'7zip.install'"       "//*[local-name()='Id'][text()='7zip.install']"
check_xml "missing safe"     "$BASE/Packages?\$filter=Id%20eq%20'doesnotexist'"      "//*[local-name()='feed']"

# ── OData pagination ──────────────────────────────────────────────────────────
hdr "PAGINATION"
check_xml "\$top=1 has entry"  "$BASE/Packages?\$top=1"           "//*[local-name()='entry']"
check_xml "\$skip=0 has entry" "$BASE/Packages?\$skip=0"          "//*[local-name()='entry']"
check_xml "\$orderby id asc"   "$BASE/Packages?\$orderby=id%20asc" "//*[local-name()='feed']"

# ── \$count ────────────────────────────────────────────────────────────────────
hdr "\$COUNT"
COUNT=$(curl -s "$BASE/Packages/\$count")
if [[ "$COUNT" =~ ^[0-9]+$ ]]; then
    ok "Packages/\$count = $COUNT"
else
    fail "Packages/\$count (got: $COUNT)"
fi

# ── FindPackagesById ──────────────────────────────────────────────────────────
hdr "FindPackagesById"
check_xml "git.install"  "$BASE/FindPackagesById()?id='git.install'"  "//*[local-name()='Id'][text()='git.install']"
check_xml "7zip.install" "$BASE/FindPackagesById()?id='7zip.install'" "//*[local-name()='Id'][text()='7zip.install']"

# ── Search() ─────────────────────────────────────────────────────────────────
hdr "Search()"
check_xml "Search git"  "$BASE/Search()?searchTerm='git'"  "//*[local-name()='feed']"
check_xml "Search 7zip" "$BASE/Search()?searchTerm='7zip'" "//*[local-name()='feed']"

# ── GetUpdates ────────────────────────────────────────────────────────────────
hdr "GetUpdates()"
check_xml "GetUpdates feed"  "$BASE/GetUpdates()?packageIds=git.install&versions=0.0.0" "//*[local-name()='feed']"
check_xml "no updates same"  "$BASE/GetUpdates()?packageIds=git.install&versions=99.99.99" "//*[local-name()='feed']"

# ── Package hashes in entries ─────────────────────────────────────────────────
hdr "NuGet COMPLIANCE"
check_xml "PackageHash present"          "$BASE/Packages" "//*[local-name()='PackageHash']"
check_xml "PackageHashAlgorithm present" "$BASE/Packages" "//*[local-name()='PackageHashAlgorithm']"
check_xml "IsLatestVersion present"      "$BASE/Packages" "//*[local-name()='IsLatestVersion']"

# ── Downloads ─────────────────────────────────────────────────────────────────
hdr "DOWNLOADS"
check_http "HTTP git.install"   "$BASE/package/git.install/2.54.0"
check_http "HTTP 7zip.install"  "$BASE/package/7zip.install/26.0.0"
check_http "404 on missing pkg" "$BASE/package/doesnotexist/1.0.0" 404

check_zip "git.install"  "$BASE/package/git.install/2.54.0"
check_zip "7zip.install" "$BASE/package/7zip.install/26.0.0"

# ── NuGet CLI ─────────────────────────────────────────────────────────────────
hdr "NuGet CLI"
nuget_has "git"  "git.install"
nuget_has "7zip" "7zip.install"

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
if [[ "$FAIL" -eq 0 ]]; then
    echo -e "${GREEN}ALL PASS${NC}"
else
    echo -e "${RED}${FAIL} FAILURE(S)${NC}"
fi
exit $FAIL