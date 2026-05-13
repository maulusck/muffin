#!/usr/bin/env bash
set -euo pipefail

BASE="${1:-http://localhost:8080}"
PKG="${2:-7zip}"
VER="${3:-26.0.0}"

PASS=0
FAIL=0
SKIP=0

GREEN="\033[0;32m"
RED="\033[0;31m"
YELLOW="\033[0;33m"
BOLD="\033[1m"
NC="\033[0m"

ok() { printf "  %-60s ${GREEN}OK${NC}\n" "$1"; ((PASS++)) || true; }
fail() { printf "  %-60s ${RED}FAIL${NC} %s\n" "$1" "${2:-}"; ((FAIL++)) || true; }
skip() { printf "  %-60s ${YELLOW}SKIP${NC} %s\n" "$1" "${2:-}"; ((SKIP++)) || true; }

hdr() { printf "\n${BOLD}── %s ──${NC}\n" "$1"; }

fetch() { curl -sL "$1"; }
code() { curl -s -o /dev/null -w "%{http_code}" "$1"; }

xml_has() {
  echo "$1" | xmllint --nowarning --xpath "$2" - >/dev/null 2>&1
}

xml_val() {
  echo "$1" | xmllint --nowarning --xpath "$2" - 2>/dev/null || true
}

check_http() {
  local name=$1 url=$2 want=${3:-200}
  local got
  got=$(code "$url")
  [[ "$got" == "$want" ]] && ok "$name" || fail "$name" "HTTP $got"
}

check_xml() {
  local name=$1 url=$2 xpath=$3
  local body
  body=$(fetch "$url")
  xml_has "$body" "$xpath" && ok "$name" || fail "$name" "xpath fail"
}

check_xml_val() {
  local name=$1 url=$2 xpath=$3 want=$4
  local body got
  body=$(fetch "$url")
  got=$(xml_val "$body" "$xpath")
  [[ "$got" == "$want" ]] && ok "$name" || fail "$name" "got='$got' want='$want'"
}

check_xml_empty() {
  local name=$1 url=$2 xpath=$3
  local body
  body=$(fetch "$url")
  xml_has "$body" "$xpath" && fail "$name" "unexpected match" || ok "$name"
}

check_int() {
  local name=$1 url=$2 min=${3:-0}
  local body val

  body=$(fetch "$url" | tr -d '\r\n ')

  val=""
  if [[ "$body" =~ ^[0-9]+$ ]]; then
    val="$body"
  else
    val=$(echo "$body" | grep -oE '[0-9]+' | head -n1 || true)
  fi

  [[ "$val" =~ ^[0-9]+$ ]] && (( val >= min )) \
    && ok "$name ($val)" \
    || fail "$name" "bad int '$body'"
}

check_zip() {
  local name=$1 url=$2
  local tmp magic

  tmp=$(mktemp)
  curl -fsSL "$url" -o "$tmp" || {
    fail "$name" "download failed"
    rm -f "$tmp"
    return
  }

  magic=$(od -An -tx1 -N4 "$tmp" | tr -d ' \n')
  rm -f "$tmp"

  [[ "$magic" == "504b0304" ]] && ok "$name" || fail "$name" "bad zip"
}

hdr "SERVICE"

check_http "GET /Packages" "$BASE/Packages"
check_xml "feed root" "$BASE/Packages" "//*[local-name()='feed']"
check_http "GET /metadata" "$BASE/\$metadata"

hdr "ODATA CORE"

check_xml "filter Id eq" "$BASE/Packages?\$filter=Id%20eq%20'$PKG'" "//*[local-name()='Id']"

# -------------------- CHOCO COMPAT FILTER (INLINE, NOT SEPARATE) --------------------

check_http "choco complex filter (/Packages())" \
"$BASE/Packages()?\$filter=((((Id%20ne%20null)%20and%20substringof('git',tolower(Id)))%20or%20((Description%20ne%20null)%20and%20substringof('git',tolower(Description))))%20or%20((Tags%20ne%20null)%20and%20substringof('%20git%20',tolower(Tags))))%20and%20IsLatestVersion&\$orderby=Id&\$skip=0&\$top=30&semVerLevel=2.0.0" \
200 || true

check_xml "choco complex filter feed" \
"$BASE/Packages()?\$filter=((((Id%20ne%20null)%20and%20substringof('git',tolower(Id)))%20or%20((Description%20ne%20null)%20and%20substringof('git',tolower(Description))))%20or%20((Tags%20ne%20null)%20and%20substringof('%20git%20',tolower(Tags))))%20and%20IsLatestVersion&\$orderby=Id&\$skip=0&\$top=30&semVerLevel=2.0.0" \
"//*[local-name()='feed']"

check_xml "substring Id search" \
"$BASE/Packages?\$filter=substringof('git',tolower(Id))" \
"//*[local-name()='feed']"

check_xml "substring Description search" \
"$BASE/Packages?\$filter=Description%20ne%20null%20and%20substringof('git',tolower(Description))" \
"//*[local-name()='feed']"

check_xml "substring Tags search" \
"$BASE/Packages?\$filter=Tags%20ne%20null%20and%20substringof('%20git%20',tolower(Tags))" \
"//*[local-name()='feed']"

check_xml "OR filter search" \
"$BASE/Packages?\$filter=(substringof('git',tolower(Id))%20or%20substringof('git',tolower(Description)))" \
"//*[local-name()='feed']"

# -------------------- REST --------------------

hdr "SEARCH"

check_http "Search" "$BASE/Search()?searchTerm='$PKG'"
check_xml "Search feed" "$BASE/Search()?searchTerm='$PKG'" "//*[local-name()='feed']"

hdr "FIND"

check_http "FindPackagesById" "$BASE/FindPackagesById()?id='$PKG'"
check_xml "FindPackagesById entry" "$BASE/FindPackagesById()?id='$PKG'" "//*[local-name()='entry']"

hdr "SINGLE ENTITY"

check_http "entity" "$BASE/Packages(Id='$PKG',Version='$VER')"
check_xml "entity entry" "$BASE/Packages(Id='$PKG',Version='$VER')" "//*[local-name()='entry']"
check_xml_val "entity id match" \
  "$BASE/Packages(Id='$PKG',Version='$VER')" \
  "string(//*[local-name()='Id'][1])" "$PKG"

hdr "COUNT"

check_int "Packages/\$count" "$BASE/Packages/\$count" 0
check_int "Search/\$count" "$BASE/Search()/\$count" 0

hdr "DOWNLOAD"

check_http "nupkg endpoint" "$BASE/package/$PKG/$VER"
check_zip "valid zip" "$BASE/package/$PKG/$VER"

hdr "NEGATIVE"

check_http "missing entity 404" "$BASE/Packages(Id='__nope__',Version='0.0.0')" 404
check_xml_empty "missing filter empty" \
  "$BASE/Packages?\$filter=Id%20eq%20'__nope__'" \
  "//*[local-name()='entry']"

hdr "GetUpdates"

check_xml "update exists" \
  "$BASE/GetUpdates()?packageIds=$PKG&versions=0.0.0&includePrerelease=false&includeAllVersions=false" \
  "//*[local-name()='Id']"

check_xml_empty "same version no updates" \
  "$BASE/GetUpdates()?packageIds=$PKG&versions=$VER&includePrerelease=false&includeAllVersions=false" \
  "//*[local-name()='entry']"

hdr "v3"

check_http "v3 index" "$BASE/v3/index.json"

if command -v jq >/dev/null 2>&1; then
  check_http "v3 query" "$BASE/v3/query?q=${PKG,,}"
fi

hdr "CLI"

if [[ -x bin/nuget ]]; then
  bin/nuget list "$PKG" -Source "$BASE" >/dev/null 2>&1 && ok "nuget list" || fail "nuget list"
else
  skip "nuget CLI" "missing"
fi

hdr "RESULTS"

printf "PASS=%d FAIL=%d SKIP=%d\n" "$PASS" "$FAIL" "$SKIP"

(( FAIL == 0 )) && exit 0 || exit 1