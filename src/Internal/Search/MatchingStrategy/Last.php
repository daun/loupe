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
        $droppableIndices = [];
        foreach ($positiveConditions as $i => $condition) {
            if ($condition['droppable']) {
                $droppableIndices[] = $i;
            }
        }

        $M = \count($droppableIndices);

        if ($M === 0) {
            // No droppables — behaves like `all`
            return $positiveConditions;
        }

        $threshold = $searcher->getQueryParameters()->getLimit();

        $chosenK = 0;
        for ($k = $M; $k >= 1; $k--) {
            $keepSet = array_flip(\array_slice($droppableIndices, 0, $k));
            $probeStatements = [];
            foreach ($positiveConditions as $i => $condition) {
                if (!$condition['droppable'] || isset($keepSet[$i])) {
                    $probeStatements[] = $condition['statements'];
                }
            }

            $count = $this->probeDocumentCount($searcher, $probeStatements, $negativeConditions);
            if ($count >= $threshold) {
                $chosenK = $k;
                break;
            }
        }

        $keepSet = array_flip(\array_slice($droppableIndices, 0, $chosenK));
        $result = [];
        foreach ($positiveConditions as $i => $condition) {
            if (!$condition['droppable'] || isset($keepSet[$i])) {
                $result[] = $condition;
            }
        }

        return $result;
    }

    /**
     * Check potential number of results from existing term-document CTEs with the given positive/negative
     * condition groups combined with AND, in order to decide how many trailing query terms to drop.
     *
     * @param list<list<?string>> $positiveConditions
     * @param list<list<?string>> $negativeConditions
     */
    private function probeDocumentCount(Searcher $searcher, array $positiveConditions, array $negativeConditions): int
    {
        $engine = $searcher->getEngine();
        $documentsAlias = $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);

        $innerQb = $engine->getConnection()->createQueryBuilder()
            ->select($documentsAlias . '._id AS document_id')
            ->from(IndexInfo::TABLE_NAME_DOCUMENTS, $documentsAlias);

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
