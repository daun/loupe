<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\MatchingStrategy;

use Doctrine\DBAL\Query\QueryBuilder;
use Loupe\Loupe\Internal\Search\Searcher;

final class All extends MatchingStrategy
{
    public function apply(
        Searcher $searcher,
        QueryBuilder $qb,
        array $positiveConditions,
        array $negativeConditions
    ): void {
        $this->applyPositiveConditions($qb, $positiveConditions, ' AND ');
        $this->applyNegativeConditions($qb, $negativeConditions);
    }

    public static function getName(): string
    {
        return 'all';
    }
}
