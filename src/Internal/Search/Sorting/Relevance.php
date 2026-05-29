<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Search\Cte;
use Loupe\Loupe\Internal\Search\Ranking\AttributeWeight;
use Loupe\Loupe\Internal\Search\Ranking\Exactness;
use Loupe\Loupe\Internal\Search\Ranking\Proximity;
use Loupe\Loupe\Internal\Search\Ranking\RankingInfo;
use Loupe\Loupe\Internal\Search\Ranking\TypoCount;
use Loupe\Loupe\Internal\Search\Ranking\WordCount;
use Loupe\Loupe\Internal\Search\Searcher;
use Loupe\Loupe\SearchParameters;

class Relevance extends AbstractSorter
{
    public const RANKERS = [
        'words' => WordCount::class,
        'typo' => TypoCount::class,
        'proximity' => Proximity::class,
        'attribute' => AttributeWeight::class,
        'exactness' => Exactness::class,
    ];

    private const CTE_NAME = 'relevances_per_document';

    public function __construct(
        private Direction $direction
    ) {
    }

    public function apply(Searcher $searcher, Engine $engine): void
    {
        $queryParameters = $searcher->getQueryParameters();

        $tokens = $searcher->getTokens()->all();
        if (!\count($tokens) || !$queryParameters instanceof SearchParameters) {
            return;
        }

        $ctes = [];
        $relevances = [];
        $needsFoldingState = \in_array('exactness', $engine->getConfiguration()->getRankingRules(), true);
        $missingPlaceholder = $needsFoldingState ? '0|0' : '0';

        foreach ($tokens as $token) {
            $cteName = $searcher->getCTENameForToken(Searcher::CTE_TERM_DOCUMENT_MATCHES_PREFIX, $token);

            // Could be that the token is not being searched for, as it might be a stop word
            if (!$searcher->hasCTE($cteName)) {
                continue;
            }

            $termRelevanceCTE = $searcher->getCTENameForToken(self::CTE_NAME . '_term_', $token);

            // Aggregate from _cte_term_document_matches_N directly and let the outer merge COALESCE the missing ones to the placeholder string
            $qb = $engine->getConnection()->createQueryBuilder();
            $qb->select('dm.document AS document_id')->from($cteName, 'dm')->groupBy('dm.document');

            if ($needsFoldingState) {
                $qb->addSelect("group_concat(dm.position || ':' || dm.attribute || ':' || dm.typos) || '|' || MAX(dm.exact_match) AS relevance");
            } else {
                $qb->addSelect("group_concat(dm.position || ':' || dm.attribute || ':' || dm.typos) AS relevance");
            }

            $searcher->addCTE(new Cte($termRelevanceCTE, ['document_id', 'relevance_per_term'], $qb));

            $ctes[] = $termRelevanceCTE;
            $relevances[] = \sprintf("COALESCE(%s.relevance_per_term, '%s')", $termRelevanceCTE, $missingPlaceholder);
        }

        if ($ctes === []) {
            return;
        }

        // CTE for all documents
        $qb = $engine->getConnection()->createQueryBuilder();
        $qb
            ->addSelect(Searcher::CTE_MATCHES . '.document_id AS document')
            ->addSelect(implode(" || ';' || ", $relevances) . ' AS relevance_per_term')
            ->from(Searcher::CTE_MATCHES)
        ;

        foreach ($ctes as $cte) {
            $qb->leftJoin(Searcher::CTE_MATCHES, $cte, $cte, \sprintf('%s.document_id = %s.document_id', $cte, Searcher::CTE_MATCHES));
        }

        $searcher->addCTE(new Cte(self::CTE_NAME, ['document_id', 'relevance_per_term'], $qb));

        // Join the CTE
        $searcher->getQueryBuilder()->join(
            Searcher::CTE_MATCHES,
            self::CTE_NAME,
            self::CTE_NAME,
            \sprintf('%s.document_id = %s.document_id', self::CTE_NAME, Searcher::CTE_MATCHES)
        );

        // Searchable attributes to determine attribute weight
        $searchableAttributes = $engine->getConfiguration()->getSearchableAttributes();

        $select = \sprintf(
            "loupe_relevance('%s', '%s', %s) AS %s",
            implode(':', $searchableAttributes),
            implode(':', $engine->getConfiguration()->getRankingRules()),
            self::CTE_NAME . '.relevance_per_term',
            Searcher::RELEVANCE_ALIAS,
        );

        $searcher->getQueryBuilder()->addSelect($select);

        // No need to use the abstract addOrderBy() here because the relevance alias cannot be of
        // our internal null or empty value
        $searcher->addOrderBy(Searcher::RELEVANCE_ALIAS, $this->direction->getSQL());

        // Apply threshold
        $threshold = $queryParameters->getRankingScoreThreshold();
        if ($threshold > 0) {
            $searcher->getQueryBuilder()->andWhere(Searcher::RELEVANCE_ALIAS . '>= ' . $threshold);
        }
    }

    /**
     * Example: A string with "3:title,8:title,10:title;0;4:summary" would read as follows:
     * - The query consisted of 3 tokens (terms).
     * - The first term matched. At positions 3, 8 and 10 in the `title` attribute.
     * - The second term did not match (position 0).
     * - The third term matched. At position 4 in the `summary` attribute.
     *
     * @param string $termPositions A string of ";" separated per term and "," separated for all the term positions within a document
     */
    public static function fromQuery(string $searchableAttributes, string $rankingRules, string $termPositions): float
    {
        $rankingInfo = RankingInfo::fromQueryFunction($searchableAttributes, $rankingRules, $termPositions);
        $rankers = static::getRankers($rankingInfo->getRankingRules());

        $weights = [];
        $totalWeight = 0;
        foreach ($rankers as [$class, $weight]) {
            $weights[] = $class::calculate($rankingInfo) * $weight;
            $totalWeight += $weight;
        }

        return array_sum($weights) / $totalWeight;
    }

    public static function fromString(string $value, Engine $engine, Direction $direction): AbstractSorter
    {
        return new self($direction);
    }

    public static function supports(string $value, Engine $engine): bool
    {
        return $value === Searcher::RELEVANCE_ALIAS;
    }

    /**
     * @param array<string> $rules
     * @return array<array{string, float}>
     */
    protected static function getRankers(array $rules): array
    {
        return array_map(
            function ($rule, $index) {
                $class = self::RANKERS[$rule];
                $weight = Configuration::RANKING_RULES_ORDER_FACTOR ** $index;
                return [$class, $weight];
            },
            $rules,
            range(0, \count($rules) - 1)
        );
    }
}
