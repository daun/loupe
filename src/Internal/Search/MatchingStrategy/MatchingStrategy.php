<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\MatchingStrategy;

use Doctrine\DBAL\Query\QueryBuilder;
use Loupe\Loupe\Internal\Search\Searcher;

abstract class MatchingStrategy
{
    /**
     * Apply the matching strategy to the query builder by creating clauses for
     * both the positive and negative conditions found in the query tokens.
     *
     * @param list<array{statements: list<?string>, droppable: bool}> $positiveConditions
     * @param list<list<?string>>                                     $negativeConditions
     */
    abstract public function apply(
        Searcher $searcher,
        QueryBuilder $qb,
        array $positiveConditions,
        array $negativeConditions
    ): void;

    public static function fromName(string $name): ?self
    {
        $map = self::strategies();

        return ($map[$name] ?? null)
            ? new $map[$name]()
            : null;
    }

    abstract public static function getName(): string;

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::strategies());
    }

    /**
     * @param list<list<?string>> $negativeConditions
     */
    protected function applyNegativeConditions(QueryBuilder $qb, array $negativeConditions): void
    {
        $whereNot = implode(' AND ', array_map(
            fn ($statements) => '(' . implode(' AND ', $statements) . ')',
            $negativeConditions
        ));

        if ($whereNot !== '') {
            $qb->andWhere('(' . $whereNot . ')');
        }
    }

    /**
     * @param list<array{statements: list<?string>, droppable: bool}> $positiveConditions
     */
    protected function applyPositiveConditions(QueryBuilder $qb, array $positiveConditions, string $operator): void
    {
        $where = implode($operator, array_map(
            fn ($condition) => '(' . implode(' AND ', $condition['statements']) . ')',
            $positiveConditions
        ));

        if ($where !== '') {
            $qb->andWhere('(' . $where . ')');
        }
    }

    /**
     * @return array<string, class-string<self>>
     */
    private static function strategies(): array
    {
        return [
            All::getName() => All::class,
            Any::getName() => Any::class,
        ];
    }
}
