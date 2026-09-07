#!/usr/bin/env bash
# Browse projection benchmark. Emits METRIC lines for run_experiment.
set -euo pipefail
cd "$(dirname "$0")/.."

OUT=$(vendor/bin/phpbench run --report=aggregate --group=browse --progress=none 2>&1)
echo "$OUT"

echo "$OUT" | awk -F'|' '
/benchBrowseLargeSubset/        { print "large_subset", $8 }
/benchBrowseLargeWholeDocument/ { print "large_whole", $8 }
/benchBrowsePrimaryKey/         { print "pk", $8 }
/benchBrowseSubset  *\|/        { print "subset", $8 }
/benchBrowseSubsetFiltered/     { print "filtered", $8 }
/benchBrowseWholeDocument  *\|/ { print "whole", $8 }
' | while read -r name val; do
  v=$(echo "$val" | tr -d ' ,' | sed 's/ms$//')
  echo "METRIC ${name}_ms=${v}"
  echo "$v"
done | awk '/^METRIC/ {print; next} {s+=$1} END {printf "METRIC browse_total_ms=%.2f\n", s}'
