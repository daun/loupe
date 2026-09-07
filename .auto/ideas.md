# Browse performance ideas

## Measured, awaiting a decision

### 1. Covering index on `documents(_id)` + seek pagination (BIG)
`ORDER BY _id ... LIMIT n OFFSET m` walks the table B-tree, and with a large `_document`
payload SQLite reads roughly one page per skipped row. That walk, not decoding, is the
dominant browse cost.

Measured on the 20 000 x 4 KB article corpus, full traversal in 1 000-doc pages:

| variant | time |
|---|---|
| OFFSET, whole document + decode (today) | 419.89 ms |
| boundary subquery `SELECT _id ... LIMIT 1 OFFSET n`, no index | 369.93 ms |
| boundary subquery, with `INDEX(_id)` | 2.21 ms |
| seek `WHERE _id >= boundary`, whole document + decode | 185.89 ms |
| seek + `json_extract` projection | 78.85 ms |

31 944 x 510 B movies corpus: 117.63 ms -> 80.44 ms (-32%).

Index costs 26 of 157 897 pages (0.02%) and 31 ms to build over 20 000 rows.
Cost: a schema change, so `Engine::VERSION` has to be bumped and every existing index
needs a reindex. Also one more index to maintain on every write (unmeasured).

Without a new index, `INDEXED BY <unique index on _user_id>` forces a covering scan plus
a temp-B-tree sort: 234 ms -> 107 ms. Better than nothing but relies on the DBAL-generated
index name, so it is fragile.

### 2. Generic subset projection with `json_extract`
Consistent ~20% off browse projections on both corpora, but only the multipath form is fast,
and it changes two observable behaviours.

| variant | 4 KB docs | 510 B docs | keeps key order | tells absent from null |
|---|---|---|---|---|
| whole document (today) | 0% | 0% | yes | yes |
| multipath `json_extract` | -20% | -21% | no | no |
| `json_group_object` over `json_each` | -17% | +38% | yes | yes |
| multipath + `json_group_array` key list | -12% | +63% | yes | yes |

Only the multipath form wins on both corpora. It returns values in requested (sorted) order
rather than document order (62 functional tests assert document order) and cannot tell an
absent attribute from a null one (`testSortWithNullAndNonExistingValue` asserts the difference).

## Rejected

- `json_group_object(key, value)` over `json_each` without a type-restoring CASE: turns `true`
  into `1` and nested objects into strings.
- `json_array(json_extract(...))` for single-attribute projections: same boolean corruption.
- Storing `_document` as JSONB: an unindexed CTAS probe was 5x slower; needs a fair retest
  against a real indexed table before drawing any conclusion.
- Streaming `Result::iterateColumn` instead of `fetchFirstColumn` for bounded pages (measured
  in an earlier session: +6%).

## Not yet explored

- `PRAGMA mmap_size` for the read path on large corpora.
- Caching `countDocuments()` per snapshot instead of one count query per browse page.
