<?php

declare(strict_types=1);

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\Search\Searcher;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\SearchResult;
use Loupe\Loupe\Tests\Benchmark\AbstractBench;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Determines Searcher::FACETS_FROM_MATCHES_MAX_TOTAL_HITS: the largest maxTotalHits at which driving multi-value
// facets from the match set is never slower than the planner-chosen join order, for any query, in every round.
// Last run: 2026-10-15, movies fixture, PHP 8.4.20, SQLite 3.53.0, 2 rounds, tolerance 2%. Selective order won up to
// 3000 (worst -0%), lost from 4000 on (+3%, +5%, +10%, +10%, +13% at 4000/5000/7500/10000/20000). Recommended: 3000.

$sweep = [500, 1000, 2000, 3000, 4000, 5000, 7500, 10000, 20000];
$queries = ['Anakin Skywalker', 'star wars', 'love', 'man', 'the', 'a'];
$rounds = 2;
$iterations = 9;
$tolerance = 0.02;

$bench = new class() extends AbstractBench {
    public static function indexPath(): string
    {
        self::ensureSearchIndex();

        return self::searchIndexPath();
    }

    public static function config(): Configuration
    {
        return self::configuration();
    }
};

$search = static function (Engine $engine, Parser $parser, SearchParameters $parameters, bool $fromMatches): SearchResult {
    $searcher = new class($engine, $parser, $parameters, $fromMatches) extends Searcher {
        public function __construct(
            Engine $engine,
            Parser $parser,
            SearchParameters $parameters,
            private readonly bool $fromMatches,
        ) {
            parent::__construct($engine, $parser, $parameters);
        }

        protected function shouldDriveFacetsFromMatches(): bool
        {
            return $this->fromMatches;
        }
    };

    $result = $searcher->fetchResult();
    assert($result instanceof SearchResult);

    return $result;
};

$median = static function (Engine $engine, Parser $parser, SearchParameters $parameters, bool $fromMatches) use ($search, $iterations): float {
    $timings = [];

    for ($i = 0; $i <= $iterations; ++$i) {
        $start = hrtime(true);
        $search($engine, $parser, $parameters, $fromMatches);
        $timings[] = (hrtime(true) - $start) / 1e6;
    }

    sort($timings);

    return $timings[intdiv($iterations, 2) + 1]; // First run is the warm-up
};

$createEngine = static fn (int $maxTotalHits): Engine => (new ReflectionProperty(Loupe::class, 'engine'))
    ->getValue((new LoupeFactory())->create($bench::indexPath(), $bench::config()->withMaxTotalHits($maxTotalHits)))
;

$best = array_fill_keys($sweep, INF);
$worst = array_fill_keys($sweep, -INF);
$passes = array_fill_keys($sweep, true);

printf("Current constant: %d\n\n", Searcher::FACETS_FROM_MATCHES_MAX_TOTAL_HITS);

for ($round = 1; $round <= $rounds; ++$round) {
    foreach ($sweep as $maxTotalHits) {
        $engine = $createEngine($maxTotalHits);
        $parser = new Parser($engine);
        $roundWorst = -INF;

        foreach ($queries as $query) {
            $parameters = SearchParameters::create()->withQuery($query)->withFacets(['genres']);
            $hits = $search($engine, $parser, $parameters, false)->getTotalHits();
            $planner = $median($engine, $parser, $parameters, false);
            $fromMatches = $median($engine, $parser, $parameters, true);
            $delta = $fromMatches / $planner - 1;

            $roundWorst = max($roundWorst, $delta);
            $best[$maxTotalHits] = min($best[$maxTotalHits], $delta);
            $worst[$maxTotalHits] = max($worst[$maxTotalHits], $delta);

            printf("round %d  maxTotalHits %-6d %-18s %6d hits  planner %8.2f ms  from matches %8.2f ms  %+4.0f%%\n", $round, $maxTotalHits, $query, $hits, $planner, $fromMatches, $delta * 100);
        }

        $passes[$maxTotalHits] = $passes[$maxTotalHits] && $roundWorst <= $tolerance;
    }
}

printf("\n%s, movies fixture, PHP %s, SQLite %s, %d rounds, tolerance %.0f%%\n", date('Y-m-d'), PHP_VERSION, $createEngine(1000)->getConnection()->fetchOne('SELECT sqlite_version()'), $rounds, $tolerance * 100);

foreach ($sweep as $maxTotalHits) {
    printf("maxTotalHits %-6d best %+4.0f%%  worst %+4.0f%%  %s\n", $maxTotalHits, $best[$maxTotalHits] * 100, $worst[$maxTotalHits] * 100, $passes[$maxTotalHits] ? 'wins' : 'loses');
}

$recommended = null;

foreach ($sweep as $maxTotalHits) {
    if (!$passes[$maxTotalHits]) {
        break;
    }

    $recommended = $maxTotalHits;
}

printf("\nRecommended FACETS_FROM_MATCHES_MAX_TOTAL_HITS: %s\n", $recommended ?? 'none, from matches always loses');
