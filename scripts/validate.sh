#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "== PHP Syntax (php -l) =="
tmp_out="$(mktemp)"
trap 'rm -f "$tmp_out"' EXIT

find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l >"$tmp_out" || true
if rg -n "(Errors parsing|Parse error|Fatal error)" "$tmp_out"; then
  echo "FAIL: PHP lint errors found" >&2
  exit 1
fi
echo "OK: PHP lint"

echo
echo "== Sanity Scans =="
query_count="$(rg -n -e '->query\\s*\\(' --glob '*.php' --glob '!vendor/**' . 2>/dev/null | wc -l || true)"
strict_missing="$(rg --files-without-match "declare\\(strict_types=1\\)" --glob '*.php' --glob '!vendor/**' . 2>/dev/null | wc -l || true)"
list_remaining="$(rg -n "\\blist\\s*\\(" --glob '*.php' --glob '!vendor/**' . 2>/dev/null | wc -l || true)"
at_suppression="$(rg -n "@(file_put_contents|mkdir|filemtime|touch|unlink|fopen|copy|rename|chmod|chown)" --glob '*.php' --glob '!vendor/**' . 2>/dev/null | wc -l || true)"

echo "->query() count:                 $query_count"
echo "declare(strict_types=1) missing: $strict_missing"
echo "list() remaining:                $list_remaining"
echo "@ suppression (file ops):        $at_suppression"

if [[ "$query_count" != "0" ]]; then
  echo "FAIL: Remaining ->query() usage detected" >&2
  exit 1
fi
if [[ "$strict_missing" != "0" ]]; then
  echo "FAIL: Some PHP files are missing strict_types" >&2
  exit 1
fi
if [[ "$list_remaining" != "0" ]]; then
  echo "FAIL: Remaining list() usage detected" >&2
  exit 1
fi
if [[ "$at_suppression" != "0" ]]; then
  echo "FAIL: Remaining @ suppression detected" >&2
  exit 1
fi

echo "OK: sanity scans"

echo
echo "== Rector (dry-run) =="
if [[ -f "vendor/bin/rector" ]]; then
  if [[ "${SKIP_RECTOR:-0}" == "1" ]]; then
    echo "SKIP: Rector (set SKIP_RECTOR=0 to enable)"
  else
    php vendor/bin/rector process --dry-run
    echo "OK: Rector dry-run"
  fi
else
  echo "SKIP: vendor/bin/rector not found"
fi
