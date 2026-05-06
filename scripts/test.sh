#!/usr/bin/env bash
set -e
echo "== NUGET TEST SUITE (CURL + NUGET NO CONFIG) =="

BASE="http://localhost:8080"

# 1. root service doc
echo -n "[root] "
curl -s -o /dev/null -w "%{http_code}\n" "$BASE/"

# 2. packages feed exists
echo -n "[feed] "
curl -s "$BASE/Packages" | grep -q "<feed" && echo "OK" || echo "FAIL"

# 3. count present
echo -n "[count] "
curl -s "$BASE/Packages" | grep -q "<m:count>" && echo "OK" || echo "FAIL"

# 4. git exact match
echo -n "[git exact] "
curl -s "$BASE/Packages?\$filter=Id%20eq%20'git.install'" | grep -q "git.install" && echo "OK" || echo "FAIL"

# 5. git fuzzy match
echo -n "[git fuzzy] "
curl -s "$BASE/Packages?\$filter=substringof('git',tolower(Id))" | grep -q "git.install" && echo "OK" || echo "FAIL"

# 6. 7zip exact match
echo -n "[7zip] "
curl -s "$BASE/Packages?\$filter=Id%20eq%20'7zip.install'" | grep -q "7zip.install" && echo "OK" || echo "FAIL"

# 7. FindPackagesById
echo -n "[find id] "
curl -s "$BASE/FindPackagesById()?id='git.install'" | grep -q "git.install" && echo "OK" || echo "FAIL"

# 8. download git headers
echo -n "[dl git] "
curl -s -I "$BASE/package/git.install/2.54.0" | grep -q "200 OK" && echo "OK" || echo "FAIL"

# 9. download 7zip headers
echo -n "[dl 7zip] "
curl -s -I "$BASE/package/7zip.install/26.0.0" | grep -q "200 OK" && echo "OK" || echo "FAIL"

# 10. missing package safety
echo -n "[missing] "
curl -s "$BASE/Packages?\$filter=Id%20eq%20'doesnotexist'" | grep -q "<feed" && echo "OK" || echo "FAIL"

# 11. metadata endpoint sanity
echo -n "[metadata] "
curl -s "$BASE/" | grep -q "<collection href=\"Packages\"" && echo "OK" || echo "FAIL"

# ─────────────────────────────
# 12. NUGET CLI TEST (NO CONFIG)
# ─────────────────────────────

echo "[nuget search git]"
./bin/nuget search git -Source "$BASE/Packages" -NonInteractive 2>/dev/null | grep -q "git.install" && echo "OK" || echo "FAIL"

echo "[nuget search 7zip]"
./bin/nuget search 7zip -Source "$BASE/Packages" -NonInteractive 2>/dev/null | grep -q "7zip.install" && echo "OK" || echo "FAIL"

echo "== DONE =="