<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\MatchingStrategy;

use Doctrine\DBAL\Query\QueryBuilder;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\Searcher;

/**
 * Matching strategy: "last"
 * Requires all terms to match, but drops trailing terms until enough results are present.
 * Phrases are never dropped. Enough results = first page of pagination limit.
 */
final class Last extends MatchingStrategy
{
    public function apply(
        Searcher $searcher,
        QueryBuilder $qb,
        array $positiveConditions,
        array $negativeConditions
    ): void {
        $effectivePositiveConditions = $this->dropTrailingConditions($searcher, $positiveConditions, $negativeConditions);

        $this->applyPositiveConditions($qb, $effectivePositiveConditions, ' AND ');
        $this->applyNegativeConditions($qb, $negativeConditions);
    }

    public static function getName(): string
    {
        return 'last';
    }

    /**
     * @param list<array{statements: list<?string>, droppable: bool}> $positiveConditions
     * @param list<list<?string>>                                     $negativeConditions
     *
     * @return list<array{statements: list<?string>, droppable: bool}>
     */
    private function dropTrailingConditions(
        Searcher $searcher,
        array $positiveConditions,
        array $negativeConditions
    ): array {
        $threshold = $searcher->getQueryParameters()->getLimit();

        while (($lastDroppable = $this->lastDroppableIndex($positiveConditions)) !== null) {
            $probeStatements = array_column($positiveConditions, 'statements');

            if ($this->probeDocumentCount($searcher, $probeStatements, $negativeConditions, $threshold) >= $threshold) {
                return $positiveConditions;
            }

            array_splice($positiveConditions, $lastDroppable, 1);
        }

        return $positiveConditions;
    }

    /**
     * The first positive term anchors the query and is never dropped, regardless of whether it is
     * a phrase or a single token. Only droppables at index >= 1 are considered.
     *
     * @param list<array{statements: list<?string>, droppable: bool}> $positiveConditions
     */
    private function lastDroppableIndex(array $positiveConditions): ?int
    {
        for ($i = \count($positiveConditions) - 1; $i >= 1; $i--) {
            if ($positiveConditions[$i]['droppable']) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Check potential number of results from existing term-document CTEs with the given positive/negative
     * condition groups combined with AND, in order to decide how many trailing query terms to drop.
     * Bounded by $threshold — the count short-circuits once enough rows are found.
     *
     * @param list<list<?string>> $positiveConditions
     * @param list<list<?string>> $negativeConditions
     */
    private function probeDocumentCount(Searcher $searcher, array $positiveConditions, array $negativeConditions, int $threshold): int
    {
        $engine = $searcher->getEngine();
        $documentsAlias = $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);

        $innerQb = $engine->getConnection()->createQueryBuilder()
            ->select('1')
            ->from(IndexInfo::TABLE_NAME_DOCUMENTS, $documentsAlias)
            ->setMaxResults($threshold);

        $positiveParts = array_map(
            fn ($statements) => '(' . implode(' AND ', $statements) . ')',
            $positiveConditions
        );

        if ($positiveParts !== []) {
            $innerQb->andWhere('(' . implode(' AND ', $positiveParts) . ')');
        }

        $negativeParts = array_map(
            fn ($statements) => '(' . implode(' AND ', $statements) . ')',
            $negativeConditions
        );

        if ($negativeParts !== []) {
            $innerQb->andWhere('(' . implode(' AND ', $negativeParts) . ')');
        }

        $queryParts = [];

        if ($searcher->getCtesByName() !== []) {
            $queryParts[] = 'WITH';
            foreach ($searcher->getCtesByName() as $name => $cte) {
                $queryParts[] = \sprintf(
                    '%s (%s) AS (%s)',
                    $name,
                    implode(',', $cte->getColumnAliasList()),
                    $cte->getQueryBuilder()->getSQL()
                );
                $queryParts[] = ',';
            }

            array_pop($queryParts);
        }

        $queryParts[] = 'SELECT COUNT(*) FROM (' . $innerQb->getSQL() . ')';

        $outerQb = $searcher->getQueryBuilder();
        $result = $engine->getConnection()->executeQuery(
            implode(' ', $queryParts),
            $outerQb->getParameters(),
            $outerQb->getParameterTypes(),
        );

        return (int) $result->fetchOne();
    }
}
