#!/usr/bin/env bash
# Runs the index benchmark group REPS times and reports the per-subject median (ms).
# Used to measure the write cost of adding an index, not as the session's primary metric.
set -euo pipefail
cd "$(dirname "$0")/.."

REPS=${REPS:-3}

for i in $(seq 1 "$REPS"); do
  vendor/bin/phpbench run --report=aggregate --group=index --progress=none 2>/dev/null \
    | awk -F'|' '/ms/ && /bench(Add|Update)Documents/ {
        gsub(/^[ \t]+|[ \t]+$/, "", $3); gsub(/^[ \t]+|[ \t]+$/, "", $4);
        v = $8; gsub(/[ ,]/, "", v); sub(/ms$/, "", v);
        print $3 "/" $4, v
      }'
done | awk '
  { if (!($1 in vals)) order[++o] = $1; vals[$1] = vals[$1] " " $2 }
  END {
    total = 0
    n = o
    for (k = 1; k <= n; k++) {
      key = order[k]
      c = split(vals[key], a, " ")
      # a[] is 1..c after split of a leading-space string; sort numerically
      for (i = 1; i < c; i++) for (j = i + 1; j <= c; j++) if (a[i] + 0 > a[j] + 0) { t = a[i]; a[i] = a[j]; a[j] = t }
      med = (c % 2) ? a[int(c / 2) + 1] : (a[c / 2] + a[c / 2 + 1]) / 2
      printf "%-40s %10.2f ms   (%s )\n", key, med, vals[key]
      total += med
    }
    printf "%-40s %10.2f ms\n", "TOTAL (sum of medians)", total
  }
'
