# Implementation plan: term aliases (`feat/term-aliases`)

Status: researched and decided — ready for implementation. All design questions below were
settled with the maintainer; do not re-litigate them, but flag anything that contradicts
what you find in the code.

## Feature summary

Add a Meilisearch-style `aliases` setting to `Configuration`. Semantics are **query-side
and one-way**, matching Meilisearch's `synonyms` setting:

```php
$configuration = Configuration::create()
    ->withAliases([
        // one-way: searching "jacket" also finds docs containing "parka"/"windbreaker",
        // but searching "parka" does NOT find generic jackets
        'jacket' => ['parka', 'windbreaker'],

        // two-way is expressed by listing both directions explicitly (Meilisearch parity)
        'couch' => ['sofa'],
        'sofa' => ['couch'],
    ]);
```

Although the config *reads* query-side, resolution happens entirely at **index time**:
the map is inverted into a `documentTerm => [searchTerms]` lookup, and when a document
token (or one of its variants) hits an entry, the search terms are added to the token as
variants via the existing `Token::withAddedVariants()` mechanism — exactly like stemming
does today. No query-time expansion. No changes to the `loupe/matcher` package.

### Decided constraints

- **Single-word only.** Every key and every alias value must tokenize to a single term.
  Reject multi-word entries with `InvalidConfigurationException`. Multi-word phrases are
  explicitly out of scope for v1.
- **Aliases match surface form + existing variants.** The lookup is checked against
  `$token->allTerms()` — i.e. the normalized term *and* already-computed variants (stems,
  decompound parts). No recursive chaining (an alias added by this step is not itself
  looked up again).
- **Not gated on typo tolerance.** Stemming is currently only applied when typo tolerance
  is enabled (`src/Internal/Tokenizer/Tokenizer.php`, `tokenizeWithVariants()`). Aliases
  must be applied unconditionally — otherwise search and highlighting break when typo
  tolerance is disabled.
- **Normalization:** alias keys and values are lowercased + de-unicoded (Meilisearch
  parity) using `Loupe\Matcher\Tokenizer\Normalizer\Normalizer` when the lookup is built,
  so `'TV' => ['Télévision']` behaves like `'tv' => ['television']`.
- **Changing aliases requires a reindex.** Include the setting in
  `Configuration::getIndexHash()` so `needsReindex()` reports it automatically.

## Why this works without touching the matcher / searcher (context for the implementer)

Verified end-to-end during research; no action needed, but useful to understand:

- `src/Internal/Index/Indexer.php` (`prepareDocument`, ~line 830): every token variant is
  indexed as its own row in the `terms` table, at the **same position** as the parent
  token, with `folded=true` in `terms_documents`, and with the parent token's original
  start/end character offsets.
- **Typo tolerance** works on aliases for free: variant terms enter the state set index
  like any other term (`bulkInsertTerms`).
- **Ranking**: the term-matches CTE computes `typos = levenshtein(matched_term, query_term)`
  — an alias match is the literal query term, so 0 typos. `exactness` already demotes
  variant matches via the `folded` flag (`src/Internal/Search/Searcher.php:636`), so a
  document literally containing "tv" outranks one matching via the "television" alias.
  Shared positions keep `words`/`proximity` correct.
- **Prefix search**: variants are excluded from the typo-tolerant prefix tables
  (`Indexer.php:500`) but reachable via the plain `GLOB` path — identical to stems today.
- **Highlighting**, both paths:
  - Position-info path (single string attributes): stored offsets point at the original
    word, `Formatter::format()` uses explicit matches as-is. Works via the DB.
  - Re-derivation path (array attributes): `Matcher::calculateMatches()` tokenizes the
    document text through Loupe's internal tokenizer *with variants* (the `Formatter` is
    built on it, `src/Internal/Engine.php:98`), so the doc token carries the alias variant
    and matches the query term. This is why the expansion MUST live in
    `tokenizeWithVariants()` (shared by indexing and highlight-time re-tokenization) and
    not in `tokenizeDocument()`.
- **Phrase queries will match aliases** (the phrase CTE's exact-term lookup doesn't filter
  `folded=1`). This is pre-existing behavior for stems; aliases inherit it. Accepted —
  cover with a documenting test, don't "fix".

## Changes

### 1. `src/Configuration.php`

- New property `private array $aliases = [];` — shape `array<string, array<string>>`,
  mapping search term => list of document terms it should additionally match.
- `withAliases(array $aliases): self` (clone-and-set like the other setters):
  - Validate: keys are non-empty strings; values are non-empty lists of non-empty
    strings; no key or value may contain whitespace (`preg_match('/\s/u', ...)`) —
    single-word rule. Throw `InvalidConfigurationException` with a message naming the
    offending entry. Add a static factory on the exception following the existing
    `becauseInvalidAttributeName()` style (e.g. `becauseInvalidAlias(string $value)`).
  - Sort deterministically for stable hashing/serialization: `ksort()` the map and
    `sort()` each value list (mirrors `withStopWords()`).
  - Store as given otherwise — do NOT normalize here (keeps `toArray()`/`fromArray()`
    round-trips faithful; normalization happens when the tokenizer builds its lookup).
- `getAliases(): array` getter.
- `fromArray()`: handle an `aliases` key; `toArray()`: emit it. Update the array-shape
  phpdoc blocks on `fromArray()` and `toArray()` accordingly.
- `getIndexHash()`: add `$hash[] = json_encode($this->getAliases());`.

### 2. `src/Internal/Tokenizer/Tokenizer.php`

- Add a lazily-built inverted lookup:

  ```php
  /**
   * Inverted alias map: normalized document term => list of normalized search terms to index as variants.
   *
   * @var array<string, list<string>>|null
   */
  private array|null $aliasLookup = null;

  private function getAliasLookup(): array
  {
      if (null !== $this->aliasLookup) {
          return $this->aliasLookup;
      }
      // For each configured `searchTerm => [docTerms]`:
      //   foreach docTerms as docTerm: lookup[normalize(docTerm)][] = normalize(searchTerm)
      // Normalize with Loupe\Matcher\Tokenizer\Normalizer\Normalizer + the term is already
      // lowercased by normalize(). Dedupe values per key (array_unique).
  }
  ```

- In `tokenizeWithVariants()`: currently each token gets stem variants and is added to the
  collection. Restructure the loop body to:

  ```php
  $token = $token->withAddedVariants($stemVariants);   // existing logic, unchanged gating

  $aliasLookup = $this->getAliasLookup();
  if ([] !== $aliasLookup) {
      $aliasVariants = [];
      foreach ($token->allTerms() as $term) {          // surface + stem + decompound parts
          foreach ($aliasLookup[$term] ?? [] as $searchTerm) {
              $aliasVariants[] = $searchTerm;
          }
      }
      $token = $token->withAddedVariants($aliasVariants); // withAddedVariants dedupes
  }

  $tokenCollectionWithVariants->add($token);
  ```

- Apply aliases to **all** document tokens, including ones flagged `isPartOfPhrase()`
  (quotation marks inside document text are just punctuation; only stemming keeps its
  phrase guard). Do NOT copy the `TypoTolerance::isDisabled()` guard.
- `tokenizeQuery()` / `tokenizeWithoutVariants()` are untouched — queries never get alias
  variants (this is what keeps typo counting and exactness correct).

Note / known limitation to record in a code comment or docs: alias entries are normalized
with the *default* normalizer. Locale-specific normalizers (e.g. German `GermanNormalizer`)
may fold document terms differently (umlauts), in which case a non-ASCII alias entry may
not line up with the locale-folded document term. Acceptable for v1; recommend users
configure ASCII-folded alias terms.

### 3. Docs: `docs/configuration.md`

New "Aliases" section (place it near stop words / typo tolerance). Cover:

- Semantics: query-side reading, one-way, Meilisearch-compatible; two-way = list both
  directions. Use the `jacket => parka` (one-way) and `couch <=> sofa` (two-way) examples
  with a sentence on *why* one-way is useful (generic term stays broad, specific term
  stays precise).
- Single-word restriction (throws otherwise).
- Normalization (case/diacritics-insensitive).
- Changing aliases changes the index hash → documents must be reindexed.
- Behavior notes: typo tolerance applies to alias matches; literal matches rank above
  alias matches under the `exactness` ranking rule; highlighting highlights the document's
  actual word (e.g. query "jacket" highlights "parka").

## Tests

Run with `composer tests` (or `composer unit-tests` / `composer functional-tests`).
Fix code style with `composer cs-fixer` before committing.

### `tests/Unit/ConfigurationTest.php`

1. `withAliases()`/`getAliases()` round-trip, plus `toArray()`/`fromArray()` round-trip
   including the new key.
2. Deterministic ordering: two configs with the same aliases in different key/value order
   produce identical `toArray()` and identical `getIndexHash()`.
3. `getIndexHash()` data provider (`testGetIndexHash` already exists — add cases):
   differing aliases → differing hashes; same aliases → same hash.
4. Validation failures (expect `InvalidConfigurationException`): multi-word key
   (`'san francisco' => ['sf']`), multi-word value (`'sf' => ['san francisco']`), empty
   string key or value, non-string value inside the list, non-list value.

### `tests/Unit/Internal/Tokenizer/TokenizerTest.php`

Use the existing `createTokenizer(Configuration ...)` helper (mocks `Engine`, real
`NitotmLanguageDetector`); pass `Configuration::create()->withAliases([...])`.

1. **Doc-side expansion**: config `['tv' => ['television']]`; tokenize
   `'my television is broken'` with variants → the `television` token's `getVariants()`
   contains `'tv'` (also assert via `allTermsWithVariants()`).
2. **Direction**: same config; tokenize `'watching tv tonight'` → the `tv` token does NOT
   get a `television` variant.
3. **No aliases on queries**: same config; `tokenizeQuery('television')` → no variants.
4. **Stem chaining** (surface + variants decision): config `['go' => ['run']]`, languages
   `['en']`; tokenize `'running fast'` → token `running` has stem variant `run`, and the
   alias step must then also add `go`. (Chosen because the English stemmer maps
   `running → run`; verify the stem in the test itself rather than assuming.)
5. **No recursive chaining**: config `['a' => ['b'], 'b' => ['c']]`; tokenize text
   containing `'c'` → variant `b` is added, but `a` is NOT.
6. **Typo tolerance disabled**: config with `TypoTolerance::disabled()` and an alias →
   alias variant still present (stem variants absent, per existing behavior).
7. **Normalization**: config `['TV' => ['Télévision']]`; tokenize `'télévision'` → variant
   `tv` present.

### `tests/Functional/AliasTest.php` (new file)

Use `FunctionalTestTrait`, `createLoupe()` in-memory, small inline `addDocuments()`
payloads (2–4 docs with `id` + `title`), `searchAndAssertResults()` like
`tests/Functional/SearchTest.php` does. Scenarios:

1. **One-way match**: config `['phone' => ['iphone']]`; docs `iphone 15 pro` /
   `android phone`; searching `phone` returns both; searching `iphone` returns only the
   iphone doc.
2. **Two-way match**: `['couch' => ['sofa'], 'sofa' => ['couch']]`; either query returns
   both docs.
3. **Typo on the query hits the alias**: config `['television' => ['tv']]`; doc contains
   `tv`; query `televsion` (one typo) still matches, because the indexed variant row is
   the literal term `television` and participates in the state set.
4. **Exactness ranking**: config `['tv' => ['television']]`; docs `television set` and
   `tv set`; query `tv` with `showRankingScore` → both match, the literal `tv set` doc
   ranks first (alias row is `folded=1` → not an exact match).
5. **Highlighting, string attribute** (DB-position path): query `tv`, highlight `title`
   → `<em>television</em> set`.
6. **Highlighting, array attribute** (re-derivation path): document with an array
   attribute containing a string with `television`; query `tv` with that attribute
   highlighted → the word is wrapped. Also assert `_matchesPosition` points at
   `television`'s offsets for the string-attribute case (`showMatchesPosition`).
7. **Typo tolerance disabled end-to-end**: same as (1) but with
   `TypoTolerance::disabled()` — alias matching must still work.
8. **Documenting test — phrase queries match aliases**: config `['television' => ['tv']]`;
   doc `tv set`; phrase query `"television set"` matches. Add a comment stating this is
   inherited variant behavior (same as stems), intentional, and the test exists to detect
   accidental behavior changes.
9. **Reindex detection**: create Loupe with a data dir and config A, index a doc; reopen
   the same data dir with different aliases → `$loupe->needsReindex()` is `true`
   (see existing patterns in `tests/Functional/IndexTest.php`).

### What NOT to test

- Prefix-search-over-aliases edge behavior (inherited, unspecified — same as stems).
- Multi-word aliases (validated away).
- `loupe/matcher` internals (unchanged).

## Suggested commit split

1. `Configuration`: aliases setting + validation + hash + unit tests.
2. Tokenizer expansion + unit tests.
3. Functional tests + docs.
