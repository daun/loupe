<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\MatchingStrategy;

use Doctrine\DBAL\Query\QueryBuilder;
use Loupe\Loupe\Internal\Search\Searcher;

/**
 * Matching strategy: "any"
 * Any one term must match. No terms are dropped.
 */
final class Any extends MatchingStrategy
{
    public function apply(
        Searcher $searcher,
        QueryBuilder $qb,
        array $positiveConditions,
        array $negativeConditions
    ): void {
        $this->applyPositiveConditions($qb, $positiveConditions, ' OR ');
        $this->applyNegativeConditions($qb, $negativeConditions);
    }

    public static function getName(): string
    {
        return 'any';
    }
}
